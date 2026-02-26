<?php
/**
 * cron_fetch_emails.php
 *
 * ► Place at:
 *     E:\xampp\htdocs\barangaylink1\modules\notifications\cron_fetch_emails.php
 *
 * ► Test by visiting in your browser:
 *     http://localhost/barangaylink1/modules/notifications/cron_fetch_emails.php
 */

define('CRON_MODE', true);

// ── Path fix: hardcoded project root ─────────────────────────────────────────
// __DIR__ = E:\xampp\htdocs\barangaylink1\modules\notifications
// We need: E:\xampp\htdocs\barangaylink1
// dirname(__DIR__) = E:\xampp\htdocs\barangaylink1\modules  ← one level up
// dirname(dirname(__DIR__)) = E:\xampp\htdocs\barangaylink1 ← two levels up ✓
// The previous error showed it going to \modules/config — that means the old
// file had the wrong __DIR__ reference. Using realpath() ensures it works on
// both Windows (backslash) and Linux (forward slash).

$root = realpath(dirname(dirname(__DIR__)));
// $root should now be: E:\xampp\htdocs\barangaylink1

if (!file_exists($root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php')) {
    die('ERROR: Cannot find config.php. Resolved root: ' . $root . '<br>'
      . 'Expected: ' . $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php<br>'
      . 'If this path looks wrong, change $root on line 20 to the full path, e.g.:<br>'
      . '$root = "E:\\\\xampp\\\\htdocs\\\\barangaylink1";');
}

require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';

// ── Browser vs CLI output ─────────────────────────────────────────────────────
$is_browser = !empty($_SERVER['HTTP_HOST']);
if ($is_browser) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Email Fetcher</title>'
       . '<style>'
       . 'body{font-family:monospace;font-size:13px;padding:24px;background:#0f172a;color:#e2e8f0;line-height:1.7}'
       . 'h2{color:#fff} hr{border-color:#334155}'
       . '.ok{color:#4ade80} .err{color:#f87171} .skip{color:#94a3b8} .stored{color:#60a5fa}'
       . 'a{color:#60a5fa}'
       . '</style></head><body>'
       . '<h2>📬 Barangay Email Fetcher</h2><hr>';
}

function cronLog(string $msg, string $type = ''): void {
    global $is_browser;
    $ts    = date('Y-m-d H:i:s');
    $class = $type ? " class=\"{$type}\"" : '';
    if ($is_browser) {
        echo "<div{$class}>[{$ts}] " . htmlspecialchars($msg) . "</div>\n";
        if (ob_get_level()) { ob_flush(); flush(); }
    } else {
        echo "[{$ts}] {$msg}" . PHP_EOL;
    }
}

cronLog("Root resolved to: {$root}");
cronLog('config.php loaded OK.', 'ok');

// ── IMAP credentials ──────────────────────────────────────────────────────────
$imap_host = defined('IMAP_HOST')     ? IMAP_HOST     : '{imap.gmail.com:993/imap/ssl}INBOX';
$imap_user = defined('IMAP_USER')     ? IMAP_USER     : (defined('MAIL_USERNAME') ? MAIL_USERNAME : '');
$imap_pass = defined('IMAP_PASSWORD') ? IMAP_PASSWORD : (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '');

if (!$imap_user || !$imap_pass) {
    cronLog('IMAP credentials not set in config.php.', 'err');
    cronLog("Add these lines to your config/config.php:", 'err');
    cronLog("  define('IMAP_HOST',     '{imap.gmail.com:993/imap/ssl}INBOX');", 'err');
    cronLog("  define('IMAP_USER',     'sanjuanbrgycentro@gmail.com');", 'err');
    cronLog("  define('IMAP_PASSWORD', 'xxxx xxxx xxxx xxxx'); // 16-char Gmail App Password", 'err');
    if ($is_browser) echo '</body></html>';
    exit(1);
}

if (!function_exists('imap_open')) {
    cronLog('PHP IMAP extension is not enabled!', 'err');
    cronLog('Fix: Open E:\\xampp\\php\\php.ini', 'err');
    cronLog('Search for:   ;extension=imap', 'err');
    cronLog('Change to:     extension=imap   (remove the semicolon)', 'err');
    cronLog('Then RESTART Apache in XAMPP Control Panel.', 'err');
    if ($is_browser) echo '</body></html>';
    exit(1);
}

// ── Connect to Gmail IMAP ─────────────────────────────────────────────────────
cronLog("Connecting to Gmail ({$imap_user})...");
$mbox = @imap_open($imap_host, $imap_user, $imap_pass, 0, 1);
if (!$mbox) {
    cronLog('Connection FAILED: ' . imap_last_error(), 'err');
    cronLog('Check: Gmail IMAP enabled? Using App Password (not account password)?', 'err');
    if ($is_browser) echo '</body></html>';
    exit(1);
}
cronLog('Connected to Gmail inbox.', 'ok');

