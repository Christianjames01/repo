<?php
/**
 * check_replies.php - modules/notifications/check_replies.php
 *
 * Dual purpose:
 *   1. AJAX poll: called from notification-detail.php to fetch new inbound replies
 *      for a specific notification thread (POST with notification_id).
 *   2. Background / cron fetch: reads new emails from the barangay inbox via IMAP,
 *      stores them in tbl_email_replies, and creates a tbl_notifications row for
 *      every Super Admin so the bell icon and notifications index light up.
 *
 * Both paths return JSON.
 * Supports extracting inline images and file attachments from resident emails.
 */

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$user_role = getCurrentUserRole();
$is_admin  = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');

if (!$is_admin) {
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit();
}

// ── Attachment upload directory ───────────────────────────────────────────────
// Adjust BASE_PATH / UPLOAD_URL to match your server layout.
// e.g. if your project root is /var/www/html/barangay, set:
//   define('BASE_PATH', '/var/www/html/barangay');
//   define('BASE_URL',  'http://yourdomain.com/barangay');
$attachDir = (defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : rtrim($_SERVER['DOCUMENT_ROOT'], '/'))
           . '/uploads/email_attachments/';
$attachUrl = (defined('BASE_URL')  ? rtrim(BASE_URL, '/')  : '')
           . '/uploads/email_attachments/';

