<?php
/**
 * Compose / Reply Page - modules/notifications/compose-reply.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$user_role = getCurrentUserRole();
$is_admin  = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');

if (!$is_admin) {
    $_SESSION['error_message'] = 'Access denied.';
    header('Location: index.php'); exit();
}

$notif_id  = intval($_GET['notif_id']  ?? 0);
$mode      = $_GET['mode']      ?? 'reply';
$to_email  = $_GET['to_email']  ?? '';
$to_name   = $_GET['to_name']   ?? 'Resident';
$subject   = $_GET['subject']   ?? '';
$reply_id  = intval($_GET['reply_id']  ?? 0);

if (!$notif_id) { header('Location: index.php'); exit(); }

$notif = fetchOne($conn,
    "SELECT * FROM tbl_notifications WHERE notification_id = ?",
    [$notif_id], 'i'
);
if (!$notif) { header('Location: index.php'); exit(); }

if (!$subject) {
    $prefix  = ($mode === 'forward') ? 'Fwd: ' : 'Re: ';
    $subject = $prefix . $notif['title'];
}

$quoted_body = '';
$quoted_from = '';
$quoted_time = '';
if ($reply_id) {
    $orig = fetchOne($conn, "SELECT * FROM tbl_email_replies WHERE id = ?", [$reply_id], 'i');
    if ($orig) {
        $quoted_body = strip_tags($orig['body_text'] ?? $orig['body_html'] ?? '');
        $quoted_from = $orig['from_name'] ? $orig['from_name'] . ' <' . $orig['from_email'] . '>' : $orig['from_email'];
        $quoted_time = $orig['created_at'] ? date('D, M j, Y \a\t g:i A', strtotime($orig['created_at'])) : '';
    }
} else {
    $quoted_body = $notif['message'] ?? '';
    $quoted_from = 'System Notification';
    $quoted_time = $notif['created_at'] ? date('D, M j, Y \a\t g:i A', strtotime($notif['created_at'])) : '';
}

$reply_all_recipients = [];
if ($mode === 'reply_all') {
    $tbl_chk = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
    if ($tbl_chk && $tbl_chk->num_rows > 0) {
        $inb = $conn->prepare("SELECT DISTINCT from_email, from_name FROM tbl_email_replies WHERE notification_id = ? AND direction = 'inbound'");
        $inb->bind_param('i', $notif_id);
        $inb->execute();
        $res = $inb->get_result();
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['from_email'])) {
                $reply_all_recipients[] = ['email' => $row['from_email'], 'name' => $row['from_name'] ?: $row['from_email']];
            }
        }
        $inb->close();
    }
    if ($to_email && !in_array($to_email, array_column($reply_all_recipients, 'email'))) {
        $reply_all_recipients[] = ['email' => $to_email, 'name' => $to_name];
    }
}

$send_success = '';
$send_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['compose_send'])) {
    require_once '../../includes/email_helper.php';

    $post_subject  = trim($_POST['post_subject']  ?? '');
    $post_body_raw = trim($_POST['post_body_text'] ?? '');
    $post_mode     = $_POST['post_mode']     ?? 'reply';
    $post_to       = trim($_POST['post_to']   ?? '');
    $post_cc       = trim($_POST['post_cc']   ?? '');

    if (!$post_subject) { $send_error = 'Subject is required.'; }
    elseif (!$post_body_raw) { $send_error = 'Message body is required.'; }
    elseif ($post_mode !== 'reply_all' && !$post_to) { $send_error = 'Recipient (To) is required.'; }
    else {
        $recipients = [];
        if ($post_mode === 'reply_all') {
            foreach ($reply_all_recipients as $r) {
                $recipients[] = ['email' => $r['email'], 'full_name' => $r['name']];
            }
        } else {
            foreach (explode(',', $post_to) as $addr) {
                $addr = trim($addr);
                if ($addr) $recipients[] = ['email' => $addr, 'full_name' => $addr];
            }
        }

        if (empty($recipients)) {
            $send_error = 'No valid recipients found.';
        } else {
            $body_html = getEmailTemplate([
                'title'       => htmlspecialchars($post_subject),
                'greeting'    => 'Dear Resident,',
                'message'     => nl2br(htmlspecialchars($post_body_raw)),
                'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Barangay Office'
            ]);

            $sent = 0; $fails = 0; $fail_list = [];
            foreach ($recipients as $r) {
                try { $ok = sendEmail($r['email'], $post_subject, $body_html, $r['full_name']); }
                catch (Throwable $e) { error_log("compose-reply sendEmail: " . $e->getMessage()); $ok = false; }

                if ($ok) {
                    $sent++;
                    $tbl_chk2 = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
                    if ($tbl_chk2 && $tbl_chk2->num_rows > 0) {
                        $mail_from = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '';
                        $out = $conn->prepare("INSERT INTO tbl_email_replies (notification_id, from_email, from_name, subject, body_text, direction, is_read) VALUES (?, ?, 'Barangay System', ?, ?, 'outbound', 1)");
                        $out->bind_param('isss', $notif_id, $mail_from, $post_subject, $post_body_raw);
                        $out->execute(); $out->close();
                    }
                } else { $fails++; $fail_list[] = $r['email']; }
            }

            if ($sent > 0) {
                $send_success = "Email sent to <strong>{$sent}</strong> recipient(s)" . ($fails > 0 ? " ({$fails} failed)" : '') . ".";
            } else {
                $send_error = "No emails were sent. Failed: " . htmlspecialchars(implode(', ', $fail_list));
            }
        }
    }
}

$mode_labels = ['reply' => 'Reply', 'reply_all' => 'Reply All', 'forward' => 'Forward'];
$mode_label  = $mode_labels[$mode] ?? 'Compose';
$page_title  = $mode_label . ' — ' . htmlspecialchars($notif['title']);

$reply_all_to_display = implode(', ', array_map(function($r) {
    return ($r['name'] !== $r['email']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'];
}, $reply_all_recipients));

$mode_color = $mode === 'forward' ? '#d97706' : ($mode === 'reply_all' ? '#16a34a' : '#2563eb');

$extra_css = '
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── Variables ── */
:root {
    --bg:        #f0f2f5;
    --surface:   #ffffff;
    --border:    #e2e6ea;
    --border2:   #d0d5dd;
    --text:      #1a1d23;
    --text2:     #4a5568;
    --text3:     #8a94a6;
    --blue:      #2563eb;
    --blue-lt:   #eff4ff;
    --blue-h:    #1d4ed8;
    --green:     #16a34a;
    --green-lt:  #f0fdf4;
    --red:       #dc2626;
    --red-lt:    #fef2f2;
    --yellow-lt: #fffbeb;
    --yellow:    #d97706;
    --shadow:    0 1px 3px rgba(0,0,0,.08);
    --sb-w:      260px;
}