// ── Ensure tbl_email_replies exists ──────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS tbl_email_replies (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        notification_id  INT          NOT NULL DEFAULT 0,
        from_email       VARCHAR(255) NOT NULL,
        from_name        VARCHAR(255) DEFAULT '',
        subject          VARCHAR(500) DEFAULT '',
        body_text        LONGTEXT,
        body_html        LONGTEXT,
        direction        ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
        is_read          TINYINT(1)   NOT NULL DEFAULT 0,
        message_id       VARCHAR(500) DEFAULT NULL,
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif  (notification_id),
        INDEX idx_msgid  (message_id(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$col = $conn->query("SHOW COLUMNS FROM tbl_email_replies LIKE 'message_id'");
if ($col && $col->num_rows === 0) {
    $conn->query("ALTER TABLE tbl_email_replies
                  ADD COLUMN message_id VARCHAR(500) DEFAULT NULL,
                  ADD INDEX idx_msgid (message_id(191))");
    cronLog('Added message_id column.', 'ok');
}

// ── Super Admin IDs ───────────────────────────────────────────────────────────
$adminIds = [];

// Try role column first, fall back to role_name/role_id
$roleCheck = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'role'");
if ($roleCheck && $roleCheck->num_rows > 0) {
    $ar = $conn->query("SELECT user_id FROM tbl_users WHERE role IN ('Super Admin','Super Administrator')");
} else {
    // Role stored via join — adjust query to match your schema
    $ar = $conn->query("
        SELECT u.user_id FROM tbl_users u
        JOIN tbl_roles r ON u.role_id = r.role_id
        WHERE r.role_name IN ('Super Admin','Super Administrator')
    ");
}
if ($ar) { while ($row = $ar->fetch_assoc()) $adminIds[] = intval($row['user_id']); }

if (empty($adminIds)) {
    cronLog('WARNING: No Super Admin found — notifications will not be created!', 'err');
} else {
    cronLog('Super Admin IDs: ' . implode(', ', $adminIds), 'ok');
}

$own_email = strtolower(defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : $imap_user);

// ── Helper functions ──────────────────────────────────────────────────────────
function stripQuotes(string $body): string {
    $body  = preg_replace('/\r?\nOn .+? wrote:\r?\n[\s\S]*/i', '', $body);
    $lines = explode("\n", $body);
    $out   = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\s*>/', $line)) $out[] = $line;
    }
    return rtrim(implode("\n", $out));
}

function resolveNotifId(mysqli $db, string $subject, string $inReplyTo, string $refs): int {
    foreach ([$inReplyTo, $refs] as $hdr) {
        if (!$hdr) continue;
        preg_match_all('/<([^>]+)>/', $hdr, $m);
        foreach ($m[1] as $mid) {
            $esc = $db->real_escape_string($mid);
            $r   = $db->query("SELECT notification_id FROM tbl_email_replies WHERE message_id='$esc' LIMIT 1");
            if ($r && ($row = $r->fetch_assoc())) return intval($row['notification_id']);
        }
    }
    $clean = trim(preg_replace('/^(re|fwd|fw):\s*/i', '', $subject));
    if ($clean) {
        $esc = $db->real_escape_string('%' . $clean . '%');
        $r   = $db->query("SELECT notification_id FROM tbl_notifications WHERE title LIKE '$esc' ORDER BY created_at DESC LIMIT 1");
        if ($r && ($row = $r->fetch_assoc())) return intval($row['notification_id']);
    }
    return 0;
}

function decodeBodyPart($mbox, int $uid, string $num, $part): string {
    $raw = imap_fetchbody($mbox, $uid, $num);
    $enc = $part->encoding ?? 0;
    if ($enc === 3)     $raw = base64_decode($raw);
    elseif ($enc === 4) $raw = quoted_printable_decode($raw);
    $charset = 'UTF-8';
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'charset') { $charset = strtoupper($p->value); break; }
        }
    }
    if ($charset !== 'UTF-8' && $charset !== '') {
        $conv = @mb_convert_encoding($raw, 'UTF-8', $charset);
        if ($conv !== false) $raw = $conv;
    }
    return $raw;
}

// ── Search UNSEEN ─────────────────────────────────────────────────────────────
$uids = imap_search($mbox, 'UNSEEN');
if (!$uids) {
    cronLog('No new messages.', 'skip');
    imap_close($mbox);
    if ($is_browser) echo '<p class="skip">ℹ️ No new emails to process.</p></body></html>';
    exit(0);
}

cronLog(count($uids) . ' new message(s) found.', 'ok');
$stored = 0; $skipped = 0;