if (!is_dir($attachDir)) {
    @mkdir($attachDir, 0755, true);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Strip common email footers / quoted-reply blocks so only the
 * resident's actual new text is shown in the conversation thread.
 */
function stripEmailQuotes(string $body): string {
    $body = preg_replace('/\r?\nOn .+? wrote:\r?\n[\s\S]*/i', '', $body);
    $lines = explode("\n", $body);
    $clean = [];
    foreach ($lines as $line) {
        if (!preg_match('/^\s*>/', $line)) {
            $clean[] = $line;
        }
    }
    return rtrim(implode("\n", $clean));
}

/**
 * Try to match an inbound email to an existing notification thread by
 * inspecting the In-Reply-To / References headers or the subject line.
 */
function resolveNotificationId(mysqli $conn, string $subject, string $inReplyTo, string $references): int {
    foreach ([$inReplyTo, $references] as $hdr) {
        if (!$hdr) continue;
        preg_match_all('/<([^>]+)>/', $hdr, $m);
        foreach ($m[1] as $msgId) {
            $row = fetchOne($conn,
                "SELECT notification_id FROM tbl_email_replies WHERE message_id = ? LIMIT 1",
                [$msgId], 's'
            );
            if ($row) return intval($row['notification_id']);
        }
    }

    $clean = trim(preg_replace('/^(re|fwd|fw):\s*/i', '', $subject));
    if ($clean) {
        $row = fetchOne($conn,
            "SELECT notification_id FROM tbl_notifications
             WHERE title LIKE ? ORDER BY created_at DESC LIMIT 1",
            ['%' . $clean . '%'], 's'
        );
        if ($row) return intval($row['notification_id']);
    }

    return 0;
}

/**
 * Decode a MIME part body based on its encoding.
 */
function decodePart(string $raw, int $encoding): string {
    if ($encoding === 3) return base64_decode($raw);
    if ($encoding === 4) return quoted_printable_decode($raw);
    return $raw;
}

/**
 * Walk IMAP message parts recursively and collect:
 *   - text/plain  → $bodyText
 *   - text/html   → $bodyHtml
 *   - image/*     → saved to disk, path added to $attachments
 *   - other files → saved to disk, path added to $attachments
 */
function walkParts(
    $mbox, int $uid,
    array $parts,
    string $prefix,
    string &$bodyText,
    string &$bodyHtml,
    array  &$attachments,
    string  $attachDir,
    string  $attachUrl
): void {
    foreach ($parts as $idx => $part) {
        $partNum = $prefix ? ($prefix . '.' . ($idx + 1)) : (string)($idx + 1);
        $subtype  = strtolower($part->subtype ?? '');
        $mainType = $part->type ?? 0;   // 0=text,1=multipart,2=message,3=app,4=audio,5=image,6=video,7=other
        $enc      = $part->encoding ?? 0;

        // ── Determine filename for non-text parts ──────────────────────────
        $filename = '';
        $dispo    = $part->disposition ?? '';

        // Check Content-Disposition parameters first
        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $dp) {
                if (strtolower($dp->attribute) === 'filename') {
                    $filename = imap_utf8($dp->value);
                }
            }
        }
        // Fall back to Content-Type name parameter
        if (!$filename && !empty($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (strtolower($p->attribute) === 'name') {
                    $filename = imap_utf8($p->value);
                }
            }
        }

        // ── Text parts ────────────────────────────────────────────────────
        if ($mainType === 0) { // text/*
            // Skip inline text parts that are actually attachments with a filename
            if ($filename && strtolower($dispo) === 'attachment') {
                // treat as generic file attachment below
            } else {
                $raw = imap_fetchbody($mbox, $uid, $partNum);
                $raw = decodePart($raw, $enc);

                // Charset conversion
                $charset = 'UTF-8';
                if (!empty($part->parameters)) {
                    foreach ($part->parameters as $p) {
                        if (strtolower($p->attribute) === 'charset') {
                            $charset = strtoupper($p->value);
                        }
                    }
                }
                if ($charset !== 'UTF-8') {
                    $raw = mb_convert_encoding($raw, 'UTF-8', $charset);
                }

                if ($subtype === 'html' && !$bodyHtml) {
                    $bodyHtml = $raw;
                } elseif ($subtype === 'plain' && !$bodyText) {
                    $bodyText = $raw;
                }

                // Recurse into sub-parts
                if (!empty($part->parts)) {
                    walkParts($mbox, $uid, $part->parts, $partNum, $bodyText, $bodyHtml, $attachments, $attachDir, $attachUrl);
                }
                continue;
            }
        }

        // ── Multipart containers — just recurse ───────────────────────────
        if ($mainType === 1) { // multipart/*
            if (!empty($part->parts)) {
                walkParts($mbox, $uid, $part->parts, $partNum, $bodyText, $bodyHtml, $attachments, $attachDir, $attachUrl);
            }
            continue;
        }

        // ── Image / file attachments ──────────────────────────────────────
        // Accept images (type 5), common application types (type 3), audio (4), video (6)
        // Also handle inline images embedded in HTML emails
        $isImage = ($mainType === 5) || (
            $mainType === 0 &&
            in_array($subtype, ['jpeg','jpg','png','gif','webp','bmp','svg+xml'])
        );

        // Build a safe filename
        if (!$filename) {
            $extMap = [
                // images
                'jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png',
                'gif'  => 'gif', 'webp' => 'webp', 'bmp' => 'bmp',
                // docs
                'pdf'  => 'pdf', 'msword' => 'doc',
                'vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'vnd.ms-excel' => 'xls',
                'vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'zip' => 'zip', 'plain' => 'txt',
            ];
            $ext      = $extMap[$subtype] ?? 'bin';
            $filename = 'attachment_' . uniqid() . '.' . $ext;
        }

        // Sanitize filename
        $safeName  = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
        $saveName  = date('Ymd_His') . '_' . $safeName;
        $savePath  = $attachDir . $saveName;
        $saveUrl   = $attachUrl . $saveName;

        // Fetch and decode the part
        $raw = imap_fetchbody($mbox, $uid, $partNum);
        $raw = decodePart($raw, $enc);

        if ($raw && file_put_contents($savePath, $raw) !== false) {
            $mime = ($mainType === 5)
                ? ('image/' . ($subtype === 'jpeg' ? 'jpeg' : $subtype))
                : mime_content_type($savePath);

            $attachments[] = [
                'path'     => $savePath,
                'url'      => $saveUrl,
                'filename' => $filename,
                'size'     => strlen($raw),
                'mime'     => $mime ?: 'application/octet-stream',
                'is_image' => (bool)preg_match('/^image\//i', $mime),
            ];
        }

        // Recurse in case there are nested parts
        if (!empty($part->parts)) {
            walkParts($mbox, $uid, $part->parts, $partNum, $bodyText, $bodyHtml, $attachments, $attachDir, $attachUrl);
        }
    }
}

// ── IMAP fetch function ───────────────────────────────────────────────────────

