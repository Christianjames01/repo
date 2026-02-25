<?php

set_time_limit(15);
ini_set('default_socket_timeout', 10);

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Only admins can trigger this
requireLogin();
$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Super Admin', 'Super Administrator'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$notif_id = intval($_POST['notification_id'] ?? 0);
if (!$notif_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
    exit;
}

// Check email config
if (!file_exists(__DIR__ . '/../../config/email.php')) {
    echo json_encode(['success' => false, 'error' => 'Email config not found']);
    exit;
}
require_once __DIR__ . '/../../config/email.php';

// Check IMAP extension
if (!function_exists('imap_open')) {
    echo json_encode([
        'success' => false,
        'error'   => 'PHP IMAP extension is not enabled. Edit php.ini and uncomment: extension=imap, then restart XAMPP.'
    ]);
    exit;
}

// Check table exists
$tbl_check = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
if (!$tbl_check || $tbl_check->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'error'   => 'Table tbl_email_replies does not exist. Please run the SQL migration first.'
    ]);
    exit;
}

// ── IMAP Connect ──────────────────────────────────────────────────────────────
$imap_host = '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX';
$inbox = @imap_open($imap_host, MAIL_USERNAME, MAIL_PASSWORD, 0, 1, [
    'DISABLE_AUTHENTICATOR' => 'GSSAPI'
]);
if (!$inbox) {
    $imap_host = '{imap.gmail.com:993/imap/ssl}INBOX';
    $inbox = @imap_open($imap_host, MAIL_USERNAME, MAIL_PASSWORD, 0, 1);
}

if (!$inbox) {
    echo json_encode(['success' => false, 'error' => 'Could not connect to IMAP: ' . imap_last_error()]);
    exit;
}

// ── Helper functions ──────────────────────────────────────────────────────────
function decodeMime($str) {
    if (empty($str)) return '';
    $parts  = imap_mime_header_decode($str);
    $result = '';
    foreach ($parts as $p) {
        $charset = strtolower($p->charset ?? 'utf-8');
        $text    = $p->text ?? '';
        if ($charset !== 'utf-8' && $charset !== 'default') {
            $text = mb_convert_encoding($text, 'UTF-8', $charset);
        }
        $result .= $text;
    }
    return trim($result);
}

function getBody($inbox, $msg_num) {
    $structure = imap_fetchstructure($inbox, $msg_num);
    $plain = ''; $html = '';

    if (!isset($structure->parts)) {
        $body = imap_body($inbox, $msg_num);
        if ($structure->encoding == 3) $body = base64_decode($body);
        elseif ($structure->encoding == 4) $body = quoted_printable_decode($body);
        return ['plain' => trim($body), 'html' => ''];
    }

    $extractParts = function($parts, $prefix = '') use ($inbox, $msg_num, &$plain, &$html, &$extractParts) {
        foreach ($parts as $i => $part) {
            $num     = $prefix ? $prefix . '.' . ($i + 1) : ($i + 1);
            $subtype = strtolower($part->subtype ?? '');
            $type    = $part->type ?? 0;

            if ($type == 0) {
                $body = imap_fetchbody($inbox, $msg_num, $num);
                if ($part->encoding == 3) $body = base64_decode($body);
                elseif ($part->encoding == 4) $body = quoted_printable_decode($body);

                $charset = 'UTF-8';
                if (!empty($part->parameters)) {
                    foreach ($part->parameters as $param) {
                        if (strtolower($param->attribute) === 'charset') {
                            $charset = strtoupper($param->value);
                        }
                    }
                }
                if ($charset !== 'UTF-8') {
                    $body = mb_convert_encoding($body, 'UTF-8', $charset);
                }

                if ($subtype === 'plain' && empty($plain)) $plain = trim($body);
                elseif ($subtype === 'html'  && empty($html))  $html  = trim($body);
            }

            if (!empty($part->parts)) {
                $extractParts($part->parts, $num);
            }
        }
    };

    $extractParts($structure->parts);
    return ['plain' => $plain, 'html' => $html];
}

function stripQuotedReply($text) {
    $text = preg_replace('/\r\n/', "\n", $text);
    $text = preg_replace('/\n+On .+wrote:\s*\n.*/s', '', $text);
    $text = preg_replace('/\n+-{3,}.*(original|message|reply).*/si', '', $text);
    $lines = explode("\n", $text);
    $clean = [];
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '>') === 0) break;
        $clean[] = $line;
    }
    return trim(implode("\n", $clean));
}