foreach ($uids as $uid) {
    $header  = @imap_headerinfo($mbox, $uid);
    $rawHdrs = @imap_fetchheader($mbox, $uid);
    if (!$header) { $skipped++; continue; }

    $fromEmail = '';
    $fromName  = '';
    if (!empty($header->from[0])) {
        $f         = $header->from[0];
        $fromEmail = isset($f->mailbox, $f->host) ? ($f->mailbox . '@' . $f->host) : '';
        $fromName  = isset($f->personal) ? imap_utf8($f->personal) : $fromEmail;
    }
    if (!$fromEmail) { $skipped++; imap_setflag_full($mbox, (string)$uid, '\\Seen'); continue; }
    if (strtolower($fromEmail) === $own_email) {
        imap_setflag_full($mbox, (string)$uid, '\\Seen'); $skipped++; continue;
    }

    $subject    = isset($header->subject) ? mb_decode_mimeheader(imap_utf8($header->subject)) : '(No Subject)';
    $msgId      = '';
    $inReplyTo  = '';
    $references = '';
    if (preg_match('/^Message-ID:\s*(.+)$/im', $rawHdrs, $mx))   $msgId      = trim($mx[1]);
    if (preg_match('/^In-Reply-To:\s*(.+)$/im', $rawHdrs, $rx))  $inReplyTo  = trim($rx[1]);
    if (preg_match('/^References:\s*(.+)$/im',  $rawHdrs, $rfx)) $references = trim($rfx[1]);

    if ($msgId) {
        $esc = $conn->real_escape_string($msgId);
        $dup = $conn->query("SELECT id FROM tbl_email_replies WHERE message_id='$esc' LIMIT 1");
        if ($dup && $dup->num_rows > 0) {
            cronLog("  SKIP (duplicate): [$fromEmail] $subject", 'skip');
            imap_setflag_full($mbox, (string)$uid, '\\Seen'); $skipped++; continue;
        }
    }

    // Parse body
    $bodyText = ''; $bodyHtml = '';
    $structure = @imap_fetchstructure($mbox, $uid);
    if (!$structure) {
        $bodyText = imap_body($mbox, $uid);
    } elseif ($structure->type === 0) {
        $raw = imap_body($mbox, $uid);
        $enc = $structure->encoding ?? 0;
        if ($enc === 3) $raw = base64_decode($raw);
        elseif ($enc === 4) $raw = quoted_printable_decode($raw);
        if (strtolower($structure->subtype ?? '') === 'html') { $bodyHtml = $raw; $bodyText = strip_tags($raw); }
        else $bodyText = $raw;
    } else {
        $walker = function($parts, $prefix) use ($mbox, $uid, &$bodyText, &$bodyHtml, &$walker) {
            foreach ($parts as $i => $part) {
                $num = $prefix ? ($prefix . '.' . ($i+1)) : (string)($i+1);
                $sub = strtolower($part->subtype ?? '');
                if ($part->type === 0) {
                    $raw = decodeBodyPart($mbox, $uid, $num, $part);
                    if ($sub === 'html' && !$bodyHtml)      $bodyHtml = $raw;
                    elseif ($sub === 'plain' && !$bodyText) $bodyText = $raw;
                }
                if (!empty($part->parts)) $walker($part->parts, $num);
            }
        };
        $walker($structure->parts ?? [], '');
        if (!$bodyText && $bodyHtml) $bodyText = strip_tags($bodyHtml);
    }
    $bodyText = stripQuotes(trim($bodyText));

    $notifId = resolveNotifId($conn, $subject, $inReplyTo, $references);

    // Save email reply
    $ins = $conn->prepare("
        INSERT INTO tbl_email_replies
            (notification_id, from_email, from_name, subject, body_text, body_html, direction, is_read, message_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'inbound', 0, ?, NOW())
    ");
    $ins->bind_param('issssss', $notifId, $fromEmail, $fromName, $subject, $bodyText, $bodyHtml, $msgId);
    $ins->execute();
    $replyId = $ins->insert_id;
    $ins->close();

    // Create notification for each Super Admin
    $notifTitle   = 'New Email: ' . mb_strimwidth($subject, 0, 100, '…');
    $notifMessage = "From: {$fromName} <{$fromEmail}>\n\n" . mb_strimwidth($bodyText ?: strip_tags($bodyHtml), 0, 300, '…');

    foreach ($adminIds as $adminId) {
        $ni = $conn->prepare("
            INSERT INTO tbl_notifications
                (user_id, type, reference_type, reference_id, title, message, is_read, created_at)
            VALUES (?, 'email_reply', 'email_inbox', ?, ?, ?, 0, NOW())
        ");
        $ni->bind_param('iiss', $adminId, $replyId, $notifTitle, $notifMessage);
        $ni->execute();
        $newNotifId = $ni->insert_id;
        $ni->close();

        if (!$notifId && $newNotifId) {
            $notifId = $newNotifId;
            $upd = $conn->prepare("UPDATE tbl_email_replies SET notification_id=? WHERE id=?");
            $upd->bind_param('ii', $notifId, $replyId);
            $upd->execute(); $upd->close();
        }
    }

    imap_setflag_full($mbox, (string)$uid, '\\Seen');
    $stored++;
    cronLog("  ✅ STORED: [$fromEmail] \"$subject\" → reply_id=$replyId, notif_id=$notifId", 'stored');
}

imap_close($mbox);
cronLog("Done. Stored={$stored}, Skipped={$skipped}.", 'ok');

if ($is_browser) {
    echo '<hr>';
    if ($stored > 0) {
        echo "<p class='ok' style='font-size:15px;'>✅ {$stored} email(s) added!
              <a href='/barangaylink1/modules/notifications/index.php'>→ Go to Notifications</a></p>";
    } else {
        echo "<p class='skip'>ℹ️ No new emails found.</p>";
    }
    echo '</body></html>';
}