function fetchNewEmails(mysqli $conn, string $attachDir, string $attachUrl): array {
    $imap_host     = defined('IMAP_HOST')     ? IMAP_HOST     : '{imap.gmail.com:993/imap/ssl}INBOX';
    $imap_user     = defined('IMAP_USER')     ? IMAP_USER     : (defined('MAIL_USERNAME') ? MAIL_USERNAME : '');
    $imap_password = defined('IMAP_PASSWORD') ? IMAP_PASSWORD : (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '');

    if (!$imap_user || !$imap_password) {
        return ['success' => false, 'error' => 'IMAP credentials not configured. Set IMAP_USER and IMAP_PASSWORD in config.php.'];
    }

    if (!function_exists('imap_open')) {
        return ['success' => false, 'error' => 'PHP IMAP extension is not enabled on this server.'];
    }

    $mbox = @imap_open($imap_host, $imap_user, $imap_password, 0, 1);
    if (!$mbox) {
        return ['success' => false, 'error' => 'Could not connect to mailbox: ' . imap_last_error()];
    }

    $uids = imap_search($mbox, 'UNSEEN');
    if (!$uids) {
        imap_close($mbox);
        return ['success' => true, 'processed' => 0, 'new_replies' => []];
    }

    // Ensure tbl_email_replies exists with attachments column
    $conn->query("
        CREATE TABLE IF NOT EXISTS tbl_email_replies (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            notification_id  INT          NOT NULL DEFAULT 0,
            from_email       VARCHAR(255) NOT NULL,
            from_name        VARCHAR(255) DEFAULT '',
            subject          VARCHAR(500) DEFAULT '',
            body_text        LONGTEXT     DEFAULT NULL,
            body_html        LONGTEXT     DEFAULT NULL,
            attachments      LONGTEXT     DEFAULT NULL COMMENT 'JSON array of attachment objects',
            direction        ENUM('inbound','outbound') NOT NULL DEFAULT 'inbound',
            is_read          TINYINT(1)   NOT NULL DEFAULT 0,
            message_id       VARCHAR(500) DEFAULT NULL,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif (notification_id),
            INDEX idx_dir   (direction),
            INDEX idx_read  (is_read),
            INDEX idx_msgid (message_id(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add missing columns for older installs
    foreach (['message_id VARCHAR(500) DEFAULT NULL', 'attachments LONGTEXT DEFAULT NULL'] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        $chk = $conn->query("SHOW COLUMNS FROM tbl_email_replies LIKE '$colName'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE tbl_email_replies ADD COLUMN $colDef");
        }
    }

    // Get all Super Admin user IDs
    $adminIds = [];
    $ar = $conn->query("SELECT user_id FROM tbl_users WHERE role IN ('Super Admin','Super Administrator')");
    if ($ar) {
        while ($row = $ar->fetch_assoc()) {
            $adminIds[] = intval($row['user_id']);
        }
    }

    $processed  = 0;
    $newReplies = [];

    foreach ($uids as $uid) {
        $header  = imap_headerinfo($mbox, $uid);
        $rawHdrs = imap_fetchheader($mbox, $uid);

        // ── Sender ─────────────────────────────────────────────────────────
        $fromEmail = '';
        $fromName  = '';
        if (!empty($header->from[0])) {
            $f         = $header->from[0];
            $fromEmail = isset($f->mailbox, $f->host) ? ($f->mailbox . '@' . $f->host) : '';
            $fromName  = isset($f->personal) ? imap_utf8($f->personal) : $fromEmail;
        }

        // Skip loop-back emails
        $ownEmail = defined('MAIL_FROM_EMAIL') ? strtolower(MAIL_FROM_EMAIL) : strtolower($imap_user);
        if (strtolower($fromEmail) === $ownEmail) {
            imap_setflag_full($mbox, (string)$uid, '\\Seen');
            continue;
        }

        // ── Subject & headers ──────────────────────────────────────────────
        $subject    = isset($header->subject) ? imap_utf8($header->subject) : '(No Subject)';
        $subject    = mb_decode_mimeheader($subject);
        $msgId      = '';
        $inReplyTo  = '';
        $references = '';
        if (preg_match('/^Message-ID:\s*(.+)$/im', $rawHdrs, $mx)) $msgId      = trim($mx[1]);
        if (preg_match('/^In-Reply-To:\s*(.+)$/im', $rawHdrs, $rx)) $inReplyTo  = trim($rx[1]);
        if (preg_match('/^References:\s*(.+)$/im', $rawHdrs, $rfx)) $references = trim($rfx[1]);

        // Skip duplicates
        if ($msgId) {
            $dup = fetchOne($conn, "SELECT id FROM tbl_email_replies WHERE message_id = ? LIMIT 1", [$msgId], 's');
            if ($dup) {
                imap_setflag_full($mbox, (string)$uid, '\\Seen');
                continue;
            }
        }

        // ── Parse body + attachments ───────────────────────────────────────
        $structure   = imap_fetchstructure($mbox, $uid);
        $bodyText    = '';
        $bodyHtml    = '';
        $attachments = [];

        if ($structure->type === 0) {
            // Single-part message
            $raw = imap_body($mbox, $uid);
            $raw = decodePart($raw, $structure->encoding ?? 0);
            if (strtolower($structure->subtype ?? '') === 'html') {
                $bodyHtml = $raw;
                $bodyText = strip_tags($raw);
            } else {
                $bodyText = $raw;
            }
        } else {
            walkParts(
                $mbox, $uid,
                $structure->parts ?? [],
                '',
                $bodyText, $bodyHtml,
                $attachments,
                $attachDir, $attachUrl
            );
            if (!$bodyText && $bodyHtml) {
                $bodyText = strip_tags($bodyHtml);
            }
        }

        $bodyText = stripEmailQuotes(trim($bodyText));

        // ── Resolve thread ─────────────────────────────────────────────────
        $notifId = resolveNotificationId($conn, $subject, $inReplyTo, $references);

        // Serialize attachments for storage (store only metadata + URL, not binary)
        $attachMeta = array_map(function($a) {
            return [
                'url'      => $a['url'],
                'filename' => $a['filename'],
                'size'     => $a['size'],
                'mime'     => $a['mime'],
                'is_image' => $a['is_image'],
            ];
        }, $attachments);
        $attachJson = !empty($attachMeta) ? json_encode($attachMeta) : null;

        // ── Insert into tbl_email_replies ──────────────────────────────────
        $ins = $conn->prepare("
            INSERT INTO tbl_email_replies
                (notification_id, from_email, from_name, subject, body_text, body_html,
                 attachments, direction, is_read, message_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'inbound', 0, ?, NOW())
        ");
        $ins->bind_param('isssssss',
            $notifId, $fromEmail, $fromName,
            $subject, $bodyText, $bodyHtml,
            $attachJson, $msgId
        );
        $ins->execute();
        $replyId = $ins->insert_id;
        $ins->close();

        // ── Create notification for every Super Admin ──────────────────────
        $notifTitle   = 'New Email Reply: ' . mb_strimwidth($subject, 0, 100, '…');
        $attachCount  = count($attachMeta);
        $attachSuffix = $attachCount > 0
            ? "\n\n[{$attachCount} attachment(s) included]"
            : '';
       $notifMessage = mb_strimwidth($bodyText ?: strip_tags($bodyHtml), 0, 300, '…') . $attachSuffix;

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
                $upd = $conn->prepare("UPDATE tbl_email_replies SET notification_id = ? WHERE id = ?");
                $upd->bind_param('ii', $newNotifId, $replyId);
                $upd->execute();
                $upd->close();
                $notifId = $newNotifId;
            }
        }

        imap_setflag_full($mbox, (string)$uid, '\\Seen');

        $processed++;
        $newReplies[] = [
            'id'          => $replyId,
            'from_name'   => $fromName,
            'from_email'  => $fromEmail,
            'subject'     => $subject,
            'body_text'   => mb_strimwidth($bodyText, 0, 500, '…'),
            'direction'   => 'inbound',
            'created_at'  => date('D, M j, Y g:i A'),
            'notif_id'    => $notifId,
            'attachments' => $attachMeta,
        ];
    }

    imap_close($mbox);

    return [
        'success'     => true,
        'processed'   => $processed,
        'new_replies' => $newReplies,
    ];
}

// ── Route: AJAX poll from notification-detail.php ────────────────────────────
$notifId = intval($_POST['notification_id'] ?? 0);

$fetchResult = fetchNewEmails($conn, $attachDir, $attachUrl);

if ($notifId) {
    $tblCheck = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
    if (!$tblCheck || $tblCheck->num_rows === 0) {
        echo json_encode(['success' => true, 'processed' => 0, 'new_replies' => []]);
        exit();
    }

    $knownIds = array_filter(array_map('intval', (array)($_POST['known_ids'] ?? [])));

    $sql    = "SELECT * FROM tbl_email_replies WHERE notification_id = ? AND direction = 'inbound'";
    $params = [$notifId];
    $types  = 'i';

    if (!empty($knownIds)) {
        $placeholders = implode(',', array_fill(0, count($knownIds), '?'));
        $sql    .= " AND id NOT IN ($placeholders)";
        $params  = array_merge($params, $knownIds);
        $types  .= str_repeat('i', count($knownIds));
    }

    $sql .= " ORDER BY created_at ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $newReplies = [];
    while ($row = $res->fetch_assoc()) {
        $attachMeta = [];
        if (!empty($row['attachments'])) {
            $decoded = json_decode($row['attachments'], true);
            if (is_array($decoded)) $attachMeta = $decoded;
        }
        $newReplies[] = [
            'id'          => $row['id'],
            'from_name'   => $row['from_name'],
            'from_email'  => $row['from_email'],
            'subject'     => $row['subject'],
            'body_text'   => $row['body_text'],
            'direction'   => $row['direction'],
            'created_at'  => date('D, M j, Y g:i A', strtotime($row['created_at'])),
            'attachments' => $attachMeta,
        ];
    }
    $stmt->close();

    $upd = $conn->prepare("UPDATE tbl_email_replies SET is_read = 1 WHERE notification_id = ? AND direction = 'inbound' AND is_read = 0");
    $upd->bind_param('i', $notifId);
    $upd->execute();
    $upd->close();

    echo json_encode([
        'success'     => true,
        'processed'   => count($newReplies),
        'new_replies' => $newReplies,
        'imap'        => $fetchResult,
    ]);
    exit();
}

echo json_encode($fetchResult);
exit();