// ── Check if sender is a mail daemon / bounce / auto-reply ───────────────────
function isDaemonOrBounce($from_email, $from_name, $subject) {
    // Patterns that indicate automated/bounce emails — NOT real resident replies
    $daemon_email_patterns = [
        'mailer-daemon',
        'postmaster',
        'mail-daemon',
        'mailerdaemon',
        'no-reply',
        'noreply',
        'do-not-reply',
        'donotreply',
        'bounce',
        'delivery-status',
        'mail+caf_',        // Gmail bounce prefix
        'auto-confirm',
        'auto-reply',
    ];

    $daemon_subject_patterns = [
        'delivery status notification',
        'delivery status notification (failure)',
        'delivery status notification (success)',
        'delivery failure',
        'delivery has failed',
        'undeliverable',
        'undelivered mail',
        'mail delivery failed',
        'mail delivery failure',
        'returned mail',
        'returned to sender',
        'address not found',
        'user unknown',
        'mailbox not found',
        'mailbox unavailable',
        'no such user',
        'recipient address rejected',
        'failed permanently',
        'auto-reply',
        'automatic reply',
        'out of office',
        'autoreply',
        'vacation reply',
        'away from office',
        'failure notice',
        'mail system error',
        'non-delivery',
        'message not delivered',
    ];

    $from_lower    = strtolower($from_email);
    $name_lower    = strtolower($from_name);
    $subject_lower = strtolower($subject);

    foreach ($daemon_email_patterns as $pattern) {
        if (strpos($from_lower, $pattern) !== false || strpos($name_lower, $pattern) !== false) {
            return true;
        }
    }

    foreach ($daemon_subject_patterns as $pattern) {
        if (strpos($subject_lower, $pattern) !== false) {
            return true;
        }
    }

    return false;
}

// ── Search UNSEEN emails ──────────────────────────────────────────────────────
$email_ids = imap_search($inbox, 'UNSEEN');
$processed   = 0;
$new_replies = [];