/* ── Base ── */
* { box-sizing: border-box; }

/* ── The .main-content wrapper (set by header.php / layout) ── */
/* We need it to stretch full height without extra padding */
.main-content {
    padding: 0 !important;
    display: flex;
    flex-direction: column;
    min-height: calc(100vh - 56px); /* 56px = header height */
    background: var(--bg);
}

/* ══════════════════════════════════════════
   TOP BAR
══════════════════════════════════════════ */
.cr-topbar {
    display: flex;
    align-items: center;
    gap: 10px;
    height: 46px;
    padding: 0 18px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
    position: sticky;
    top: 0;
    z-index: 90;
    flex-shrink: 0;
}
.cr-topbar-brand {
    display: flex; align-items: center; gap: 7px;
    font-size: 13px; font-weight: 700; color: var(--text);
    text-decoration: none; letter-spacing: -.2px;
    white-space: nowrap;
}
.cr-topbar-brand i { color: var(--blue); font-size: 14px; }
.cr-topbar-brand:hover { color: var(--blue); }
.cr-topbar-divider { width: 1px; height: 18px; background: var(--border); flex-shrink: 0; }
.cr-topbar-title {
    font-size: 13px; font-weight: 600; color: var(--text2);
    flex: 1; min-width: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cr-topbar-right { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }

.cr-btn {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12.5px; font-weight: 500; font-family: inherit;
    padding: 5px 12px;
    border-radius: 7px; border: 1px solid var(--border);
    background: transparent; color: var(--text2);
    cursor: pointer; text-decoration: none;
    transition: background .12s, border-color .12s, color .12s;
    white-space: nowrap;
}
.cr-btn:hover   { background: var(--bg); border-color: var(--border2); color: var(--text); }
.cr-btn.primary { background: var(--blue); color: #fff; border-color: var(--blue); }
.cr-btn.primary:hover { background: var(--blue-h); border-color: var(--blue-h); }
.cr-btn.danger  { background: var(--red-lt); color: var(--red); border-color: #fca5a5; }
.cr-btn.danger:hover  { background: #fee2e2; }

/* ══════════════════════════════════════════
   MODE BADGE
══════════════════════════════════════════ */
.cr-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10.5px; font-weight: 700; letter-spacing: .4px;
    text-transform: uppercase; border-radius: 5px; padding: 3px 9px;
    white-space: nowrap;
}
.cr-badge.reply     { background: var(--blue-lt);   color: var(--blue);   }
.cr-badge.reply_all { background: var(--green-lt);  color: var(--green);  }
.cr-badge.forward   { background: var(--yellow-lt); color: var(--yellow); }

/* ══════════════════════════════════════════
   PAGE BODY  — two-column layout
   Left: compose  |  Right: sidebar
══════════════════════════════════════════ */
.cr-page {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr var(--sb-w);
    grid-template-rows: auto 1fr;
    min-height: 0;
    /* grid areas:
       [strip]   [sbhead]
       [compose] [sidebar]
    */
}

/* ── Strip row: thread info (left) aligned with sidebar header (right) ── */
.cr-strip {
    grid-column: 1;
    grid-row: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    height: 52px;
    padding: 0 18px;
    background: #f8f9fb;
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
    overflow: hidden;
}
.cr-strip-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    color: #fff; font-size: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.cr-strip-meta { flex: 1; min-width: 0; }
.cr-strip-name { font-size: 13px; font-weight: 700; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cr-strip-sub  { font-size: 11px; color: var(--text3); }
.cr-strip-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.cr-icon-btn {
    width: 26px; height: 26px; border-radius: 6px;
    background: none; border: 1px solid var(--border);
    color: var(--text3); cursor: pointer; font-size: 11px;
    display: inline-flex; align-items: center; justify-content: center;
    text-decoration: none; transition: all .12s;
}
.cr-icon-btn:hover       { background: var(--bg); color: var(--text2); border-color: var(--border2); }
.cr-icon-btn.ib-active   { background: var(--blue-lt);   color: var(--blue);   border-color: #bfdbfe; }
.cr-icon-btn.ib-fwd      { background: var(--yellow-lt); color: var(--yellow); border-color: #fde68a; }

/* ── Sidebar header (right column, row 1) ── */
.cr-sbhead {
    grid-column: 2;
    grid-row: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 52px;
    padding: 0 14px;
    background: #eff1f4;
    border-bottom: 1px solid var(--border);
    border-left: 1px solid var(--border);
}
.cr-sbhead-title {
    font-size: 10.5px; font-weight: 800;
    letter-spacing: .7px; text-transform: uppercase;
    color: var(--text2);
}

/* ── Compose column (left, row 2) ── */
.cr-compose {
    grid-column: 1;
    grid-row: 2;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border-right: 1px solid var(--border);
    overflow-y: auto;
    min-width: 0;
    min-height: 0;
}

/* ── Sidebar (right, row 2) ── */
.cr-sidebar {
    grid-column: 2;
    grid-row: 2;
    display: flex;
    flex-direction: column;
    background: #f8f9fb;
    overflow-y: auto;
    min-height: 0;
    border-left: 1px solid var(--border);
}

/* ── Alerts ── */
.cr-alert {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 18px; font-size: 13px; flex-shrink: 0;
}
.cr-alert-success { background: var(--green-lt); color: #15803d; border-bottom: 1px solid #bbf7d0; }
.cr-alert-error   { background: var(--red-lt);   color: var(--red);  border-bottom: 1px solid #fecaca; }
.cr-alert i { font-size: 14px; flex-shrink: 0; }

/* ── Form fields ── */
.cr-field {
    display: flex;
    align-items: flex-start;
    border-bottom: 1px solid var(--border);
    min-height: 42px;
    flex-shrink: 0;
}
.cr-field-lbl {
    font-size: 11.5px; font-weight: 700; color: var(--text3);
    padding: 11px 14px; min-width: 72px; flex-shrink: 0;
    border-right: 1px solid var(--border);
    display: flex; align-items: center;
}
.cr-field-lbl .req { color: var(--red); margin-left: 2px; }
.cr-field-input {
    flex: 1; font-size: 13.5px; font-family: inherit;
    color: var(--text); border: none; outline: none;
    padding: 10px 14px; background: transparent; width: 100%;
}
.cr-field-input::placeholder { color: var(--text3); }
.cr-field-input:focus { background: #fafbff; }
.cr-sel-wrap { flex: 1; position: relative; }
.cr-sel-wrap::after {
    content: "\f078"; font-family: "Font Awesome 6 Free"; font-weight: 900;
    position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
    font-size: 9px; color: var(--text3); pointer-events: none;
}
.cr-tpl-sel {
    width: 100%; font-size: 13px; font-family: inherit;
    color: var(--text); border: none; outline: none;
    padding: 10px 14px; background: transparent; cursor: pointer;
    appearance: none; -webkit-appearance: none;
}

/* ── Toolbar ── */
.cr-toolbar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 2px;
    padding: 5px 10px; background: #f8f9fb;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.cr-tb-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 27px; height: 25px; padding: 0 5px;
    font-size: 12px; color: var(--text2);
    background: transparent; border: 1px solid transparent;
    border-radius: 4px; cursor: pointer; font-family: inherit;
    transition: background .1s;
}
.cr-tb-btn:hover { background: var(--border); border-color: var(--border2); }
.cr-tb-sep { width: 1px; height: 15px; background: var(--border); margin: 0 3px; flex-shrink: 0; }
.cr-tb-sel {
    font-size: 11.5px; color: var(--text2); background: transparent;
    border: 1px solid var(--border); border-radius: 4px;
    padding: 2px 5px; cursor: pointer; outline: none;
    height: 25px; font-family: inherit;
}
.cr-font-sel { width: 88px; }
.cr-size-sel { width: 40px; }

/* ── Editor ── */
.cr-editor-wrap { position: relative; }
.cr-editor {
    min-height: 280px;
    padding: 15px 18px;
    font-size: 14px; line-height: 1.75; color: var(--text);
    outline: none; font-family: inherit; word-break: break-word;
}
.cr-editor:empty::before { content: attr(data-placeholder); color: var(--text3); pointer-events: none; }
.cr-editor:focus { background: #fafbff; }

/* ── Quoted ── */
.cr-quoted { border-top: 2px solid var(--border); background: #f8f9fb; flex-shrink: 0; }
.cr-quoted-hd {
    display: flex; align-items: center; gap: 7px;
    padding: 8px 18px; font-size: 12px; font-weight: 600; color: var(--text3);
    border-bottom: 1px solid var(--border);
}
.cr-quoted-toggle {
    margin-left: auto; background: none; border: none;
    font-size: 12px; font-weight: 700; color: var(--blue);
    cursor: pointer; font-family: inherit;
}
.cr-quoted-body {
    margin: 10px 18px 12px; padding: 10px 14px;
    font-size: 13px; line-height: 1.65; color: var(--text2);
    white-space: pre-wrap; word-break: break-word;
    border-left: 3px solid #cbd5e0;
    border-radius: 0 4px 4px 0; background: #fff;
}
.cr-quoted-meta { font-size: 11.5px; color: var(--text3); margin-bottom: 6px; }

/* ── Options bar ── */
.cr-opts {
    display: flex; align-items: center; flex-wrap: wrap; gap: 14px;
    padding: 9px 18px; background: #f8f9fb;
    border-top: 1px solid var(--border); flex-shrink: 0;
}
.cr-opt-lbl { font-size: 11.5px; font-weight: 700; color: var(--text3); white-space: nowrap; }
.cr-opt-sel {
    font-size: 12.5px; color: var(--text);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 6px; padding: 5px 26px 5px 10px;
    cursor: pointer; outline: none;
    appearance: none; -webkit-appearance: none;
    font-family: inherit;
}
.cr-chk-row { display: flex; align-items: center; gap: 7px; cursor: pointer; user-select: none; }
.cr-chk-row input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--blue); cursor: pointer; }
.cr-chk-lbl { font-size: 13px; color: var(--text2); }

/* ── Attachments ── */
.cr-attach-bar {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 18px; border-top: 1px solid var(--border); flex-shrink: 0;
}
.cr-attach-lbl { font-size: 13px; font-weight: 600; color: var(--text2); }
.cr-attach-add {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 12.5px; font-weight: 600; color: var(--blue);
    background: var(--blue-lt); border: 1px solid #bfdbfe;
    border-radius: 5px; padding: 4px 10px; cursor: pointer;
    transition: background .13s; font-family: inherit;
}
.cr-attach-add:hover { background: #dbeafe; }
.cr-attach-chev {
    display: inline-flex; align-items: center;
    font-size: 12px; color: var(--text3);
    background: none; border: none; cursor: pointer; padding: 4px 5px;
}
.cr-dropzone {
    margin: 0 18px 12px; border: 2px dashed var(--border);
    border-radius: 8px; padding: 20px 18px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    font-size: 13px; color: var(--text3); cursor: pointer; transition: all .15s;
}
.cr-dropzone:hover, .cr-drag-over { border-color: var(--blue); background: var(--blue-lt); color: var(--blue); }
.cr-file-list { padding: 0 18px 10px; display: flex; flex-direction: column; gap: 4px; }
.cr-file-item {
    display: flex; align-items: center; gap: 8px;
    font-size: 12.5px; color: var(--text2);
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 6px; padding: 5px 10px;
}
.cr-file-rm { background: none; border: none; color: var(--text3); cursor: pointer; padding: 0 2px; font-size: 12px; margin-left: auto; }
.cr-file-rm:hover { color: var(--red); }

/* ── Footer ── */
.cr-footer {
    display: flex; align-items: center; gap: 10px;
    padding: 13px 18px; border-top: 2px solid var(--border);
    background: var(--surface); flex-shrink: 0;
}

/* ══════════════════════════════════════════
   SIDEBAR INTERNALS
══════════════════════════════════════════ */
.cr-sb-section { border-bottom: 1px solid var(--border); }
.cr-sb-sec-hd {
    display: flex; align-items: center; justify-content: space-between;
    padding: 9px 14px;
    font-size: 10.5px; font-weight: 800; letter-spacing: .6px;
    text-transform: uppercase; color: var(--text2);
    background: #eff1f4; cursor: pointer;
    border-bottom: 1px solid var(--border);
}
.cr-sb-sec-hd i { font-size: 9px; color: var(--text3); transition: transform .2s; }
.cr-sb-sec-body { background: var(--surface); }
.cr-sb-row {
    display: grid; grid-template-columns: 76px 1fr; gap: 5px;
    padding: 7px 14px; border-bottom: 1px solid #f0f4f8;
    align-items: start;
}
.cr-sb-row:last-child { border-bottom: none; }
.cr-sb-lbl { font-size: 10px; font-weight: 700; letter-spacing: .4px; color: var(--text3); padding-top: 2px; }
.cr-sb-val { font-size: 12px; color: var(--text); font-weight: 500; word-break: break-word; }
.cr-sb-link { color: var(--blue); font-size: 11.5px; font-weight: 600; text-decoration: none; }
.cr-sb-link:hover { text-decoration: underline; }

/* Quick-switch links */
.cr-sw-link {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; border-radius: 6px; margin: 2px 6px;
    text-decoration: none; font-size: 12.5px; font-weight: 600;
    transition: background .1s;
}

/* ── States ── */
.cr-sending { opacity: .6; pointer-events: none; }
.cr-spin { animation: _spin .7s linear infinite; display: inline-block; }
@keyframes _spin { to { transform: rotate(360deg); } }

/* ── Responsive ── */
@media (max-width: 860px) {
    .cr-page { grid-template-columns: 1fr; grid-template-rows: auto auto auto auto; }
    .cr-strip  { grid-column: 1; grid-row: 1; border-right: none; }
    .cr-sbhead { grid-column: 1; grid-row: 2; border-left: none; }
    .cr-compose{ grid-column: 1; grid-row: 3; border-right: none; }
    .cr-sidebar{ grid-column: 1; grid-row: 4; border-left: none; border-top: 1px solid var(--border); }
}
</style>';

include '../../includes/header.php';
?>

<div class="main-content">

<!-- ══ TOP BAR ══════════════════════════════════════════════════════════ -->
<div class="cr-topbar">
    <a href="notification-detail.php?id=<?= $notif_id ?>" class="cr-topbar-brand">
        <i class="fas fa-arrow-left"></i>Back to Notification
    </a>
    <div class="cr-topbar-divider"></div>
    <span class="cr-topbar-title"><?= htmlspecialchars($notif['title']) ?></span>
    <div class="cr-topbar-right">
        <span class="cr-badge <?= htmlspecialchars($mode) ?>">
            <?php if ($mode === 'reply'): ?><i class="fas fa-reply"></i>
            <?php elseif ($mode === 'reply_all'): ?><i class="fas fa-reply-all"></i>
            <?php else: ?><i class="fas fa-share"></i><?php endif; ?>
            <?= htmlspecialchars($mode_label) ?>
        </span>
        <a href="notification-detail.php?id=<?= $notif_id ?>" class="cr-btn danger">
            <i class="fas fa-times"></i> Cancel
        </a>
    </div>
</div>

<!-- ══ PAGE BODY (2-col grid) ════════════════════════════════════════════ -->
<div class="cr-page">

    <!-- ── Row 1 left: Thread strip ── -->
    <div class="cr-strip">
        <div class="cr-strip-avatar" style="background:<?= $mode_color ?>;">
            <?php if ($mode === 'reply'): ?><i class="fas fa-reply"></i>
            <?php elseif ($mode === 'reply_all'): ?><i class="fas fa-reply-all" style="font-size:10px;"></i>
            <?php else: ?><i class="fas fa-share"></i><?php endif; ?>
        </div>
        <div class="cr-strip-meta">
            <div class="cr-strip-name"><?= htmlspecialchars($notif['title']) ?></div>
            <div class="cr-strip-sub">NTF-<?= str_pad($notif_id, 4, '0', STR_PAD_LEFT) ?> &middot; <?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?></div>
        </div>
        <div class="cr-strip-actions">
            <a href="compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply&to_email=<?= urlencode($to_email) ?>&to_name=<?= urlencode($to_name) ?>&reply_id=<?= $reply_id ?>"
               class="cr-icon-btn <?= $mode==='reply' ? 'ib-active' : '' ?>" title="Reply"><i class="fas fa-reply"></i></a>
            <a href="compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&to_email=<?= urlencode($to_email) ?>&to_name=<?= urlencode($to_name) ?>&reply_id=<?= $reply_id ?>"
               class="cr-icon-btn <?= $mode==='reply_all' ? 'ib-active' : '' ?>" title="Reply All"><i class="fas fa-reply-all"></i></a>
            <a href="compose-reply.php?notif_id=<?= $notif_id ?>&mode=forward&reply_id=<?= $reply_id ?>"
               class="cr-icon-btn <?= $mode==='forward' ? 'ib-fwd' : '' ?>" title="Forward"><i class="fas fa-share"></i></a>
        </div>
    </div>

    <!-- ── Row 1 right: Sidebar header ── -->
    <div class="cr-sbhead">
        <span class="cr-sbhead-title">Ticket Info</span>
        <span class="cr-badge <?= htmlspecialchars($mode) ?>" style="font-size:9.5px;">
            <?= htmlspecialchars($mode_label) ?>
        </span>
    </div>

    <!-- ══════════════════════════════════════
         Row 2 left: COMPOSE COLUMN
    ══════════════════════════════════════ -->
    <div class="cr-compose">

        <?php if ($send_success): ?>
        <div class="cr-alert cr-alert-success">
            <i class="fas fa-check-circle"></i> <?= $send_success ?>
            <a href="notification-detail.php?id=<?= $notif_id ?>" style="margin-left:auto;font-weight:700;color:#15803d;">← Back</a>
        </div>
        <?php endif; ?>
        <?php if ($send_error): ?>
        <div class="cr-alert cr-alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($send_error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" id="cr-form">
            <input type="hidden" name="compose_send" value="1">
            <input type="hidden" name="post_mode" value="<?= htmlspecialchars($mode) ?>">

            <!-- To -->
            <div class="cr-field">
                <label class="cr-field-lbl">To <span class="req">*</span></label>
                <?php if ($mode === 'reply_all'): ?>
                <div style="flex:1;padding:10px 14px;font-size:13px;color:var(--text2);background:#f8f9fb;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-users" style="font-size:11px;color:var(--text3);"></i>
                    <em style="color:var(--text3);">All inbound senders</em>
                    <?php if (!empty($reply_all_recipients)): ?>
                    <span style="font-size:11.5px;color:var(--blue);font-weight:600;">(<?= count($reply_all_recipients) ?> recipient<?= count($reply_all_recipients)>1?'s':'' ?>)</span>
                    <?php endif; ?>
                    <input type="hidden" name="post_to" value="<?= htmlspecialchars($reply_all_to_display) ?>">
                </div>
                <?php else: ?>
                <input type="text" name="post_to" id="cr-to" class="cr-field-input"
                       value="<?= htmlspecialchars($mode === 'forward' ? '' : $to_email) ?>"
                       placeholder="Recipient email address…" autocomplete="email">
                <?php endif; ?>
            </div>

            <!-- Cc -->
            <div class="cr-field">
                <label class="cr-field-lbl">Cc</label>
                <input type="text" name="post_cc" class="cr-field-input" placeholder="CC email addresses (comma-separated)…">
            </div>

            <!-- Bcc (hidden by default) -->
            <div class="cr-field" id="cr-bcc-row" style="display:none;">
                <label class="cr-field-lbl">Bcc</label>
                <input type="text" name="post_bcc" class="cr-field-input" placeholder="BCC email addresses (comma-separated)…">
            </div>

            <!-- Template + Bcc toggle -->
            <div class="cr-field">
                <label class="cr-field-lbl">Template</label>
                <div class="cr-sel-wrap">
                    <select class="cr-tpl-sel" onchange="crApplyTemplate(this.value)">
                        <option value="">Default Reply Template</option>
                        <option value="acknowledged">Complaint Acknowledged</option>
                        <option value="resolved">Issue Resolved</option>
                        <option value="followup">Follow-Up Required</option>
                        <option value="noaction">No Action Needed</option>
                    </select>
                </div>
                <button type="button"
                        onclick="document.getElementById('cr-bcc-row').style.display='flex'"
                        style="background:none;border:none;font-size:12px;color:var(--text3);cursor:pointer;padding:0 12px;white-space:nowrap;font-family:inherit;">
                    + Bcc
                </button>
            </div>

            <!-- Subject -->
            <div class="cr-field">
                <label class="cr-field-lbl">Subject <span class="req">*</span></label>
                <input type="text" name="post_subject" id="cr-subject" class="cr-field-input"
                       value="<?= htmlspecialchars($subject) ?>">
            </div>

            <!-- Rich-text toolbar -->
            <div class="cr-toolbar">
                <button type="button" class="cr-tb-btn" style="font-weight:700;" onclick="crExec('bold')" title="Bold"><b>B</b></button>
                <button type="button" class="cr-tb-btn" style="font-style:italic;" onclick="crExec('italic')" title="Italic"><i>I</i></button>
                <button type="button" class="cr-tb-btn" style="text-decoration:underline;" onclick="crExec('underline')" title="Underline"><u>U</u></button>
                <button type="button" class="cr-tb-btn" style="text-decoration:line-through;" onclick="crExec('strikethrough')" title="Strike"><s>S</s></button>
                <div class="cr-tb-sep"></div>
                <select class="cr-tb-sel cr-font-sel" onchange="crExec('fontName',this.value)">
                    <option value="DM Sans" selected>DM Sans</option>
                    <option value="Arial">Arial</option>
                    <option value="Georgia">Georgia</option>
                    <option value="Courier New">Courier New</option>
                    <option value="Times New Roman">Times New Roman</option>
                </select>
                <select class="cr-tb-sel cr-size-sel" onchange="crExec('fontSize',this.value)">
                    <option value="1">8</option><option value="2">10</option>
                    <option value="3" selected>12</option><option value="4">14</option>
                    <option value="5">18</option><option value="6">24</option>
                </select>
                <div class="cr-tb-sep"></div>
                <button type="button" class="cr-tb-btn" onclick="crExec('justifyLeft')"   title="Left"><i class="fas fa-align-left"></i></button>
                <button type="button" class="cr-tb-btn" onclick="crExec('justifyCenter')" title="Center"><i class="fas fa-align-center"></i></button>
                <button type="button" class="cr-tb-btn" onclick="crExec('justifyRight')"  title="Right"><i class="fas fa-align-right"></i></button>
                <div class="cr-tb-sep"></div>
                <button type="button" class="cr-tb-btn" onclick="crExec('insertUnorderedList')" title="Bullets"><i class="fas fa-list-ul"></i></button>
                <button type="button" class="cr-tb-btn" onclick="crExec('insertOrderedList')"   title="Numbers"><i class="fas fa-list-ol"></i></button>
                <div class="cr-tb-sep"></div>
                <button type="button" class="cr-tb-btn" onclick="crInsertLink()" title="Link"><i class="fas fa-link"></i></button>
                <button type="button" class="cr-tb-btn" onclick="crExec('removeFormat')" title="Clear formatting"><i class="fas fa-remove-format"></i></button>
                <button type="button" class="cr-tb-btn" onclick="crToggleFullscreen()" id="cr-fs-btn" title="Fullscreen"><i class="fas fa-expand-alt"></i></button>
            </div>

            <!-- Contenteditable editor -->
            <div class="cr-editor-wrap" id="cr-editor-wrap">
                <div class="cr-editor" id="cr-editor"
                     contenteditable="true"
                     data-placeholder="Write your message here…"
                     oninput="crSync()"></div>
                <textarea name="post_body_text" id="cr-body-hidden" style="display:none;"></textarea>
            </div>

            <!-- Quoted message -->
            <?php if ($quoted_body): ?>
            <div class="cr-quoted">
                <div class="cr-quoted-hd">
                    <i class="fas <?= $mode==='forward'?'fa-share':'fa-reply' ?>"></i>
                    <?= $mode==='forward' ? 'Forwarded message' : 'Original message' ?>
                    <button type="button" class="cr-quoted-toggle" id="cr-qt-toggle">Hide</button>
                </div>
                <div class="cr-quoted-body" id="cr-qt-body">
                    <div class="cr-quoted-meta">
                        <?php if ($quoted_from): ?>From: <?= htmlspecialchars($quoted_from) ?><br><?php endif; ?>
                        <?php if ($quoted_time): ?>Date: <?= htmlspecialchars($quoted_time) ?><?php endif; ?>
                    </div>
                    <?= nl2br(htmlspecialchars($quoted_body)) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Options bar -->
            <div class="cr-opts">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="cr-opt-lbl">Request Status</span>
                    <select name="post_status" class="cr-opt-sel">
                        <option value="work_in_progress">Work In Progress</option>
                        <option value="sent">Sent</option>
                        <option value="resolved">Resolved</option>
                        <option value="on_hold">On Hold</option>
                    </select>
                </div>
                <label class="cr-chk-row">
                    <input type="checkbox" name="show_requester" checked>
                    <span class="cr-chk-lbl">Show this mail to requester also</span>
                </label>
            </div>

            <!-- Attachments -->
            <div class="cr-attach-bar">
                <span class="cr-attach-lbl"><i class="fas fa-paperclip" style="color:var(--text3);margin-right:4px;"></i>Attachments</span>
                <button type="button" class="cr-attach-add" onclick="document.getElementById('cr-file-input').click()">
                    <i class="fas fa-plus"></i> Add
                </button>
                <button type="button" class="cr-attach-chev" onclick="crToggleDz()">
                    <i class="fas fa-chevron-down" id="cr-dz-caret"></i>
                </button>
                <input type="file" id="cr-file-input" name="post_attachments[]" multiple style="display:none;" onchange="crShowFiles(this)">
            </div>
            <div id="cr-dz-wrap" style="display:none;">
                <div class="cr-dropzone" id="cr-dropzone"
                     onclick="document.getElementById('cr-file-input').click()"
                     ondragover="event.preventDefault();this.classList.add('cr-drag-over')"
                     ondragleave="this.classList.remove('cr-drag-over')"
                     ondrop="crHandleDrop(event)">
                    <i class="fas fa-cloud-upload-alt" style="font-size:15px;"></i>
                    <span>Drag and drop files here, or click to browse</span>
                </div>
                <div class="cr-file-list" id="cr-file-list"></div>
            </div>

            <!-- Footer -->
            <div class="cr-footer">
                <button type="submit" class="cr-btn primary" id="cr-send-btn" onclick="crSync()">
                    <i class="fas fa-paper-plane"></i> Send
                </button>
                <button type="button" class="cr-btn" onclick="crSaveDraft()">
                    <i class="fas fa-save"></i> Save Draft
                </button>
                <a href="notification-detail.php?id=<?= $notif_id ?>" class="cr-btn" style="margin-left:auto;">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>

        </form>
    </div><!-- /.cr-compose -->

    <!-- ══════════════════════════════════════
         Row 2 right: SIDEBAR
    ══════════════════════════════════════ -->
    <div class="cr-sidebar">

        <!-- Ticket Info -->
        <div class="cr-sb-section">
            <div class="cr-sb-sec-hd" onclick="crToggleSb(this)">
                TICKET INFO <i class="fas fa-chevron-up"></i>
            </div>
            <div class="cr-sb-sec-body">
                <div class="cr-sb-row">
                    <div class="cr-sb-lbl">ID</div>
                    <div class="cr-sb-val" style="font-family:'DM Mono',monospace;font-size:11.5px;">NTF-<?= str_pad($notif_id,4,'0',STR_PAD_LEFT) ?></div>
                </div>
                <div class="cr-sb-row">
                    <div class="cr-sb-lbl">STATUS</div>
                    <div class="cr-sb-val">
                        <span style="display:inline-flex;align-items:center;gap:4px;">
                            <span style="width:7px;height:7px;border-radius:50%;background:#48bb78;display:inline-block;"></span>
                            <?= $notif['is_read'] ? 'Read' : 'Unread' ?>
                        </span>
                    </div>
                </div>
                <div class="cr-sb-row">
                    <div class="cr-sb-lbl">MODE</div>
                    <div class="cr-sb-val">
                        <span class="cr-badge <?= htmlspecialchars($mode) ?>" style="font-size:9.5px;"><?= htmlspecialchars($mode_label) ?></span>
                    </div>
                </div>
                <div class="cr-sb-row">
                    <div class="cr-sb-lbl">RECEIVED</div>
                    <div class="cr-sb-val" style="font-size:11.5px;color:var(--text3);"><?= date('M j, Y g:i A', strtotime($notif['created_at'])) ?></div>
                </div>
                <?php if ($to_email): ?>
                <div class="cr-sb-row">
                    <div class="cr-sb-lbl">TO</div>
                    <div class="cr-sb-val" style="font-size:11.5px;">
                        <?= htmlspecialchars($to_name) ?><br>
                        <span style="color:var(--text3);font-size:10.5px;"><?= htmlspecialchars($to_email) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reply All Recipients -->
        <?php if ($mode === 'reply_all' && !empty($reply_all_recipients)): ?>
        <div class="cr-sb-section">
            <div class="cr-sb-sec-hd" onclick="crToggleSb(this)">
                RECIPIENTS (<?= count($reply_all_recipients) ?>) <i class="fas fa-chevron-up"></i>
            </div>
            <div class="cr-sb-sec-body" style="padding:0;">
                <?php foreach ($reply_all_recipients as $r): ?>
                <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;border-bottom:1px solid var(--border);">
                    <div style="width:24px;height:24px;border-radius:50%;background:var(--blue);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <?= strtoupper(substr($r['name'],0,1)) ?>
                    </div>
                    <div>
                        <div style="font-size:12px;font-weight:600;color:var(--text);"><?= htmlspecialchars($r['name']) ?></div>
                        <div style="font-size:10.5px;color:var(--text3);"><?= htmlspecialchars($r['email']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Switch -->
        <div class="cr-sb-section">
            <div class="cr-sb-sec-hd" onclick="crToggleSb(this)">
                QUICK SWITCH <i class="fas fa-chevron-up"></i>
            </div>
            <div class="cr-sb-sec-body" style="padding:6px 0;">
                <a href="compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply&to_email=<?= urlencode($to_email) ?>&to_name=<?= urlencode($to_name) ?>&reply_id=<?= $reply_id ?>"
                   class="cr-sw-link" style="color:<?= $mode==='reply'?'var(--blue)':'var(--text2)' ?>;background:<?= $mode==='reply'?'var(--blue-lt)':'transparent' ?>;">
                    <i class="fas fa-reply" style="width:13px;font-size:11px;color:<?= $mode==='reply'?'var(--blue)':'var(--text3)' ?>;"></i> Reply
                </a>
                <a href="compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&to_email=<?= urlencode($to_email) ?>&to_name=<?= urlencode($to_name) ?>&reply_id=<?= $reply_id ?>"
                   class="cr-sw-link" style="color:<?= $mode==='reply_all'?'var(--green)':'var(--text2)' ?>;background:<?= $mode==='reply_all'?'var(--green-lt)':'transparent' ?>;">
                    <i class="fas fa-reply-all" style="width:13px;font-size:11px;color:<?= $mode==='reply_all'?'var(--green)':'var(--text3)' ?>;"></i> Reply All
                </a>
                <a href="compose-reply.php?notif_id=<?= $notif_id ?>&mode=forward&reply_id=<?= $reply_id ?>"
                   class="cr-sw-link" style="color:<?= $mode==='forward'?'var(--yellow)':'var(--text2)' ?>;background:<?= $mode==='forward'?'var(--yellow-lt)':'transparent' ?>;">
                    <i class="fas fa-share" style="width:13px;font-size:11px;color:<?= $mode==='forward'?'var(--yellow)':'var(--text3)' ?>;"></i> Forward
                </a>
            </div>
        </div>

    </div><!-- /.cr-sidebar -->

</div><!-- /.cr-page -->

</div><!-- /.main-content -->

<script>
/* ── Editor helpers ── */
function crExec(cmd, val) { document.getElementById('cr-editor').focus(); document.execCommand(cmd, false, val||null); }
function crSync() {
    var e = document.getElementById('cr-editor'), h = document.getElementById('cr-body-hidden');
    if (e && h) h.value = e.innerText.trim();
}
function crInsertLink() { var u = prompt('Enter URL:'); if (u) crExec('createLink', u); }

var _fs = false;
function crToggleFullscreen() {
    var w = document.getElementById('cr-editor-wrap'), b = document.getElementById('cr-fs-btn');
    _fs = !_fs;
    if (_fs) {
        w.style.cssText = 'position:fixed;inset:0;z-index:9999;background:#fff;display:flex;flex-direction:column;';
        document.getElementById('cr-editor').style.cssText = 'flex:1;min-height:unset;max-height:unset;padding:24px;font-size:15px;';
        b.innerHTML = '<i class="fas fa-compress-alt"></i>';
    } else {
        w.style.cssText = ''; document.getElementById('cr-editor').style.cssText = '';
        b.innerHTML = '<i class="fas fa-expand-alt"></i>';
    }
}

/* ── Quoted toggle ── */
document.addEventListener('DOMContentLoaded', function () {
    var tgl = document.getElementById('cr-qt-toggle'), bdy = document.getElementById('cr-qt-body');
    if (tgl && bdy) tgl.addEventListener('click', function () {
        var h = bdy.style.display === 'none';
        bdy.style.display = h ? '' : 'none';
        tgl.textContent   = h ? 'Hide' : 'Show';
    });
    var form = document.getElementById('cr-form');
    if (form) form.addEventListener('submit', function () {
        crSync();
        var btn = document.getElementById('cr-send-btn');
        btn.innerHTML = '<i class="fas fa-circle-notch cr-spin"></i> Sending…';
        btn.disabled = true;
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && _fs) crToggleFullscreen(); });
});

/* ── Dropzone ── */
var _dz = false;
function crToggleDz() {
    _dz = !_dz;
    document.getElementById('cr-dz-wrap').style.display = _dz ? '' : 'none';
    document.getElementById('cr-dz-caret').className = _dz ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
}
function crShowFiles(input) {
    var list = document.getElementById('cr-file-list'); list.innerHTML = '';
    Array.from(input.files).forEach(function (f) {
        var icon = 'fa-file';
        if (f.type.startsWith('image/'))       icon = 'fa-file-image';
        else if (f.type === 'application/pdf') icon = 'fa-file-pdf';
        else if (f.type.includes('word'))      icon = 'fa-file-word';
        else if (f.type.includes('excel')||f.type.includes('spreadsheet')) icon = 'fa-file-excel';
        var el = document.createElement('div'); el.className = 'cr-file-item';
        el.innerHTML = '<i class="fas '+icon+'" style="color:var(--text3);"></i><span>'+f.name+'</span>'
            +'<span style="margin-left:auto;color:var(--text3);font-size:11px;">'+(f.size/1024).toFixed(1)+' KB</span>'
            +'<button class="cr-file-rm" type="button" onclick="this.closest(\'.cr-file-item\').remove()" title="Remove"><i class="fas fa-times"></i></button>';
        list.appendChild(el);
    });
    if (input.files.length) { _dz = true; document.getElementById('cr-dz-wrap').style.display = ''; }
}
function crHandleDrop(e) {
    e.preventDefault(); document.getElementById('cr-dropzone').classList.remove('cr-drag-over');
    var dt = e.dataTransfer; if (dt && dt.files.length) { document.getElementById('cr-file-input').files = dt.files; crShowFiles(document.getElementById('cr-file-input')); }
}

/* ── Templates ── */
var _tpls = {
    acknowledged: { s:'Your Concern Has Been Acknowledged', b:'Dear Resident,\n\nWe have received your concern and would like to inform you that it has been acknowledged by the Barangay office. Our team is currently reviewing the matter and will provide an update as soon as possible.\n\nThank you for bringing this to our attention.\n\nSincerely,\nBarangay Office' },
    resolved:     { s:'Resolution Notice \u2013 Issue Resolved',  b:'Dear Resident,\n\nWe are pleased to inform you that your concern has been resolved. The Barangay has taken the necessary action to address the matter.\n\nIf you have further questions or concerns, please do not hesitate to contact our office.\n\nSincerely,\nBarangay Office' },
    followup:     { s:'Follow-Up Required \u2013 Barangay Office', b:'Dear Resident,\n\nThis is to inform you that your concern is still under review and requires further follow-up. We appreciate your patience and cooperation.\n\nOur office will contact you shortly with an update.\n\nSincerely,\nBarangay Office' },
    noaction:     { s:'Notice \u2013 No Further Action Required',  b:'Dear Resident,\n\nAfter careful review of your concern, the Barangay has determined that no further action is required at this time.\n\nShould you have any additional information or a new concern, please feel free to reach out to our office.\n\nSincerely,\nBarangay Office' }
};
function crApplyTemplate(k) {
    if (!k || !_tpls[k]) return;
    document.getElementById('cr-subject').value = _tpls[k].s;
    document.getElementById('cr-editor').innerText = _tpls[k].b;
    crSync();
}

/* ── Draft (placeholder) ── */
function crSaveDraft() { crSync(); alert('Draft saved (hook into your draft table here).'); }

/* ── Sidebar section collapse ── */
function crToggleSb(hd) {
    var body = hd.nextElementSibling, icon = hd.querySelector('i');
    if (!body) return;
    var vis = body.style.display !== 'none';
    body.style.display = vis ? 'none' : '';
    if (icon) icon.style.transform = vis ? 'rotate(180deg)' : '';
}
</script>

<?php include '../../includes/footer.php'; ?>