if ($email_ids) {
    rsort($email_ids);
    $email_ids = array_slice($email_ids, 0, 30);

    foreach ($email_ids as $msg_num) {
        $header     = imap_headerinfo($inbox, $msg_num);
        $raw_header = imap_fetchheader($inbox, $msg_num);

        // Message-ID for deduplication
        preg_match('/^Message-ID:\s*(.+)$/mi', $raw_header, $mid);
        $message_id = trim($mid[1] ?? '');

        if ($message_id) {
            $chk = $conn->prepare("SELECT id FROM tbl_email_replies WHERE message_id = ?");
            $chk->bind_param('s', $message_id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) { $chk->close(); continue; }
            $chk->close();
        }

        // From
        $from_obj   = $header->from[0] ?? null;
        $from_email = $from_obj ? ($from_obj->mailbox . '@' . $from_obj->host) : '';
        $from_name  = $from_obj ? decodeMime($from_obj->personal ?? '') : '';

        // ── Skip our own sent emails ──────────────────────────────────────────
        if (strtolower($from_email) === strtolower(MAIL_USERNAME)) {
            imap_setflag_full($inbox, $msg_num, '\\Seen');
            continue;
        }

        $subject = decodeMime($header->subject ?? '');

        // ── Skip bounce/daemon/auto-reply emails ──────────────────────────────
        if (isDaemonOrBounce($from_email, $from_name, $subject)) {
            imap_setflag_full($inbox, $msg_num, '\\Seen'); // mark seen so we never fetch it again
            continue;
        }

        // Also check the X-Failed-Recipients and Content-Type headers
        // for delivery status reports (RFC 3464)
        if (preg_match('/^Content-Type:\s*multipart\/report/mi', $raw_header) ||
            preg_match('/^Content-Type:\s*message\/delivery-status/mi', $raw_header) ||
            preg_match('/^X-Failed-Recipients:/mi', $raw_header) ||
            preg_match('/^Auto-Submitted:\s*auto-(replied|generated|notified)/mi', $raw_header)
        ) {
            imap_setflag_full($inbox, $msg_num, '\\Seen');
            continue;
        }

        // Get body
        $body       = getBody($inbox, $msg_num);
        $body_plain = stripQuotedReply($body['plain']);
        $body_html  = $body['html'];

        if (empty($body_plain) && empty($body_html)) continue;

        // ── Match to a notification ───────────────────────────────────────────
        $clean_subj       = trim(preg_replace('/^(Re:\s*|Fwd:\s*)+/i', '', $subject));
        $matched_notif_id = null;

        // Strategy 1: subject matches a notification title for this sender
        if ($clean_subj) {
            $s = $conn->prepare(
                "SELECT n.notification_id FROM tbl_notifications n
                 JOIN tbl_users u ON u.user_id = n.user_id
                 WHERE u.email = ? AND n.title LIKE ?
                 ORDER BY n.created_at DESC LIMIT 1"
            );
            if ($s) {
                $like = '%' . $conn->real_escape_string($clean_subj) . '%';
                $s->bind_param('ss', $from_email, $like);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
                if ($row) $matched_notif_id = intval($row['notification_id']);
            }
        }

        // Strategy 2: any notification for this email address
        if (!$matched_notif_id) {
            $s = $conn->prepare(
                "SELECT n.notification_id FROM tbl_notifications n
                 JOIN tbl_users u ON u.user_id = n.user_id
                 WHERE u.email = ? ORDER BY n.created_at DESC LIMIT 1"
            );
            if ($s) {
                $s->bind_param('s', $from_email);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
                if ($row) $matched_notif_id = intval($row['notification_id']);
            }
        }

        // Strategy 3: check tbl_email_history for this recipient
        if (!$matched_notif_id) {
            $s = $conn->prepare(
                "SELECT n.notification_id FROM tbl_notifications n
                 INNER JOIN tbl_email_history eh ON eh.id = n.reference_id
                 WHERE n.reference_type = 'announcement'
                 ORDER BY n.created_at DESC LIMIT 1"
            );
            if ($s) {
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
                if ($row) $matched_notif_id = intval($row['notification_id']);
            }
        }

        if (!$matched_notif_id) {
            // Can't match — mark seen so we don't keep fetching it
            imap_setflag_full($inbox, $msg_num, '\\Seen');
            continue;
        }

        // ── Insert reply ──────────────────────────────────────────────────────
        $ins = $conn->prepare(
            "INSERT INTO tbl_email_replies
             (notification_id, from_email, from_name, subject, body_text, body_html, message_id, direction, is_read)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'inbound', 0)"
        );
        $ins->bind_param('issssss',
            $matched_notif_id, $from_email, $from_name,
            $subject, $body_plain, $body_html, $message_id
        );

        if ($ins->execute()) {
            $reply_id = $conn->insert_id;
            $processed++;

            // Notify all admins
            $notif_msg   = "New reply from {$from_name} ({$from_email}): " . mb_substr($body_plain, 0, 100) . '...';
            $notif_title = 'Reply: ' . mb_substr($subject, 0, 100);
            $adm = $conn->prepare(
                "INSERT INTO tbl_notifications (user_id, type, reference_type, reference_id, title, message, is_read)
                 SELECT user_id, 'email_reply', 'notification', ?, ?, ?, 0
                 FROM tbl_users WHERE role IN ('Super Admin','Super Administrator')"
            );
            $adm->bind_param('iss', $matched_notif_id, $notif_title, $notif_msg);
            $adm->execute();
            $adm->close();

            // Mark seen in Gmail
            imap_setflag_full($inbox, $msg_num, '\\Seen');

            // Return the new reply for immediate display
            $new_replies[] = [
                'id'              => $reply_id,
                'notification_id' => $matched_notif_id,
                'from_email'      => $from_email,
                'from_name'       => $from_name ?: $from_email,
                'subject'         => $subject,
                'body_text'       => $body_plain,
                'direction'       => 'inbound',
                'created_at'      => date('D, M j, Y g:i A'),
                'is_read'         => 0,
            ];
        }
        $ins->close();
    }
}

imap_close($inbox);

echo json_encode([
    'success'     => true,
    'processed'   => $processed,
    'new_replies' => $new_replies,
    'message'     => $processed > 0
        ? "$processed new reply/replies fetched."
        : 'No new replies found.'
]);