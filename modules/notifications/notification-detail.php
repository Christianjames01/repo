<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Notification Detail';
$user_id    = $_SESSION['user_id'];
$user_role  = getCurrentUserRole();

$notif_id = intval($_GET['id'] ?? 0);
if (!$notif_id) {
    $_SESSION['error_message'] = 'Invalid notification.';
    header('Location: index.php'); exit();
}

$notif = fetchOne($conn,
    "SELECT * FROM tbl_notifications WHERE notification_id = ? AND user_id = ?",
    [$notif_id, $user_id], 'ii'
);
if (!$notif) {
    $_SESSION['error_message'] = 'Notification not found or access denied.';
    header('Location: index.php'); exit();
}

if (!$notif['is_read']) {
    $stmt = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();
    $stmt->close();
    $notif['is_read'] = 1;
}

$rt = $notif['reference_type'] ?? '';
$t  = $notif['type'] ?? '';

$requester = fetchOne($conn, "SELECT * FROM tbl_users WHERE user_id = ?", [$user_id], 'i');
$req_fn    = $requester['firstname']  ?? $requester['first_name']  ?? '';
$req_ln    = $requester['lastname']   ?? $requester['last_name']   ?? '';
$req_name  = trim("$req_fn $req_ln") ?: ($requester['full_name'] ?? $requester['username'] ?? 'Unknown');
$req_email = $requester['email'] ?? '';
$req_phone = $requester['phone'] ?? $requester['contact_number'] ?? $requester['mobile'] ?? '';
$req_site  = $requester['site'] ?? $requester['barangay'] ?? $requester['address'] ?? '';

$email_history        = null;
$is_email_notif       = false;
$is_email_reply_notif = ($t === 'email_reply' || $rt === 'email_inbox');

if ($is_email_reply_notif) {
    $is_email_notif = true;
    $email_history  = [
        'sender_name'       => 'Barangay System',
        'sender_email'      => defined('MAIL_USERNAME') ? MAIL_USERNAME : '',
        'recipient_type'    => 'all_residents',
        'total_recipients'  => 0,
        'successful_sends'  => 0,
        'failed_sends'      => 0,
        'notification_type' => 'email_reply',
        'email_message'     => '',
        'action_url'        => '',
        'sent_at'           => $notif['created_at'],
    ];
}

$email_broadcast_types = ['general', 'announcement', 'alert', 'status_update'];
if (!$is_email_reply_notif && $rt === 'announcement' && in_array($t, $email_broadcast_types) && !empty($notif['reference_id'])) {
    $email_history = fetchOne($conn, "SELECT * FROM tbl_email_history WHERE id = ?", [$notif['reference_id']], 'i');
    if ($email_history) {
        $is_email_notif = true;
        if (!empty($email_history['sender_id'])) {
            $sender = fetchOne($conn, "SELECT * FROM tbl_users WHERE user_id = ?", [$email_history['sender_id']], 'i');
            if ($sender) {
                $fn = $sender['firstname'] ?? $sender['first_name'] ?? '';
                $ln = $sender['lastname']  ?? $sender['last_name']  ?? '';
                $email_history['sender_name']  = trim("$fn $ln") ?: ($sender['full_name'] ?? $sender['username'] ?? 'Administrator');
                $email_history['sender_email'] = $sender['email'] ?? '';
            }
        }
        if (empty($email_history['sender_name'])) {
            $email_history['sender_name'] = 'Administrator'; $email_history['sender_email'] = '';
        }
    }
}

$inbound_sender_name  = '';
$inbound_sender_email = '';
$inbound_attachments  = [];

if ($is_email_reply_notif && !empty($notif['reference_id'])) {
    $col_check = $conn->query("SHOW COLUMNS FROM tbl_email_replies LIKE 'attachments'");
    $has_attachments_col = ($col_check && $col_check->num_rows > 0);

    $select_cols = $has_attachments_col
        ? "from_name, from_email, attachments"
        : "from_name, from_email";

    $inbound = fetchOne($conn,
        "SELECT {$select_cols} FROM tbl_email_replies
         WHERE id = ? AND direction = 'inbound' LIMIT 1",
        [$notif['reference_id']], 'i'
    );

    if ($inbound) {
        $inbound_sender_name  = $inbound['from_name']  ?: ($inbound['from_email'] ?? '');
        $inbound_sender_email = $inbound['from_email'] ?? '';
        if ($has_attachments_col && !empty($inbound['attachments'])) {
            $decoded = json_decode($inbound['attachments'], true);
            if (is_array($decoded)) $inbound_attachments = $decoded;
        }
    }
}

if ($is_email_reply_notif && ($inbound_sender_name || $inbound_sender_email)) {
    $req_name  = $inbound_sender_name  ?: $inbound_sender_email;
    $req_email = $inbound_sender_email ?: '';
    $req_phone = '';
    $req_site  = '';
}

$icon_class = 'fa-bell';    $icon_bg = '#e67e22';
if ($is_email_reply_notif)                           { $icon_class = 'fa-envelope';            $icon_bg = '#3182ce'; }
elseif ($is_email_notif)                             { $icon_class = 'fa-bullhorn';             $icon_bg = '#2d3748'; }
elseif ($rt === 'blotter')                           { $icon_class = 'fa-gavel';               $icon_bg = '#dc3545'; }
elseif ($rt === 'incident')                          { $icon_class = 'fa-exclamation-triangle'; $icon_bg = '#e67e22'; }
elseif ($rt === 'complaint')                         { $icon_class = 'fa-comments';             $icon_bg = '#e67e22'; }
elseif ($rt === 'request' || $rt === 'document')     { $icon_class = 'fa-file-alt';             $icon_bg = '#0891b2'; }
elseif ($rt === 'appointment')                       {
    $icon_class = 'fa-calendar-check'; $icon_bg = '#198754';
    if (stripos($t, 'cancelled') !== false) { $icon_class = 'fa-calendar-times'; $icon_bg = '#dc3545'; }
}
elseif ($rt === 'medical_assistance')                { $icon_class = 'fa-hand-holding-medical'; $icon_bg = '#6f42c1'; }

if ($is_email_reply_notif)                       $type_label = 'Email Reply';
elseif ($is_email_notif)                         $type_label = 'Email Announcement';
elseif ($rt === 'incident')                      $type_label = 'Incident';
elseif ($rt === 'blotter')                       $type_label = 'Blotter';
elseif ($rt === 'complaint')                     $type_label = 'Complaint';
elseif ($rt === 'request' || $rt === 'document') $type_label = 'Document Request';
elseif ($rt === 'appointment')                   $type_label = 'Appointment';
elseif ($rt === 'medical_assistance')            $type_label = 'Medical Assistance';
else                                             $type_label = ucwords(str_replace('_', ' ', $t));

$view_url = null;
if (!$is_email_notif && !empty($notif['reference_id'])) {
    if ($rt === 'incident')                      $view_url = '../incidents/incident-details.php?id='   . intval($notif['reference_id']);
    elseif ($rt === 'blotter')                   $view_url = '../blotter/view-blotter.php?id='         . intval($notif['reference_id']);
    elseif ($rt === 'complaint')                 $view_url = '../complaints/complaint-details.php?id=' . intval($notif['reference_id']);
    elseif ($rt === 'request'||$rt==='document') $view_url = '../requests/view-request.php?id='        . intval($notif['reference_id']);
    elseif ($rt === 'appointment')               $view_url = '../health/appointments.php';
    elseif ($rt === 'medical_assistance')        $view_url = '../health/medical-assistance.php';
}

$created_at      = date('M j, Y g:i A', strtotime($notif['created_at']));
$created_at_full = date('D, M j, Y g:i A', strtotime($notif['created_at']));
$ref_id_display  = 'NTF-' . str_pad($notif_id, 4, '0', STR_PAD_LEFT);

$res_success = ''; $res_error = ''; $active_tab = $_GET['tab'] ?? 'conversations';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolution_action'])) {
    $is_admin = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');
    if ($is_admin) {
        $res_subject  = trim($_POST['res_subject'] ?? '');
        $res_body_raw = trim($_POST['res_body'] ?? '') ?: trim($_POST['res_body_text'] ?? '');
        $res_body_raw = strip_tags($res_body_raw);
        $res_to       = $_POST['res_recipient'] ?? 'all';

        if (!$res_subject)      { $res_error = 'Subject is required.'; $active_tab = 'resolution'; }
        elseif (!$res_body_raw) { $res_error = 'Message body is required.'; $active_tab = 'resolution'; }
        else {
            require_once '../../includes/email_helper.php';
            $resolveName = function($row) {
                $fn = $row['firstname'] ?? $row['first_name'] ?? '';
                $ln = $row['lastname']  ?? $row['last_name']  ?? '';
                return trim("$fn $ln") ?: ($row['full_name'] ?? $row['username'] ?? 'Resident');
            };
            $residents_list = [];
            if ($res_to === 'all') {
                $rq = $conn->query("SELECT * FROM tbl_users WHERE role = 'Resident' AND email IS NOT NULL AND email != ''");
                if ($rq) while ($row = $rq->fetch_assoc()) $residents_list[] = ['email' => $row['email'], 'full_name' => $resolveName($row)];
            } elseif ($res_to === 'sender') {
                if ($inbound_sender_email) {
                    $residents_list[] = ['email' => $inbound_sender_email, 'full_name' => $inbound_sender_name ?: 'Resident'];
                }
            } else {
                $ref_user = fetchOne($conn, "SELECT * FROM tbl_users WHERE user_id = ?", [intval($res_to)], 'i');
                if ($ref_user && !empty($ref_user['email'])) $residents_list[] = ['email' => $ref_user['email'], 'full_name' => $resolveName($ref_user)];
            }
            if (empty($residents_list)) { $res_error = 'No recipients found.'; $active_tab = 'resolution'; }
            else {
               $sent = 0; $fails = 0; $fail_list = [];
                foreach ($residents_list as $r) {
                        $body_html = getEmailTemplate([
                            'title'       => htmlspecialchars($res_subject),
                            'greeting'    => 'Dear ' . htmlspecialchars($r['full_name'] ?: 'Resident') . ',',
                            'message'     => nl2br(htmlspecialchars(preg_replace('/^Dear\s+Resident,?\s*/i', '', $res_body_raw))),
                            'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Barangay Office'
                        ]);
                    try { $ok = sendEmail($r['email'], $res_subject, $body_html, $r['full_name']); } catch (Throwable $e) { error_log("Res sendEmail: " . $e->getMessage()); $ok = false; }
                    if ($ok) {
                        $sent++;
                        $tbl_chk2 = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
                        if ($tbl_chk2 && $tbl_chk2->num_rows > 0) {
                            $out = $conn->prepare("INSERT INTO tbl_email_replies (notification_id, from_email, from_name, subject, body_text, direction, is_read) VALUES (?, ?, 'Barangay System', ?, ?, 'outbound', 1)");
                            $mail_from = MAIL_FROM_EMAIL;
                            $out->bind_param('isss', $notif_id, $mail_from, $res_subject, $res_body_raw);
                            $out->execute(); $out->close();
                        }
                    } else { $fails++; $fail_list[] = $r['email']; }
                }
                $res_success = $sent > 0 ? "Resolution email sent to <strong>{$sent}</strong> resident(s)" . ($fails > 0 ? " ({$fails} failed)" : '') . "." : "No emails sent ({$fails} failed).";
                if ($sent === 0) { $res_error = $res_success; $res_success = ''; }
            }
        }
        $active_tab = 'resolution';
    }
}

$reply_success = ''; $reply_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_reply_action'])) {
    $is_admin = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');
    if ($is_admin) {
        $qr_subject  = trim($_POST['qr_subject']   ?? '');
        $qr_body_raw = trim($_POST['qr_body']      ?? '') ?: trim($_POST['qr_body_text'] ?? '');
        $qr_body_raw = strip_tags($qr_body_raw);
        $qr_mode     = $_POST['qr_mode']      ?? 'reply';
        $qr_to_email = trim($_POST['qr_to_email']  ?? '');
        $qr_to_name  = trim($_POST['qr_to_name']   ?? 'Resident');

        if (!$qr_subject)      { $reply_error = 'Subject is required.'; }
        elseif (!$qr_body_raw) { $reply_error = 'Message body is required.'; }
        else {
            require_once '../../includes/email_helper.php';
            $body_html = getEmailTemplate([
                'title'       => htmlspecialchars($qr_subject),
                'greeting'    => 'Dear ' . htmlspecialchars($qr_to_name ?: 'Resident') . ',',
                'message'     => nl2br(htmlspecialchars($qr_body_raw)),
                'footer_text' => (defined('APP_NAME') ? APP_NAME : 'Barangay System') . ' — Barangay Office'
            ]);
            $recipients = [];
            if ($qr_mode === 'reply' && $qr_to_email) {
                $recipients[] = ['email' => $qr_to_email, 'full_name' => $qr_to_name ?: 'Resident'];
            } elseif ($qr_mode === 'reply_all') {
                $tbl_chk3 = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
                if ($tbl_chk3 && $tbl_chk3->num_rows > 0) {
                    $inb = $conn->prepare("SELECT DISTINCT from_email, from_name FROM tbl_email_replies WHERE notification_id = ? AND direction = 'inbound'");
                    $inb->bind_param('i', $notif_id);
                    $inb->execute();
                    $inb_res = $inb->get_result();
                    while ($row = $inb_res->fetch_assoc()) {
                        if (!empty($row['from_email'])) {
                            $recipients[] = ['email' => $row['from_email'], 'full_name' => $row['from_name'] ?: 'Resident'];
                        }
                    }
                    $inb->close();
                }
                if ($req_email && !in_array($req_email, array_column($recipients, 'email'))) {
                    $recipients[] = ['email' => $req_email, 'full_name' => $req_name];
                }
                if (empty($recipients) && $qr_to_email) {
                    $recipients[] = ['email' => $qr_to_email, 'full_name' => $qr_to_name ?: 'Resident'];
                }
            } elseif ($qr_mode === 'forward' && $qr_to_email) {
                $recipients[] = ['email' => $qr_to_email, 'full_name' => $qr_to_name ?: 'Recipient'];
            }

            if (empty($recipients)) {
                $reply_error = 'No valid recipients found.';
            } else {
                $sent = 0; $fails = 0; $fail_list = [];
                foreach ($recipients as $r) {
                    try {
                        $ok = sendEmail($r['email'], $qr_subject, $body_html, $r['full_name']);
                    } catch (Throwable $e) {
                        error_log("QR sendEmail: " . $e->getMessage());
                        $ok = false;
                    }
                    if ($ok) {
                        $sent++;
                        $tbl_chk4 = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
                        if ($tbl_chk4 && $tbl_chk4->num_rows > 0) {
                            $out2 = $conn->prepare("INSERT INTO tbl_email_replies (notification_id, from_email, from_name, subject, body_text, direction, is_read) VALUES (?, ?, 'Barangay System', ?, ?, 'outbound', 1)");
                            $mail_from = MAIL_FROM_EMAIL;
                            $out2->bind_param('isss', $notif_id, $mail_from, $qr_subject, $qr_body_raw);
                            $out2->execute();
                            $out2->close();
                        }
                    } else {
                        $fails++;
                        $fail_list[] = $r['email'];
                    }
                }
                $reply_success = $sent > 0
                    ? "Email sent to <strong>{$sent}</strong> recipient(s)" . ($fails > 0 ? " ({$fails} failed: " . htmlspecialchars(implode(', ', $fail_list)) . ")" : '') . "."
                    : '';
                if ($sent === 0) {
                    $reply_error = "No emails were sent. Failed: " . htmlspecialchars(implode(', ', $fail_list));
                }
            }
        }
    }
    $active_tab = 'conversations';
}

// ── Load email replies ────────────────────────────────────────────────────────
$email_replies = [];
$tbl_check = $conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
if ($tbl_check && $tbl_check->num_rows > 0) {

    $col_check = $conn->query("SHOW COLUMNS FROM tbl_email_replies LIKE 'attachments'");
    $has_attach_col = ($col_check && $col_check->num_rows > 0);

    $lookup_id = $notif_id;
    if ($is_email_reply_notif && !empty($notif['reference_id'])) {
        $lookup_id = intval($notif['reference_id']);
    }

    // Exclude the reference_id row since it is already rendered as the first message
    if ($is_email_reply_notif && !empty($notif['reference_id'])) {
        $exclude_id = intval($notif['reference_id']);
        $rpl_stmt = $conn->prepare("SELECT * FROM tbl_email_replies WHERE notification_id = ? AND id != ? ORDER BY created_at ASC");
        $rpl_stmt->bind_param('ii', $lookup_id, $exclude_id);
    } else {
        $rpl_stmt = $conn->prepare("SELECT * FROM tbl_email_replies WHERE notification_id = ? ORDER BY created_at ASC");
        $rpl_stmt->bind_param('i', $lookup_id);
    }

    $rpl_stmt->execute();
    $rpl_result = $rpl_stmt->get_result();

    while ($row = $rpl_result->fetch_assoc()) {
        if ($has_attach_col && !empty($row['attachments'])) {
            $decoded = json_decode($row['attachments'], true);
            $row['attachments_parsed'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['attachments_parsed'] = [];
        }
        $email_replies[] = $row;
    }
    $rpl_stmt->close();

    if (!empty($email_replies) && ($user_role === 'Super Admin' || $user_role === 'Super Administrator')) {
        $stmt_mark = $conn->prepare("UPDATE tbl_email_replies SET is_read = 1 WHERE notification_id = ? AND direction = 'inbound' AND is_read = 0");
        $stmt_mark->bind_param('i', $lookup_id);
        $stmt_mark->execute();
        $stmt_mark->close();
    }
}

$is_admin_user = ($user_role === 'Super Admin' || $user_role === 'Super Administrator');

// ── Helper: detect if attachment is an image ──────────────────────────────────
function attachmentIsImage(array $att): bool {
    return !empty($att['is_image'])
        || (isset($att['mime']) && strpos($att['mime'], 'image/') === 0)
        || (isset($att['filename']) && preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i', $att['filename']));
}

// ── Helper: render attachment strip ──────────────────────────────────────────
function renderAttachments(array $attachments, string $msgClass = ''): string {
    if (empty($attachments)) return '';
    $html  = '<div class="nd-attachments' . ($msgClass ? ' ' . $msgClass : '') . '">';
    $html .= '<div class="nd-attach-label"><i class="fas fa-paperclip"></i> Attachments (' . count($attachments) . ')</div>';
    $html .= '<div class="nd-attach-grid">';
    foreach ($attachments as $att) {
        $url      = htmlspecialchars($att['url']      ?? '');
        $filename = htmlspecialchars($att['filename']  ?? 'file');
        $mime     = $att['mime'] ?? '';
        $isImg    = attachmentIsImage($att);
        $size     = isset($att['size']) ? number_format($att['size'] / 1024, 1) . ' KB' : '';

        if ($isImg) {
            $html .= '<div class="nd-attach-img-wrap">'
                   . '<img src="' . $url . '" alt="' . $filename . '" class="nd-attach-thumb" '
                   . 'onclick="ndLightbox(\'' . $url . '\',\'' . addslashes($filename) . '\')" '
                   . 'loading="lazy">'
                   . '<div class="nd-attach-img-meta">'
                   . '<span class="nd-attach-name">' . $filename . '</span>'
                   . ($size ? '<span class="nd-attach-size">' . $size . '</span>' : '')
                   . '</div>'
                   . '<div class="nd-attach-img-actions">'
                   . '<a href="' . $url . '" download="' . $filename . '" class="nd-attach-dl" title="Download"><i class="fas fa-download"></i></a>'
                   . '<button class="nd-attach-view" onclick="ndLightbox(\'' . $url . '\',\'' . addslashes($filename) . '\')" title="View full size"><i class="fas fa-expand-alt"></i></button>'
                   . '</div>'
                   . '</div>';
        } else {
            $iconMap = [
                'application/pdf' => 'fa-file-pdf',
                'application/msword' => 'fa-file-word',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'fa-file-word',
                'application/vnd.ms-excel' => 'fa-file-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'fa-file-excel',
                'application/zip' => 'fa-file-archive',
                'text/plain' => 'fa-file-alt',
            ];
            $icon = $iconMap[$mime] ?? 'fa-file';
            $html .= '<div class="nd-attach-file-wrap">'
                   . '<i class="fas ' . $icon . ' nd-attach-file-icon"></i>'
                   . '<div class="nd-attach-file-info">'
                   . '<span class="nd-attach-name">' . $filename . '</span>'
                   . ($size ? '<span class="nd-attach-size">' . $size . '</span>' : '')
                   . '</div>'
                   . '<a href="' . $url . '" download="' . $filename . '" class="nd-attach-dl" title="Download"><i class="fas fa-download"></i></a>'
                   . '</div>';
        }
    }
    $html .= '</div></div>';
    return $html;
}

include '../../includes/header.php';
?>

<div id="nd-lightbox" class="nd-lightbox-overlay" style="display:none;">
    <div class="nd-lightbox-box">
        <div class="nd-lightbox-header">
            <span id="nd-lightbox-title" class="nd-lightbox-filename"></span>
            <div class="nd-lightbox-actions">
                <a id="nd-lightbox-dl" href="#" download class="nd-lightbox-btn" title="Download"><i class="fas fa-download"></i></a>
                <button class="nd-lightbox-btn" id="nd-lightbox-close" title="Close"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="nd-lightbox-body">
            <img id="nd-lightbox-img" src="" alt="" class="nd-lightbox-img">
        </div>
    </div>
</div>

<?php if ($is_admin_user): ?>
<div id="nd-compose-modal" class="nd-modal-overlay" style="display:none;">
    <div class="nd-modal-box">
        <div class="nd-modal-header">
            <span id="nd-modal-title" class="nd-modal-title-text">Reply</span>
            <button class="nd-modal-close" id="nd-modal-close-btn" title="Close">&times;</button>
        </div>
        <?php if ($reply_success): ?><div class="rs-alert rs-alert-success" style="margin:0;border-radius:0;"><i class="fas fa-check-circle me-2"></i><?= $reply_success ?></div><?php endif; ?>
        <?php if ($reply_error): ?><div class="rs-alert rs-alert-error" style="margin:0;border-radius:0;"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($reply_error) ?></div><?php endif; ?>
        <form method="POST" action="?id=<?= $notif_id ?>&tab=conversations" id="nd-compose-form">
            <input type="hidden" name="quick_reply_action" value="1">
            <input type="hidden" name="qr_mode"     id="qr-mode-input"     value="reply">
            <input type="hidden" name="qr_to_email" id="qr-to-email-input" value="">
            <input type="hidden" name="qr_to_name"  id="qr-to-name-input"  value="">
            <input type="hidden" name="qr_body"     id="qr-body-hidden"    value="">
            <textarea name="qr_body_text" id="qr-body-fallback" style="display:none;"></textarea>
            <div class="nd-modal-field-row">
                <label class="nd-modal-field-lbl">To</label>
                <div class="nd-modal-to-display" id="qr-to-display">—</div>
            </div>
            <div class="nd-modal-field-row" style="border-bottom:1px solid #e2e8f0;">
                <label class="nd-modal-field-lbl">Subject</label>
                <input type="text" name="qr_subject" id="qr-subject-input" class="nd-modal-subject-input" placeholder="Subject…">
            </div>
            <div class="rs-editor-toolbar nd-modal-toolbar">
                <button type="button" class="rs-tb-btn rs-tb-bold"   onclick="ndMExec('bold')"                   title="Bold"><b>B</b></button>
                <button type="button" class="rs-tb-btn rs-tb-italic" onclick="ndMExec('italic')"                 title="Italic"><i>I</i></button>
                <button type="button" class="rs-tb-btn rs-tb-under"  onclick="ndMExec('underline')"              title="Underline"><u>U</u></button>
                <button type="button" class="rs-tb-btn rs-tb-strike" onclick="ndMExec('strikethrough')"          title="Strike"><s>S</s></button>
                <div class="rs-tb-sep"></div>
                <select class="rs-tb-select rs-font-sel" onchange="ndMExec('fontName', this.value)">
                    <option value="Arial">Arial</option><option value="PT Sans" selected>PT Sans</option>
                    <option value="Georgia">Georgia</option><option value="Courier New">Courier New</option>
                    <option value="Times New Roman">Times New Roman</option>
                </select>
                <select class="rs-tb-select rs-size-sel" onchange="ndMExec('fontSize', this.value)">
                    <option value="1">8</option><option value="2">10</option><option value="3" selected>12</option>
                    <option value="4">14</option><option value="5">18</option><option value="6">24</option>
                </select>
                <div class="rs-tb-sep"></div>
                <button type="button" class="rs-tb-btn" onclick="ndMExec('justifyLeft')"         title="Left"><i class="fas fa-align-left"></i></button>
                <button type="button" class="rs-tb-btn" onclick="ndMExec('justifyCenter')"       title="Center"><i class="fas fa-align-center"></i></button>
                <button type="button" class="rs-tb-btn" onclick="ndMExec('justifyRight')"        title="Right"><i class="fas fa-align-right"></i></button>
                <div class="rs-tb-sep"></div>
                <button type="button" class="rs-tb-btn" onclick="ndMExec('insertUnorderedList')" title="Bullets"><i class="fas fa-list-ul"></i></button>
                <button type="button" class="rs-tb-btn" onclick="ndMExec('insertOrderedList')"   title="Numbers"><i class="fas fa-list-ol"></i></button>
                <div class="rs-tb-sep"></div>
                <button type="button" class="rs-tb-btn" onclick="ndMInsertLink()"                title="Link"><i class="fas fa-link"></i></button>
                <button type="button" class="rs-tb-btn" onclick="ndMExec('removeFormat')"        title="Clear"><i class="fas fa-remove-format"></i></button>
            </div>
            <div class="rs-editor nd-modal-editor" id="nd-modal-editor" contenteditable="true"
                 data-placeholder="Write your message here…" oninput="ndMSyncBody()"></div>
            <div id="nd-modal-quoted" class="nd-modal-quoted" style="display:none;">
                <div class="nd-modal-quoted-header">
                    <i class="fas fa-reply" id="nd-quoted-icon"></i>
                    <span id="nd-quoted-label">Original message</span>
                    <button type="button" class="nd-modal-quoted-toggle" id="nd-quoted-toggle-btn">Hide</button>
                </div>
                <div class="nd-modal-quoted-body" id="nd-quoted-body"></div>
            </div>
            <div class="nd-modal-footer">
                <div class="nd-modal-footer-left">
                    <button type="submit" class="rs-save-btn" onclick="ndMSyncBody()">
                        <i class="fas fa-paper-plane me-2"></i><span id="nd-modal-send-label">Send Reply</span>
                    </button>
                    <button type="button" class="rs-cancel-btn" id="nd-modal-cancel-btn">Cancel</button>
                </div>
                <div class="nd-modal-footer-right">
                    <span class="nd-modal-mode-badge" id="nd-modal-mode-badge">Reply</span>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="nd-wrapper">

    <div class="nd-topbar">
        <div class="nd-topbar-left">
            <a href="index.php" class="nd-topbar-btn"><i class="fas fa-arrow-left"></i> Back</a>
            <?php if ($view_url): ?><a href="<?= htmlspecialchars($view_url) ?>" class="nd-topbar-btn"><i class="fas fa-external-link-alt"></i> View Record</a><?php endif; ?>
            <button class="nd-topbar-btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
        <div class="nd-topbar-right">
            <button class="nd-topbar-btn nd-actions-btn">Actions <i class="fas fa-chevron-down"></i></button>
            <button class="nd-topbar-btn nd-icon-btn" title="Refresh" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
            <button class="nd-topbar-btn nd-icon-btn" onclick="history.back()"><i class="fas fa-chevron-left"></i></button>
            <button class="nd-topbar-btn nd-icon-btn" onclick="history.forward()"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>

    <div class="nd-body">
        <div class="nd-left">

            <div class="nd-header">
                <div class="nd-header-icon" style="background:<?= $icon_bg ?>;"><i class="fas <?= $icon_class ?>"></i></div>
                <div class="nd-header-info">
                    <div class="nd-header-top">
                        <span class="nd-notif-id"><?= $ref_id_display ?></span>
                        <h2 class="nd-notif-title"><?= htmlspecialchars($notif['title']) ?></h2>
                    </div>
                    <div class="nd-header-meta">
                        <span class="nd-badge-type"><?= htmlspecialchars($type_label) ?></span>
                        <span class="nd-meta-pipe">|</span>
                        <?php if ($is_email_reply_notif): ?>
                        <span class="nd-meta-muted">From&nbsp;</span>
                        <?php else: ?>
                        <span class="nd-meta-muted">Received by&nbsp;</span>
                        <?php endif; ?>
                        <span class="nd-requester-wrap">
                            <span class="nd-meta-link nd-requester-trigger"><?= htmlspecialchars($req_name) ?></span>
                            <div class="nd-hover-card">
                                <div class="nd-hc-top">
                                    <div class="nd-hc-avatar"><?= strtoupper(substr($req_name, 0, 1)) ?></div>
                                    <div class="nd-hc-name"><?= htmlspecialchars($req_name) ?></div>
                                </div>
                                <div class="nd-hc-rows">
                                    <?php if ($req_phone): ?>
                                    <div class="nd-hc-row"><i class="fas fa-phone-alt nd-hc-icon"></i><span><?= htmlspecialchars($req_phone) ?></span></div>
                                    <?php else: ?>
                                    <div class="nd-hc-row nd-hc-muted"><i class="fas fa-phone-alt nd-hc-icon"></i><span>Not available</span></div>
                                    <?php endif; ?>
                                    <div class="nd-hc-row"><i class="fas fa-envelope nd-hc-icon"></i><span><?= $req_email ? htmlspecialchars($req_email) : 'Not available' ?></span></div>
                                    <?php if ($req_site): ?>
                                    <div class="nd-hc-divider"></div>
                                    <div class="nd-hc-row"><span class="nd-hc-site-lbl">Site</span><span><?= htmlspecialchars($req_site) ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </span>
                        <span class="nd-meta-muted">&nbsp;on <?= $created_at ?></span>
                        <?php if (!empty($inbound_attachments)): ?>
                        <span class="nd-meta-pipe">|</span>
                        <span class="nd-meta-attach-badge">
                            <i class="fas fa-paperclip"></i>
                            <?= count($inbound_attachments) ?> attachment<?= count($inbound_attachments) > 1 ? 's' : '' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="nd-header-actions">
                    <?php if ($is_admin_user): ?>
                    <div class="nd-reply-group">
                        <button class="nd-reply-main" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'">
                            <i class="fas fa-reply"></i> Reply All
                        </button>
                        <button class="nd-reply-caret" id="nd-reply-caret-btn"><i class="fas fa-chevron-down"></i></button>
                        <div class="nd-reply-dropdown" id="nd-reply-dropdown">
                            <button class="nd-reply-dd-item" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-reply"></i> Reply</button>
                            <button class="nd-reply-dd-item" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-reply-all"></i> Reply All</button>
                            <button class="nd-reply-dd-item" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=forward&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-share"></i> Forward</button>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="nd-reply-group"><button class="nd-reply-main" disabled style="opacity:.5;cursor:not-allowed;"><i class="fas fa-reply"></i> Reply All</button></div>
                    <?php endif; ?>
                    <button class="nd-icon-action-btn"><i class="fas fa-grip-horizontal"></i></button>
                </div>
            </div>

            <div class="nd-status-box">
                <div class="nd-status-col">
                    <div class="nd-status-lbl">Status</div>
                    <div class="nd-status-val"><?= $notif['is_read'] ? 'Read' : 'Unread' ?></div>
                </div>
                <div class="nd-transitions-col">
                    <div class="nd-status-lbl">Transitions</div>
                    <div class="nd-transitions">
                        <a href="index.php" class="nd-trans-btn"><i class="fas fa-arrow-left me-1"></i>Back</a>
                        <?php if ($view_url): ?><a href="<?= htmlspecialchars($view_url) ?>" class="nd-trans-btn nd-trans-primary"><i class="fas fa-external-link-alt me-1"></i>View Record</a><?php endif; ?>
                        <?php if ($is_email_notif && $is_admin_user): ?><a href="email-history.php" class="nd-trans-btn"><i class="fas fa-history me-1"></i>Email History</a><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="nd-tabs-row">
                <button class="nd-tab active" data-tab="conversations">Conversations</button>
                <button class="nd-tab" data-tab="details">Details</button>
                <?php if ($is_email_notif): ?><button class="nd-tab" data-tab="emailinfo">Email Info</button><?php endif; ?>
                <?php if ($is_admin_user): ?><button class="nd-tab" data-tab="resolution">Resolution</button><?php endif; ?>
                <button class="nd-tab" data-tab="history">History</button>
            </div>

            <div class="nd-tab-pane" id="tab-conversations">

                <?php if ($reply_success): ?><div class="rs-alert rs-alert-success"><i class="fas fa-check-circle me-2"></i><?= $reply_success ?></div><?php endif; ?>
                <?php if ($reply_error): ?><div class="rs-alert rs-alert-error"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($reply_error) ?></div><?php endif; ?>

                <div class="nd-filter-bar">
                    <span class="nd-filter-lbl">Filter :</span>
                    <label class="nd-chk nd-chk-on"><span class="nd-chk-box nd-chk-blue"></span> Messages</label>
                    <label class="nd-chk"><span class="nd-chk-box"></span> Auto Notifications</label>
                    <label class="nd-chk nd-chk-on"><span class="nd-chk-box nd-chk-blue"></span> Notes</label>
                    <div class="nd-filter-right ms-auto">
                        <?php if ($is_admin_user): ?>
                        <button class="nd-check-replies-btn" id="nd-check-replies-btn" onclick="ndCheckReplies(false)" title="Check for new replies">
                            <i class="fas fa-sync-alt" id="nd-check-icon"></i> Check for Replies
                        </button>
                        <span class="nd-poll-status" id="nd-poll-status"></span>
                        <?php endif; ?>
                        <button class="nd-sort-btn"><i class="fas fa-sort-alpha-down"></i><span class="nd-sort-num">Z<br>A</span></button>
                    </div>
                </div>

                <div class="nd-date-pill-row">
                    <div class="nd-date-pill"><?= date('Y-m-d', strtotime($notif['created_at'])) === date('Y-m-d') ? 'Today' : date('M j, Y', strtotime($notif['created_at'])) ?></div>
                </div>

                <?php if ($is_email_reply_notif && $inbound_sender_name): ?>
                <div class="nd-thread nd-thread-reply nd-thread-inbound" data-reply-id="<?= intval($notif['reference_id']) ?>">
                    <div class="nd-avatar-col">
                        <div class="nd-avatar" style="background:#ebf4ff;color:#3182ce;border:1.5px solid rgba(49,130,206,.13);font-size:13px;"><?= strtoupper(substr($inbound_sender_name, 0, 1)) ?></div>
                        <div class="nd-avatar-line"></div>
                    </div>
                    <div class="nd-msg-card nd-msg-inbound">
                        <div class="nd-msg-header">
                            <span class="nd-msg-sender" style="color:#3182ce;"><?= htmlspecialchars($inbound_sender_name) ?><span class="nd-reply-badge">Resident Email</span></span>
                            <span class="nd-msg-time">· <?= $created_at_full ?></span>
                            <div class="nd-msg-header-end"><i class="fas fa-envelope nd-globe" title="Email"></i></div>
                        </div>
                        <div class="nd-msg-body">
                            <div class="nd-msg-subject-row"><span class="nd-msg-subj-prefix">Subject: </span><span class="nd-msg-subj"><?= htmlspecialchars($notif['title']) ?></span></div>
                            <div class="nd-msg-to-row"><span class="nd-msg-to-lbl">From: </span><span class="nd-msg-to-val"><?= htmlspecialchars($inbound_sender_email) ?></span></div>
                            <div class="nd-msg-text"><?= nl2br(htmlspecialchars($notif['message'])) ?></div>
                            <?= renderAttachments($inbound_attachments) ?>
                        </div>
                        <?php if ($is_admin_user): ?>
                        <div class="nd-msg-footer">
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-reply"></i> Reply</button>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-reply-all"></i> Reply All</button>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=forward&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-share"></i> Forward</button>
                            <button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                        <?php else: ?>
                        <div class="nd-msg-footer"><button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="nd-thread">
                    <div class="nd-avatar-col">
                        <div class="nd-avatar" style="background:<?= $icon_bg ?>;"><i class="fas fa-bell" style="font-size:11px;"></i></div>
                        <div class="nd-avatar-line"></div>
                    </div>
                    <div class="nd-msg-card">
                        <div class="nd-msg-header">
                            <span class="nd-msg-sender">System Notification</span>
                            <span class="nd-msg-time">· <?= $created_at_full ?></span>
                            <div class="nd-msg-header-end"><i class="fas fa-globe nd-globe"></i></div>
                        </div>
                        <div class="nd-msg-body">
                            <div class="nd-msg-subject-row"><span class="nd-msg-subj-prefix">Subject: </span><span class="nd-msg-subj"><?= htmlspecialchars($notif['title']) ?></span></div>
                            <?php if (!empty($notif['reference_id'])): ?>
                            <div class="nd-msg-to-row"><span class="nd-msg-to-lbl">Ref # : </span><span class="nd-msg-to-val"><?= htmlspecialchars(ucwords(str_replace('_',' ',$rt))) ?> #<?= intval($notif['reference_id']) ?></span></div>
                            <?php endif; ?>
                            <div class="nd-msg-text"><?= nl2br(htmlspecialchars($notif['message'])) ?></div>
                            <?php if ($is_email_notif && !empty($email_history['email_message'])): ?>
                            <div class="nd-email-body-extra"><div class="nd-extra-label"><i class="fas fa-envelope me-1"></i>Full Email Body Sent</div><div class="nd-extra-text"><?= nl2br(htmlspecialchars($email_history['email_message'])) ?></div></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_admin_user): ?>
                        <div class="nd-msg-footer">
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-reply"></i> Reply</button>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-reply-all"></i> Reply All</button>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=forward&to_email=<?= urlencode($req_email) ?>&to_name=<?= urlencode($req_name) ?>'"><i class="fas fa-share"></i> Forward</button>
                            <button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                        <?php else: ?>
                        <div class="nd-msg-footer"><button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $prev_date = date('Y-m-d', strtotime($notif['created_at']));
                foreach ($email_replies as $reply):
                    $reply_date        = date('Y-m-d', strtotime($reply['created_at']));
                    $reply_time        = date('D, M j, Y g:i A', strtotime($reply['created_at']));
                    $is_inbound        = ($reply['direction'] === 'inbound');
                    $sender_name       = $is_inbound ? htmlspecialchars($reply['from_name'] ?: $reply['from_email']) : 'Barangay System';
                    $sender_color      = $is_inbound ? '#3182ce' : '#2d8a4e';
                    $avatar_bg         = $is_inbound ? '#ebf4ff' : '#e6ffed';
                    $avatar_color      = $is_inbound ? '#3182ce' : '#2d8a4e';
                    $avatar_ltr        = strtoupper(substr($is_inbound ? ($reply['from_name'] ?: 'R') : 'B', 0, 1));
                    $body_display      = nl2br(htmlspecialchars(strip_tags($reply['body_text'] ?: $reply['body_html'])));
                    $reply_attachments = $reply['attachments_parsed'] ?? [];
                ?>
                <?php if ($reply_date !== $prev_date): $prev_date = $reply_date; ?>
                <div class="nd-date-pill-row"><div class="nd-date-pill"><?= $reply_date === date('Y-m-d') ? 'Today' : date('M j, Y', strtotime($reply['created_at'])) ?></div></div>
                <?php endif; ?>

                <div class="nd-thread nd-thread-reply <?= $is_inbound ? 'nd-thread-inbound' : 'nd-thread-outbound' ?>" data-reply-id="<?= intval($reply['id'] ?? 0) ?>">
                    <div class="nd-avatar-col">
                        <div class="nd-avatar" style="background:<?= $avatar_bg ?>;color:<?= $avatar_color ?>;border:1.5px solid <?= $avatar_color ?>20;font-size:13px;"><?= $avatar_ltr ?></div>
                        <div class="nd-avatar-line"></div>
                    </div>
                    <div class="nd-msg-card <?= $is_inbound ? 'nd-msg-inbound' : 'nd-msg-outbound' ?>">
                        <div class="nd-msg-header">
                            <span class="nd-msg-sender" style="color:<?= $sender_color ?>;">
                                <?= $sender_name ?>
                                <?php if ($is_inbound): ?><span class="nd-reply-badge">Resident Reply</span><?php else: ?><span class="nd-reply-badge nd-reply-badge-out">Barangay</span><?php endif; ?>
                            </span>
                            <span class="nd-msg-time">· <?= $reply_time ?></span>
                            <div class="nd-msg-header-end">
                                <i class="fas fa-envelope nd-globe"></i>
                                <?php if ($is_inbound && !$reply['is_read']): ?><span class="nd-unread-dot"></span><?php endif; ?>
                                <?php if (!empty($reply_attachments)): ?>
                                <span class="nd-attach-indicator" title="<?= count($reply_attachments) ?> attachment(s)">
                                    <i class="fas fa-paperclip"></i><?= count($reply_attachments) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="nd-msg-body">
                            <div class="nd-msg-subject-row"><span class="nd-msg-subj-prefix">Subject: </span><span class="nd-msg-subj"><?= htmlspecialchars($reply['subject'] ?? '') ?></span></div>
                            <?php if ($is_inbound): ?><div class="nd-msg-to-row"><span class="nd-msg-to-lbl">From: </span><span class="nd-msg-to-val"><?= htmlspecialchars($reply['from_email']) ?></span></div><?php endif; ?>
                            <div class="nd-msg-text"><?= $body_display ?></div>
                            <?= renderAttachments($reply_attachments) ?>
                        </div>
                        <?php if ($is_admin_user): ?>
                        <div class="nd-msg-footer">
                            <?php if ($is_inbound): ?>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply&to_email=<?= urlencode($reply['from_email'] ?? '') ?>&to_name=<?= urlencode($reply['from_name'] ?? '') ?>&reply_id=<?= intval($reply['id'] ?? 0) ?>'"><i class="fas fa-reply"></i> Reply</button>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=reply_all&reply_id=<?= intval($reply['id'] ?? 0) ?>'"><i class="fas fa-reply-all"></i> Reply All</button>
                            <?php endif; ?>
                            <button class="nd-msg-action" onclick="window.location.href='compose-reply.php?notif_id=<?= $notif_id ?>&mode=forward&reply_id=<?= intval($reply['id'] ?? 0) ?>'"><i class="fas fa-share"></i> Forward</button>
                            <button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                        <?php else: ?>
                        <div class="nd-msg-footer"><button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($email_replies) && !$is_email_reply_notif): ?>
                <div class="nd-no-replies"><i class="fas fa-inbox"></i><span>No replies yet. When a resident replies to an email from the Barangay, it will appear here.</span></div>
                <?php endif; ?>

            </div>

            <div class="nd-tab-pane nd-hidden" id="tab-details">
                <div class="dt-section">
                    <div class="dt-section-head">Ticket Details</div>
                    <div class="dt-two-col">
                        <div class="dt-col">
                            <div class="dt-field"><div class="dt-label">Notification Type</div><div class="dt-value"><?= htmlspecialchars($type_label) ?></div></div>
                            <div class="dt-field"><div class="dt-label">Status</div><div class="dt-value"><span class="dt-status-dot"></span> Read</div></div>
                            <div class="dt-field"><div class="dt-label">Notification ID</div><div class="dt-value"><?= $ref_id_display ?></div></div>
                        </div>
                        <div class="dt-col">
                            <div class="dt-field"><div class="dt-label">Reference</div><div class="dt-value"><?= !empty($rt) ? htmlspecialchars(ucwords(str_replace('_',' ',$rt))) : '—' ?></div></div>
                            <div class="dt-field"><div class="dt-label">Agents</div><div class="dt-value">—</div></div>
                            <div class="dt-field"><div class="dt-label">Group</div><div class="dt-value"><?= htmlspecialchars($user_role) ?></div></div>
                        </div>
                    </div>
                </div>
                <div class="dt-section">
                    <div class="dt-section-head dt-collapsible" onclick="dtToggle(this)"><i class="fas fa-chevron-right dt-caret"></i> <?= $is_email_reply_notif ? 'Sender Information' : 'Requester Information' ?></div>
                    <div class="dt-collapsible-body">
                        <div class="dt-two-col">
                            <div class="dt-col">
                                <?php if ($is_email_reply_notif): ?>
                                <div class="dt-field"><div class="dt-label">From</div><div class="dt-value"><?= htmlspecialchars($req_name) ?></div></div>
                                <div class="dt-field"><div class="dt-label">Email</div><div class="dt-value"><?= htmlspecialchars($req_email ?: '—') ?></div></div>
                                <?php if (!empty($inbound_attachments)): ?>
                                <div class="dt-field"><div class="dt-label">Attachments</div><div class="dt-value"><?= count($inbound_attachments) ?> file(s) attached</div></div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="dt-field"><div class="dt-label">Description</div><div class="dt-value dt-message-block"><?= nl2br(htmlspecialchars($notif['message'])) ?></div></div>
                                <?php endif; ?>
                            </div>
                            <div class="dt-col"><div class="dt-field"><div class="dt-label">Source</div><div class="dt-value"><?= $is_email_reply_notif ? 'Email' : 'System' ?></div></div></div>
                        </div>
                    </div>
                </div>
                <div class="dt-section">
                    <div class="dt-section-head dt-collapsible" onclick="dtToggle(this)"><i class="fas fa-chevron-right dt-caret"></i> SLA / Announcement Information</div>
                    <div class="dt-collapsible-body">
                        <div class="dt-two-col">
                            <div class="dt-col">
                                <div class="dt-field"><div class="dt-label">ID</div><div class="dt-value"><?= $ref_id_display ?></div></div>
                                <div class="dt-field"><div class="dt-label">Reference</div><div class="dt-value"><?= !empty($notif['reference_id']) ? htmlspecialchars(ucwords(str_replace('_',' ',$rt))) . ' #' . intval($notif['reference_id']) : '—' ?></div></div>
                            </div>
                            <div class="dt-col"><?php if ($is_email_notif && $email_history): ?><div class="dt-field"><div class="dt-label">Source Category</div><div class="dt-value"><?= htmlspecialchars(ucwords(str_replace('_',' ',$email_history['notification_type'] ?? 'Announcement'))) ?></div></div><?php endif; ?></div>
                        </div>
                    </div>
                </div>
                <div class="dt-section">
                    <div class="dt-section-head">Response</div>
                    <div class="dt-two-col">
                        <div class="dt-col">
                            <div class="dt-field"><div class="dt-label">Received At</div><div class="dt-value"><?= $created_at_full ?></div></div>
                            <div class="dt-field"><div class="dt-label">Read At</div><div class="dt-value"><?= date('D, M j, Y g:i A') ?></div></div>
                        </div>
                        <div class="dt-col">
                            <div class="dt-field"><div class="dt-label">Approval Status</div><div class="dt-value dt-muted">Not Configured</div></div>
                            <?php if ($view_url): ?><div class="dt-field"><div class="dt-label">View Record</div><div class="dt-value"><a href="<?= htmlspecialchars($view_url) ?>" class="dt-link"><i class="fas fa-external-link-alt me-1"></i>Open Record</a></div></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="dt-section">
                    <div class="dt-section-head dt-collapsible" onclick="dtToggle(this)"><i class="fas fa-chevron-right dt-caret"></i> Associations</div>
                    <div class="dt-collapsible-body">
                        <?php if ($view_url): ?>
                        <div class="dt-assoc-list"><a href="<?= htmlspecialchars($view_url) ?>" class="dt-assoc-link"><i class="fas fa-external-link-alt me-1"></i><?= htmlspecialchars(ucwords(str_replace('_',' ',$rt))) ?> #<?= intval($notif['reference_id']) ?></a></div>
                        <?php else: ?><div class="dt-field" style="padding:10px 0;"><div class="dt-value dt-muted">No associations found.</div></div><?php endif; ?>
                        <?php if ($is_email_notif && $is_admin_user): ?>
                        <div class="dt-assoc-list" style="margin-top:6px;">
                            <a href="email-history.php" class="dt-assoc-link"><i class="fas fa-history me-1"></i>All Email History</a>
                            <a href="email-history.php?id=<?= intval($notif['reference_id']) ?>" class="dt-assoc-link"><i class="fas fa-list me-1"></i>Recipient List</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($is_email_notif && $email_history): ?>
            <div class="nd-tab-pane nd-hidden" id="tab-emailinfo">
                <div class="nd-detail-table">
                    <?php if ($is_email_reply_notif): ?>
                    <div class="nd-detail-row"><div class="nd-dl">From</div><div class="nd-dv"><?= htmlspecialchars($req_name) ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">From Email</div><div class="nd-dv"><?= htmlspecialchars($req_email ?: '—') ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Category</div><div class="nd-dv">Email Reply</div></div>
                    <?php if (!empty($inbound_attachments)): ?>
                    <div class="nd-detail-row"><div class="nd-dl">Attachments</div><div class="nd-dv">
                        <?php foreach ($inbound_attachments as $att):
                            $_isImg = attachmentIsImage($att);
                        ?>
                        <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                            <?php if ($_isImg): ?>
                            <img src="<?= htmlspecialchars($att['url']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer;" onclick="ndLightbox('<?= htmlspecialchars($att['url']) ?>','<?= addslashes($att['filename']) ?>')">
                            <?php else: ?><i class="fas fa-file" style="color:#a0aec0;"></i><?php endif; ?>
                            <a href="<?= htmlspecialchars($att['url']) ?>" download="<?= htmlspecialchars($att['filename']) ?>" class="nd-dv-link"><?= htmlspecialchars($att['filename']) ?></a>
                        </div>
                        <?php endforeach; ?>
                    </div></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="nd-detail-row"><div class="nd-dl">Sent By</div><div class="nd-dv"><?= htmlspecialchars($email_history['sender_name'] ?? 'Administrator') ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Sender Email</div><div class="nd-dv"><?= htmlspecialchars($email_history['sender_email'] ?? '—') ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Sent To</div><div class="nd-dv"><?= htmlspecialchars(ucwords(str_replace('_',' ',$email_history['recipient_type'] ?? 'All Residents'))) ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Total Recipients</div><div class="nd-dv"><?= intval($email_history['total_recipients'] ?? 0) ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Delivered</div><div class="nd-dv" style="color:#198754;font-weight:600;"><?= intval($email_history['successful_sends'] ?? 0) ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Failed</div><div class="nd-dv" style="color:#dc3545;font-weight:600;"><?= intval($email_history['failed_sends'] ?? 0) ?></div></div>
                    <?php if (!empty($email_history['notification_type'])): ?>
                    <div class="nd-detail-row"><div class="nd-dl">Category</div><div class="nd-dv"><?= htmlspecialchars(ucwords(str_replace('_',' ',$email_history['notification_type']))) ?></div></div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($is_admin_user): ?>
                    <div class="nd-detail-row"><div class="nd-dl">Admin Links</div><div class="nd-dv" style="display:flex;gap:12px;flex-wrap:wrap;">
                        <a href="email-history.php" class="nd-dv-link">All Email History</a>
                        <a href="email-history.php?id=<?= intval($notif['reference_id']) ?>" class="nd-dv-link">Recipient List</a>
                    </div></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="nd-tab-pane nd-hidden" id="tab-history">
                <div class="nd-detail-table">
                    <div class="nd-detail-row"><div class="nd-dl">Created</div><div class="nd-dv"><?= $created_at_full ?></div></div>
                    <div class="nd-detail-row"><div class="nd-dl">Marked Read</div><div class="nd-dv"><?= date('M j, Y g:i A') ?></div></div>
                </div>
            </div>

            <?php if ($is_admin_user): ?>
            <div class="nd-tab-pane nd-hidden" id="tab-resolution">
                <div class="rs-wrap">
                    <div class="rs-toolbar-area">
                        <div class="rs-section-title">Resolution</div>
                        <div class="rs-template-row">
                            <label class="rs-tmpl-label">Use Resolution Template:</label>
                            <select class="rs-tmpl-select" id="res-template-sel" onchange="rsApplyTemplate(this.value)">
                                <option value="">— Select Template —</option>
                                <option value="acknowledged">Complaint Acknowledged</option>
                                <option value="resolved">Issue Resolved</option>
                                <option value="followup">Follow-Up Required</option>
                                <option value="noaction">No Action Needed</option>
                            </select>
                        </div>
                    </div>
                    <?php if ($res_success): ?><div class="rs-alert rs-alert-success"><i class="fas fa-check-circle me-2"></i><?= $res_success ?></div><?php endif; ?>
                    <?php if ($res_error): ?><div class="rs-alert rs-alert-error"><i class="fas fa-exclamation-circle me-2"></i><?= $res_error ?></div><?php endif; ?>
                    <form method="POST" action="?id=<?= $notif_id ?>&tab=resolution" enctype="multipart/form-data" id="resolution-form">
                        <input type="hidden" name="resolution_action" value="1">
                        <div class="rs-subject-row">
                            <label class="rs-field-label">Subject</label>
                            <input type="text" name="res_subject" id="res-subject" class="rs-subject-input" placeholder="Resolution subject / email subject..." value="<?= htmlspecialchars('Re: ' . $notif['title']) ?>">
                        </div>
                        <div class="rs-editor-toolbar">
                            <button type="button" class="rs-tb-btn rs-tb-bold"   onclick="rsExec('bold')"                  title="Bold"><b>B</b></button>
                            <button type="button" class="rs-tb-btn rs-tb-italic" onclick="rsExec('italic')"                title="Italic"><i>I</i></button>
                            <button type="button" class="rs-tb-btn rs-tb-under"  onclick="rsExec('underline')"             title="Underline"><u>U</u></button>
                            <button type="button" class="rs-tb-btn rs-tb-strike" onclick="rsExec('strikethrough')"         title="Strike"><s>S</s></button>
                            <div class="rs-tb-sep"></div>
                            <select class="rs-tb-select rs-font-sel" onchange="rsExec('fontName', this.value)">
                                <option value="Arial">Arial</option><option value="PT Sans" selected>PT Sans</option>
                                <option value="Georgia">Georgia</option><option value="Courier New">Courier New</option>
                                <option value="Times New Roman">Times New Roman</option>
                            </select>
                            <select class="rs-tb-select rs-size-sel" onchange="rsExec('fontSize', this.value)">
                                <option value="1">8</option><option value="2">10</option><option value="3" selected>12</option>
                                <option value="4">14</option><option value="5">18</option><option value="6">24</option>
                            </select>
                            <div class="rs-tb-sep"></div>
                            <button type="button" class="rs-tb-btn" onclick="rsExec('justifyLeft')"          title="Left"><i class="fas fa-align-left"></i></button>
                            <button type="button" class="rs-tb-btn" onclick="rsExec('justifyCenter')"        title="Center"><i class="fas fa-align-center"></i></button>
                            <button type="button" class="rs-tb-btn" onclick="rsExec('justifyRight')"         title="Right"><i class="fas fa-align-right"></i></button>
                            <div class="rs-tb-sep"></div>
                            <button type="button" class="rs-tb-btn" onclick="rsExec('insertUnorderedList')"  title="Bullets"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="rs-tb-btn" onclick="rsExec('insertOrderedList')"    title="Numbers"><i class="fas fa-list-ol"></i></button>
                            <div class="rs-tb-sep"></div>
                            <button type="button" class="rs-tb-btn" onclick="rsInsertLink()"                 title="Link"><i class="fas fa-link"></i></button>
                            <button type="button" class="rs-tb-btn" onclick="rsExec('removeFormat')"         title="Clear"><i class="fas fa-remove-format"></i></button>
                        </div>
                        <div class="rs-editor" id="rs-editor" contenteditable="true"
                             data-placeholder="Write your resolution here. This will be sent as an email to residents..."
                             oninput="rsSyncBody()"></div>
                        <textarea name="res_body" id="res-body-hidden" style="display:none;"></textarea>
                        <textarea name="res_body_text" id="res-body-text-fallback" style="display:none;"></textarea>
                        <div class="rs-status-bar">
                            <div class="rs-status-group">
                                <label class="rs-field-label" style="margin:0;">Request Status</label>
                                <select name="res_status" class="rs-status-select">
                                    <option value="sent">Sent</option><option value="work_in_progress">Work In Progress</option>
                                    <option value="resolved">Resolved</option><option value="on_hold">On Hold</option>
                                </select>
                            </div>
                            <div class="rs-recipient-group">
                                <label class="rs-field-label" style="margin:0;">Send To</label>
                                <select name="res_recipient" class="rs-status-select">
                                    <option value="all">All Residents</option>
                                    <?php if ($is_email_reply_notif && $inbound_sender_email): ?>
                                    <option value="sender" selected><?= htmlspecialchars($inbound_sender_name ?: $inbound_sender_email) ?> (Sender)</option>
                                    <?php elseif (!empty($notif['reference_id'])): ?>
                                    <option value="<?= intval($notif['reference_id']) ?>">Notification Requester</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="rs-attachments">
                            <div class="rs-attachments-head">
                                <span><i class="fas fa-paperclip me-1"></i>Attachments</span>
                                <button type="button" class="rs-add-attach-btn" onclick="document.getElementById('res-file-input').click()"><i class="fas fa-plus"></i></button>
                            </div>
                            <input type="file" id="res-file-input" name="res_attachments[]" multiple style="display:none;" onchange="rsShowFiles(this)">
                            <div class="rs-dropzone" id="rs-dropzone"
                                 ondragover="event.preventDefault();this.classList.add('rs-drag-over')"
                                 ondragleave="this.classList.remove('rs-drag-over')"
                                 ondrop="rsHandleDrop(event)">
                                <i class="fas fa-cloud-upload-alt rs-drop-icon"></i><span>Drag and drop files here</span>
                            </div>
                            <div id="rs-file-list" class="rs-file-list"></div>
                        </div>
                        <div class="rs-action-bar">
                            <button type="submit" class="rs-save-btn"><i class="fas fa-paper-plane me-2"></i>Save &amp; Send Email</button>
                            <button type="button" class="rs-cancel-btn" onclick="rsClearEditor()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="nd-sidebar">
            <div class="nd-sb-section-head" id="props-toggle" onclick="ndToggleSection('props-body','props-toggle')">
                PROPERTIES <i class="fas fa-chevron-up" id="props-toggle-icon"></i>
            </div>
            <div class="nd-sb-section-body" id="props-body">
                <div class="nd-sb-row"><div class="nd-sb-lbl">NOTIFICATION ID</div><div class="nd-sb-val nd-sb-id"><strong>#<?= $notif_id ?></strong><button class="nd-copy" onclick="navigator.clipboard.writeText('#<?= $notif_id ?>')" title="Copy"><i class="far fa-copy"></i></button></div></div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">STATUS</div><div class="nd-sb-val"><span class="nd-status-dot"></span> <?= $notif['is_read'] ? 'Read' : 'Unread' ?></div></div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">TYPE</div><div class="nd-sb-val"><span class="nd-type-pill"><?= htmlspecialchars(strtoupper($type_label)) ?></span></div></div>

                <?php if ($is_email_reply_notif): ?>
                <div class="nd-sb-subsection-label">SENDER INFO</div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">FROM</div><div class="nd-sb-val">
                    <div class="nd-sender-row">
                        <div class="nd-sender-av" style="background:#3182ce;"><?= strtoupper(substr($req_name, 0, 1)) ?></div>
                        <div><div class="nd-sender-name"><?= htmlspecialchars($req_name) ?></div><?php if ($req_email): ?><div class="nd-sender-email"><?= htmlspecialchars($req_email) ?></div><?php endif; ?></div>
                    </div>
                </div></div>
                <?php if (!empty($inbound_attachments)): ?>
                <div class="nd-sb-subsection-label">ATTACHMENTS</div>
                <?php foreach ($inbound_attachments as $att):
                    $_sbIsImg = attachmentIsImage($att);
                ?>
                <div class="nd-sb-row">
                    <div class="nd-sb-lbl"><?= $_sbIsImg ? 'IMAGE' : 'FILE' ?></div>
                    <div class="nd-sb-val" style="overflow:hidden;">
                        <?php if ($_sbIsImg): ?>
                        <img src="<?= htmlspecialchars($att['url']) ?>" style="width:100%;max-height:80px;object-fit:cover;border-radius:4px;cursor:pointer;margin-bottom:3px;" onclick="ndLightbox('<?= htmlspecialchars($att['url']) ?>','<?= addslashes($att['filename']) ?>')" loading="lazy">
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($att['url']) ?>" download="<?= htmlspecialchars($att['filename']) ?>" class="nd-sb-link" style="word-break:break-all;font-size:11px;"><?= htmlspecialchars($att['filename']) ?></a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php else: ?>
                <?php if (!empty($rt)): ?><div class="nd-sb-row"><div class="nd-sb-lbl">REFERENCE</div><div class="nd-sb-val"><?= htmlspecialchars(ucwords(str_replace('_',' ',$rt))) ?></div></div><?php endif; ?>
                <?php if (!empty($notif['reference_id'])): ?><div class="nd-sb-row"><div class="nd-sb-lbl">REFERENCE ID</div><div class="nd-sb-val"><?= intval($notif['reference_id']) ?></div></div><?php endif; ?>
                <?php endif; ?>

                <div class="nd-sb-row"><div class="nd-sb-lbl">RECEIVED</div><div class="nd-sb-val nd-sb-date"><?= $created_at ?></div></div>
                <?php if ($view_url): ?><div class="nd-sb-row"><div class="nd-sb-lbl">RECORD</div><div class="nd-sb-val"><a href="<?= htmlspecialchars($view_url) ?>" class="nd-sb-link">Open Record &rarr;</a></div></div><?php endif; ?>

                <?php if ($is_email_notif && $email_history && !$is_email_reply_notif): ?>
                <div class="nd-sb-subsection-label">EMAIL BROADCAST</div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">SENT BY</div><div class="nd-sb-val"><div class="nd-sender-row"><div class="nd-sender-av"><?= strtoupper(substr($email_history['sender_name'] ?? 'A', 0, 1)) ?></div><div><div class="nd-sender-name"><?= htmlspecialchars($email_history['sender_name'] ?? 'Administrator') ?></div><?php if (!empty($email_history['sender_email'])): ?><div class="nd-sender-email"><?= htmlspecialchars($email_history['sender_email']) ?></div><?php endif; ?></div></div></div></div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">SENT TO</div><div class="nd-sb-val"><span class="nd-sent-to-pill"><?= htmlspecialchars(strtoupper($email_history['recipient_type'] ?? 'SELECTED')) ?></span></div></div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">RECIPIENTS</div><div class="nd-sb-val"><?= intval($email_history['total_recipients'] ?? 0) ?></div></div>
                <div class="nd-sb-row"><div class="nd-sb-lbl">DELIVERED</div><div class="nd-sb-val nd-delivered"><?= intval($email_history['successful_sends'] ?? 0) ?></div></div>
                <?php if (intval($email_history['failed_sends'] ?? 0) > 0): ?><div class="nd-sb-row"><div class="nd-sb-lbl">FAILED</div><div class="nd-sb-val" style="color:#dc3545;font-weight:600;"><?= intval($email_history['failed_sends']) ?></div></div><?php endif; ?>
                <div class="nd-sb-row"><div class="nd-sb-lbl">ACTIONS</div><div class="nd-sb-val nd-sb-actions">
                    <a href="email-history.php" class="nd-sb-action-link"><i class="fas fa-history"></i> All Email History</a>
                    <?php if ($is_admin_user): ?><a href="email-history.php?id=<?= intval($notif['reference_id']) ?>" class="nd-sb-action-link"><i class="fas fa-list"></i> Recipient List</a><?php endif; ?>
                </div></div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<style>
.nd-wrapper{display:flex;flex-direction:column;background:#f5f6f8;min-height:calc(100vh - 56px);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:13px;color:#2d3748;}
.nd-topbar{display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid #e2e8f0;padding:0 16px;height:44px;gap:4px;flex-shrink:0;}
.nd-topbar-left,.nd-topbar-right{display:flex;align-items:center;gap:2px;}
.nd-topbar-btn{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:500;color:#4a5568;background:transparent;border:1px solid transparent;border-radius:5px;padding:5px 10px;cursor:pointer;text-decoration:none;white-space:nowrap;transition:background .12s,border-color .12s;}
.nd-topbar-btn:hover{background:#f0f4f8;border-color:#e2e8f0;color:#1a202c;}
.nd-actions-btn{border-color:#e2e8f0!important;}
.nd-icon-btn{padding:5px 9px!important;}
.nd-body{display:grid;grid-template-columns:1fr 280px;flex:1;overflow:hidden;}
.nd-left{display:flex;flex-direction:column;background:#fff;border-right:1px solid #e2e8f0;overflow-y:auto;}
.nd-header{display:flex;align-items:flex-start;gap:14px;padding:18px 20px 14px;border-bottom:1px solid #edf2f7;flex-shrink:0;}
.nd-header-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;flex-shrink:0;}
.nd-header-info{flex:1;min-width:0;}
.nd-header-top{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-bottom:5px;}
.nd-notif-id{font-size:13px;font-weight:700;color:#718096;flex-shrink:0;}
.nd-notif-title{font-size:16px;font-weight:700;color:#1a202c;margin:0;line-height:1.3;word-break:break-word;}
.nd-header-meta{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.nd-badge-type{font-size:11px;font-weight:600;background:#ebf4ff;color:#3182ce;border-radius:4px;padding:2px 8px;}
.nd-meta-pipe{color:#cbd5e0;}
.nd-meta-muted{font-size:12px;color:#718096;}
.nd-meta-link{font-size:12px;color:#3182ce;}
.nd-meta-attach-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;background:#fef3c7;color:#b45309;border-radius:4px;padding:2px 7px;}
.nd-requester-wrap{position:relative;display:inline-block;}
.nd-requester-trigger{cursor:pointer;border-bottom:1px dashed #3182ce;font-weight:600;}
.nd-hover-card{display:none;position:absolute;top:calc(100% + 8px);left:0;z-index:999;width:260px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.13);overflow:hidden;}
.nd-requester-wrap:hover .nd-hover-card,.nd-hover-card:hover{display:block;}
.nd-hc-top{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #f0f4f8;}
.nd-hc-avatar{width:44px;height:44px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#718096;flex-shrink:0;}
.nd-hc-name{font-size:14px;font-weight:700;color:#1a202c;line-height:1.3;}
.nd-hc-rows{padding:10px 16px 12px;display:flex;flex-direction:column;gap:7px;}
.nd-hc-row{display:flex;align-items:center;gap:8px;font-size:12.5px;color:#2d3748;}
.nd-hc-muted{color:#a0aec0!important;}
.nd-hc-icon{font-size:12px;color:#a0aec0;width:14px;text-align:center;flex-shrink:0;}
.nd-hc-divider{height:1px;background:#f0f4f8;margin:4px 0;}
.nd-hc-site-lbl{font-size:11px;font-weight:600;color:#a0aec0;min-width:34px;}
.nd-header-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;margin-top:2px;position:relative;}
.nd-reply-group{display:flex;border:1px solid #e2e8f0;border-radius:6px;overflow:visible;position:relative;}
.nd-reply-main{font-size:12.5px;font-weight:500;color:#4a5568;background:#fff;border:none;border-radius:6px 0 0 6px;padding:5px 12px;cursor:pointer;display:flex;align-items:center;gap:5px;transition:background .12s;}
.nd-reply-main:hover{background:#f7fafc;}
.nd-reply-caret{background:#fff;border:none;border-left:1px solid #e2e8f0;border-radius:0 6px 6px 0;padding:5px 8px;cursor:pointer;color:#718096;font-size:11px;}
.nd-reply-caret:hover{background:#f7fafc;}
.nd-reply-dropdown{display:none;position:absolute;top:calc(100% + 4px);right:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:160px;z-index:9999;overflow:hidden;}
.nd-reply-dropdown.open{display:block;}
.nd-reply-dd-item{display:flex;align-items:center;gap:8px;width:100%;padding:9px 14px;font-size:13px;color:#2d3748;background:none;border:none;text-align:left;cursor:pointer;transition:background .1s;}
.nd-reply-dd-item:hover{background:#f7fafc;}
.nd-reply-dd-item i{font-size:12px;color:#718096;width:14px;}
.nd-icon-action-btn{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:5px 9px;cursor:pointer;color:#718096;font-size:13px;}
.nd-icon-action-btn:hover{background:#f7fafc;}
.nd-status-box{display:flex;align-items:flex-start;gap:24px;background:#f7fafc;border:1px solid #e8edf3;border-radius:8px;margin:14px 20px;padding:14px 18px;flex-shrink:0;}
.nd-status-col{min-width:100px;}
.nd-transitions-col{flex:1;}
.nd-status-lbl{font-size:11px;font-weight:600;color:#a0aec0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;}
.nd-status-val{font-size:13.5px;font-weight:600;color:#2d3748;}
.nd-transitions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.nd-trans-btn{display:inline-flex;align-items:center;font-size:12.5px;font-weight:600;color:#4a5568;background:#fff;border:1px solid #e2e8f0;border-radius:5px;padding:5px 14px;text-decoration:none;cursor:pointer;transition:background .12s;}
.nd-trans-btn:hover{background:#edf2f7;color:#1a202c;}
.nd-trans-primary{background:#3182ce;color:#fff;border-color:#3182ce;}
.nd-trans-primary:hover{background:#2b6cb0;color:#fff;}
.nd-tabs-row{display:flex;border-bottom:2px solid #e2e8f0;padding:0 20px;gap:0;overflow-x:auto;flex-shrink:0;}
.nd-tab{font-size:13px;font-weight:500;color:#718096;background:transparent;border:none;border-bottom:2px solid transparent;padding:10px 14px;cursor:pointer;white-space:nowrap;margin-bottom:-2px;transition:color .12s;}
.nd-tab:hover{color:#3182ce;}
.nd-tab.active{color:#3182ce;border-bottom-color:#3182ce;font-weight:600;}
.nd-tab-pane{flex:1;}
.nd-hidden{display:none!important;}
.nd-filter-bar{display:flex;align-items:center;gap:10px;padding:9px 20px;border-bottom:1px solid #edf2f7;flex-wrap:wrap;}
.nd-filter-lbl{font-size:12px;font-weight:600;color:#718096;}
.nd-chk{display:flex;align-items:center;gap:5px;font-size:12.5px;color:#4a5568;cursor:pointer;user-select:none;}
.nd-chk-box{width:14px;height:14px;border-radius:3px;border:2px solid #cbd5e0;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;}
.nd-chk-blue{background:#3182ce;border-color:#3182ce;}
.nd-filter-right{display:flex;align-items:center;gap:8px;}
.nd-sort-btn{background:none;border:1px solid #e2e8f0;border-radius:5px;color:#a0aec0;font-size:11px;cursor:pointer;padding:3px 7px;display:flex;align-items:center;gap:3px;line-height:1;}
.nd-sort-num{font-size:9px;line-height:1;text-align:center;}
.nd-check-replies-btn{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#3182ce;background:#ebf4ff;border:1px solid #bee3f8;border-radius:5px;padding:4px 10px;cursor:pointer;transition:all .15s;}
.nd-check-replies-btn:hover{background:#bee3f8;}
.nd-check-replies-btn.loading{opacity:.7;pointer-events:none;}
.nd-check-replies-btn.loading i{animation:nd-spin .7s linear infinite;}
@keyframes nd-spin{to{transform:rotate(360deg);}}
.nd-poll-status{font-size:11px;color:#a0aec0;font-style:italic;white-space:nowrap;}
.nd-poll-status.success{color:#2d8a4e;}
.nd-poll-status.error{color:#c53030;}
.nd-date-pill-row{display:flex;justify-content:center;padding:14px 20px 8px;}
.nd-date-pill{font-size:12px;font-weight:600;color:#a0aec0;background:#f7fafc;border:1px solid #e2e8f0;border-radius:20px;padding:3px 14px;}
.nd-thread{display:flex;gap:12px;padding:4px 20px 20px;}
.nd-avatar-col{display:flex;flex-direction:column;align-items:center;flex-shrink:0;}
.nd-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;flex-shrink:0;}
.nd-avatar-line{width:2px;flex:1;background:#e2e8f0;margin-top:6px;min-height:20px;}
.nd-msg-card{flex:1;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.nd-msg-header{display:flex;align-items:center;gap:5px;padding:8px 14px;background:#f7fafc;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;}
.nd-msg-sender{font-size:13px;font-weight:700;color:#2d8a4e;}
.nd-msg-time{font-size:12px;color:#a0aec0;}
.nd-msg-header-end{margin-left:auto;display:flex;align-items:center;gap:6px;}
.nd-globe{color:#a0aec0;font-size:14px;cursor:pointer;}
.nd-globe:hover{color:#3182ce;}
.nd-attach-indicator{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#718096;background:#f0f4f8;border-radius:10px;padding:1px 7px;}
.nd-msg-body{padding:12px 14px;}
.nd-msg-subject-row{margin-bottom:3px;}
.nd-msg-subj-prefix{font-size:12px;font-weight:600;color:#718096;}
.nd-msg-subj{font-size:13px;font-weight:600;color:#2d3748;}
.nd-msg-to-row{margin-bottom:10px;}
.nd-msg-to-lbl{font-size:12px;color:#718096;}
.nd-msg-to-val{font-size:12px;color:#3182ce;}
.nd-msg-text{font-size:13.5px;line-height:1.7;color:#2d3748;word-break:break-word;}
.nd-email-body-extra{margin-top:14px;border-top:1px solid #edf2f7;padding-top:12px;}
.nd-extra-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#a0aec0;margin-bottom:6px;}
.nd-extra-text{font-size:13px;line-height:1.7;color:#4a5568;white-space:pre-wrap;word-break:break-word;}
.nd-msg-footer{display:flex;align-items:center;gap:1px;padding:7px 10px;border-top:1px solid #edf2f7;}
.nd-msg-action{background:none;border:none;color:#a0aec0;font-size:12.5px;padding:5px 8px;cursor:pointer;border-radius:4px;transition:background .12s,color .12s;display:inline-flex;align-items:center;gap:4px;}
.nd-msg-action:hover{background:#edf2f7;color:#4a5568;}
.nd-msg-action-end{margin-left:auto;}
.nd-thread-reply{margin-top:4px;}
.nd-msg-inbound{border-left:3px solid #3182ce;}
.nd-msg-outbound{border-left:3px solid #2d8a4e;}
.nd-reply-badge{display:inline-block;font-size:10px;font-weight:700;background:#ebf4ff;color:#3182ce;border-radius:3px;padding:1px 6px;margin-left:6px;vertical-align:middle;letter-spacing:.3px;}
.nd-reply-badge-out{background:#e6ffed;color:#2d8a4e;}
.nd-unread-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#e53e3e;}
.nd-no-replies{display:flex;align-items:center;gap:10px;padding:20px;font-size:13px;color:#a0aec0;font-style:italic;}
.nd-no-replies i{font-size:16px;}
.nd-attachments{margin-top:14px;padding-top:12px;border-top:1px solid #edf2f7;}
.nd-attach-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#a0aec0;margin-bottom:8px;display:flex;align-items:center;gap:5px;}
.nd-attach-grid{display:flex;flex-wrap:wrap;gap:8px;}
.nd-attach-img-wrap{position:relative;display:flex;flex-direction:column;width:100px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;background:#f7fafc;transition:box-shadow .15s;}
.nd-attach-img-wrap:hover{box-shadow:0 4px 12px rgba(0,0,0,.12);}
.nd-attach-thumb{width:100%;height:80px;object-fit:cover;cursor:pointer;display:block;}
.nd-attach-img-meta{padding:4px 6px;}
.nd-attach-name{font-size:10px;font-weight:600;color:#4a5568;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:88px;}
.nd-attach-size{font-size:10px;color:#a0aec0;display:block;}
.nd-attach-img-actions{display:none;position:absolute;top:4px;right:4px;gap:3px;}
.nd-attach-img-wrap:hover .nd-attach-img-actions{display:flex;}
.nd-attach-view,.nd-attach-dl{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:4px;background:rgba(0,0,0,.55);color:#fff;border:none;cursor:pointer;font-size:10px;text-decoration:none;}
.nd-attach-view:hover,.nd-attach-dl:hover{background:rgba(0,0,0,.75);}
.nd-attach-file-wrap{display:flex;align-items:center;gap:8px;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;background:#f7fafc;max-width:220px;transition:box-shadow .15s;}
.nd-attach-file-wrap:hover{box-shadow:0 2px 8px rgba(0,0,0,.08);}
.nd-attach-file-icon{font-size:20px;color:#a0aec0;flex-shrink:0;}
.nd-attach-file-info{flex:1;min-width:0;}
.nd-lightbox-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:20000;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;}
.nd-lightbox-box{display:flex;flex-direction:column;max-width:90vw;max-height:90vh;background:#1a202c;border-radius:10px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.5);}
.nd-lightbox-header{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#2d3748;flex-shrink:0;}
.nd-lightbox-filename{font-size:13px;font-weight:600;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60vw;}
.nd-lightbox-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}
.nd-lightbox-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:5px;background:rgba(255,255,255,.1);border:none;color:#e2e8f0;cursor:pointer;font-size:13px;text-decoration:none;transition:background .12s;}
.nd-lightbox-btn:hover{background:rgba(255,255,255,.2);}
.nd-lightbox-body{flex:1;overflow:auto;display:flex;align-items:center;justify-content:center;padding:16px;}
.nd-lightbox-img{max-width:100%;max-height:calc(90vh - 60px);object-fit:contain;border-radius:4px;}
.nd-detail-table{padding:10px 20px;}
.nd-detail-row{display:grid;grid-template-columns:150px 1fr;gap:8px;padding:9px 0;border-bottom:1px solid #f0f4f8;align-items:start;}
.nd-detail-row:last-child{border-bottom:none;}
.nd-dl{font-size:12px;font-weight:600;color:#a0aec0;}
.nd-dv{font-size:13px;color:#2d3748;}
.nd-dv-link{color:#3182ce;font-weight:600;text-decoration:none;}
.nd-dv-link:hover{text-decoration:underline;}
.nd-sidebar{background:#f8f9fa;border-left:1px solid #e2e8f0;display:flex;flex-direction:column;overflow-y:auto;}
.nd-sb-section-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;font-size:11px;font-weight:800;letter-spacing:.7px;color:#4a5568;background:#f8f9fa;border-bottom:1px solid #e2e8f0;cursor:pointer;user-select:none;flex-shrink:0;}
.nd-sb-section-head i{font-size:10px;color:#a0aec0;transition:transform .2s;}
.nd-sb-section-head.collapsed i{transform:rotate(180deg);}
.nd-sb-section-body{display:flex;flex-direction:column;background:#fff;}
.nd-sb-section-body.nd-collapsed{display:none;}
.nd-sb-subsection-label{font-size:10px;font-weight:800;letter-spacing:.7px;color:#a0aec0;padding:10px 16px 4px;border-top:1px solid #edf2f7;background:#f8f9fa;}
.nd-sb-row{display:grid;grid-template-columns:95px 1fr;gap:6px;padding:7px 16px;border-bottom:1px solid #f0f4f8;align-items:start;}
.nd-sb-lbl{font-size:10px;font-weight:700;letter-spacing:.5px;color:#a0aec0;padding-top:2px;}
.nd-sb-val{font-size:12.5px;color:#2d3748;font-weight:500;word-break:break-word;}
.nd-sb-id{display:flex;align-items:center;gap:5px;}
.nd-sb-id strong{font-size:13px;color:#1a202c;}
.nd-sb-date{color:#718096;}
.nd-sb-link{color:#3182ce;font-size:12px;font-weight:600;text-decoration:none;}
.nd-sb-link:hover{text-decoration:underline;}
.nd-copy{background:none;border:none;padding:0;cursor:pointer;color:#a0aec0;font-size:11px;}
.nd-copy:hover{color:#4a5568;}
.nd-status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#48bb78;margin-right:4px;vertical-align:middle;}
.nd-type-pill{display:inline-block;font-size:10.5px;font-weight:700;background:#edf2f7;color:#4a5568;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;letter-spacing:.3px;}
.nd-sent-to-pill{display:inline-block;font-size:11px;font-weight:700;background:#3182ce;color:#fff;border-radius:4px;padding:2px 10px;letter-spacing:.3px;}
.nd-delivered{color:#3182ce;font-weight:700;}
.nd-sender-row{display:flex;align-items:center;gap:8px;}
.nd-sender-av{width:28px;height:28px;border-radius:50%;background:#3182ce;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.nd-sender-name{font-size:12.5px;font-weight:600;color:#2d3748;line-height:1.3;}
.nd-sender-email{font-size:11px;color:#a0aec0;line-height:1.2;}
.nd-sb-actions{display:flex;flex-direction:column;gap:5px;}
.nd-sb-action-link{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#3182ce;text-decoration:none;}
.nd-sb-action-link:hover{text-decoration:underline;}
.nd-sb-action-link i{font-size:11px;}
.dt-section{border-bottom:1px solid #e8ecf0;}
.dt-section-head{font-size:12px;font-weight:700;color:#4a5568;padding:10px 20px;background:#f7f9fb;border-bottom:1px solid #e8ecf0;display:flex;align-items:center;gap:6px;}
.dt-section-head.dt-collapsible{cursor:pointer;user-select:none;}
.dt-section-head.dt-collapsible:hover{background:#eef2f7;}
.dt-caret{font-size:10px;color:#a0aec0;transition:transform .18s;}
.dt-caret.open{transform:rotate(90deg);}
.dt-collapsible-body{display:none;}
.dt-collapsible-body.open{display:block;}
.dt-two-col{display:grid;grid-template-columns:1fr 1fr;gap:0;}
.dt-col{padding:12px 20px;border-right:1px solid #f0f4f8;}
.dt-col:last-child{border-right:none;}
.dt-field{margin-bottom:14px;}
.dt-field:last-child{margin-bottom:0;}
.dt-label{font-size:11px;font-weight:600;color:#a0aec0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
.dt-value{font-size:13px;color:#2d3748;}
.dt-muted{color:#a0aec0;}
.dt-link{color:#3182ce;font-weight:600;text-decoration:none;font-size:13px;}
.dt-link:hover{text-decoration:underline;}
.dt-status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#48bb78;margin-right:4px;vertical-align:middle;}
.dt-message-block{background:#f7fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;font-size:13px;line-height:1.6;color:#4a5568;word-break:break-word;max-height:200px;overflow-y:auto;}
.dt-assoc-list{display:flex;flex-direction:column;gap:6px;padding:10px 20px;}
.dt-assoc-link{color:#3182ce;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:5px;}
.dt-assoc-link:hover{text-decoration:underline;}
.rs-wrap{display:flex;flex-direction:column;}
.rs-toolbar-area{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:14px 20px 10px;border-bottom:1px solid #e8ecf0;}
.rs-section-title{font-size:14px;font-weight:700;color:#2d3748;}
.rs-template-row{display:flex;align-items:center;gap:8px;}
.rs-tmpl-label{font-size:12px;color:#718096;white-space:nowrap;}
.rs-tmpl-select{font-size:12.5px;color:#4a5568;border:1px solid #e2e8f0;border-radius:5px;padding:4px 24px 4px 8px;background:#fff;cursor:pointer;outline:none;}
.rs-alert{padding:10px 20px;font-size:13px;}
.rs-alert-success{background:#f0fff4;color:#276749;border-bottom:1px solid #c6f6d5;}
.rs-alert-error{background:#fff5f5;color:#c53030;border-bottom:1px solid #fed7d7;}
.rs-subject-row{padding:10px 20px;border-bottom:1px solid #f0f4f8;}
.rs-field-label{font-size:11px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px;}
.rs-subject-input{width:100%;font-size:13px;color:#2d3748;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;outline:none;box-sizing:border-box;}
.rs-subject-input:focus{border-color:#3182ce;box-shadow:0 0 0 2px rgba(49,130,206,.15);}
.rs-editor-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:1px;padding:6px 12px;background:#f7f9fb;border-bottom:1px solid #e2e8f0;}
.rs-tb-btn{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:26px;padding:0 5px;font-size:12px;color:#4a5568;background:transparent;border:1px solid transparent;border-radius:4px;cursor:pointer;transition:background .1s,border-color .1s;}
.rs-tb-btn:hover{background:#edf2f7;border-color:#e2e8f0;}
.rs-tb-bold{font-weight:700;} .rs-tb-italic{font-style:italic;}
.rs-tb-sep{width:1px;height:18px;background:#e2e8f0;margin:0 4px;}
.rs-tb-select{font-size:12px;color:#4a5568;border:1px solid #e2e8f0;border-radius:4px;padding:2px 4px;background:#fff;cursor:pointer;outline:none;height:26px;}
.rs-font-sel{width:90px;} .rs-size-sel{width:44px;}
.rs-editor{min-height:180px;padding:14px 20px;font-size:13.5px;line-height:1.7;color:#2d3748;outline:none;border-bottom:1px solid #e2e8f0;word-break:break-word;}
.rs-editor:empty::before{content:attr(data-placeholder);color:#a0aec0;pointer-events:none;}
.rs-editor:focus{background:#fafcff;}
.rs-status-bar{display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:10px 20px;background:#f7f9fb;border-bottom:1px solid #e2e8f0;}
.rs-status-group,.rs-recipient-group{display:flex;align-items:center;gap:8px;}
.rs-status-select{font-size:12.5px;color:#2d3748;border:1px solid #e2e8f0;border-radius:5px;padding:4px 24px 4px 8px;background:#fff;cursor:pointer;outline:none;}
.rs-status-select:focus{border-color:#3182ce;}
.rs-attachments{border-bottom:1px solid #e2e8f0;}
.rs-attachments-head{display:flex;align-items:center;justify-content:space-between;padding:10px 20px;font-size:13px;font-weight:600;color:#4a5568;}
.rs-add-attach-btn{width:22px;height:22px;border-radius:50%;background:#edf2f7;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:11px;color:#718096;cursor:pointer;}
.rs-add-attach-btn:hover{background:#e2e8f0;}
.rs-dropzone{margin:0 20px 14px;border:2px dashed #e2e8f0;border-radius:8px;padding:24px;text-align:center;color:#a0aec0;font-size:13px;transition:border-color .15s,background .15s;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;}
.rs-dropzone:hover,.rs-drag-over{border-color:#3182ce;background:#ebf8ff;color:#3182ce;}
.rs-drop-icon{font-size:16px;}
.rs-file-list{padding:0 20px 10px;display:flex;flex-direction:column;gap:4px;}
.rs-file-item{display:flex;align-items:center;gap:8px;font-size:12px;color:#4a5568;background:#f7fafc;border:1px solid #e2e8f0;border-radius:5px;padding:5px 10px;}
.rs-file-item i{color:#a0aec0;}
.rs-action-bar{display:flex;align-items:center;gap:10px;padding:14px 20px;}
.rs-save-btn{display:inline-flex;align-items:center;font-size:13px;font-weight:600;color:#fff;background:#3182ce;border:none;border-radius:6px;padding:7px 20px;cursor:pointer;transition:background .15s;}
.rs-save-btn:hover{background:#2b6cb0;}
.rs-cancel-btn{display:inline-flex;align-items:center;font-size:13px;font-weight:500;color:#718096;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:7px 16px;cursor:pointer;transition:background .15s;}
.rs-cancel-btn:hover{background:#f7fafc;}
.nd-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;}
.nd-modal-box{background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.22);width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;animation:ndModalIn .18s ease;}
@keyframes ndModalIn{from{opacity:0;transform:scale(.95) translateY(8px);}to{opacity:1;transform:scale(1) translateY(0);}}
.nd-modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e2e8f0;flex-shrink:0;}
.nd-modal-title-text{font-size:15px;font-weight:700;color:#1a202c;}
.nd-modal-close{background:none;border:none;font-size:20px;color:#a0aec0;cursor:pointer;padding:0 4px;line-height:1;border-radius:4px;transition:color .12s,background .12s;}
.nd-modal-close:hover{color:#2d3748;background:#edf2f7;}
.nd-modal-field-row{display:flex;align-items:center;gap:10px;padding:8px 16px;}
.nd-modal-field-lbl{font-size:12px;font-weight:700;color:#a0aec0;text-transform:uppercase;letter-spacing:.4px;min-width:52px;flex-shrink:0;}
.nd-modal-to-display{font-size:13px;color:#2d3748;font-weight:500;flex:1;background:#f7fafc;border:1px solid #e2e8f0;border-radius:5px;padding:5px 10px;min-height:30px;word-break:break-word;}
.nd-modal-to-display.nd-modal-to-editable{background:#fff;cursor:text;outline:none;}
.nd-modal-to-display.nd-modal-to-editable:focus{border-color:#3182ce;box-shadow:0 0 0 2px rgba(49,130,206,.15);}
.nd-modal-subject-input{flex:1;font-size:13px;color:#2d3748;border:1px solid #e2e8f0;border-radius:5px;padding:5px 10px;outline:none;transition:border-color .15s;}
.nd-modal-subject-input:focus{border-color:#3182ce;box-shadow:0 0 0 2px rgba(49,130,206,.15);}
.nd-modal-toolbar{border-top:1px solid #e2e8f0;flex-shrink:0;}
.nd-modal-editor{min-height:180px;max-height:280px;overflow-y:auto;border-bottom:1px solid #e2e8f0;flex-shrink:0;}
.nd-modal-quoted{border-top:2px solid #e2e8f0;background:#f7fafc;flex-shrink:0;max-height:180px;overflow-y:auto;}
.nd-modal-quoted-header{display:flex;align-items:center;gap:8px;padding:8px 16px;font-size:12px;font-weight:600;color:#718096;border-bottom:1px solid #e2e8f0;position:sticky;top:0;background:#f7fafc;}
.nd-modal-quoted-header i{color:#a0aec0;font-size:11px;}
.nd-modal-quoted-toggle{margin-left:auto;background:none;border:none;color:#3182ce;font-size:12px;cursor:pointer;font-weight:600;padding:0;}
.nd-modal-quoted-body{padding:10px 16px;font-size:12.5px;line-height:1.65;color:#4a5568;white-space:pre-wrap;word-break:break-word;border-left:3px solid #e2e8f0;margin:8px 16px;border-radius:0 4px 4px 0;background:#fff;padding-left:12px;}
.nd-modal-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #e2e8f0;flex-shrink:0;}
.nd-modal-footer-left{display:flex;align-items:center;gap:8px;}
.nd-modal-footer-right{display:flex;align-items:center;}
.nd-modal-mode-badge{font-size:11px;font-weight:700;background:#ebf4ff;color:#3182ce;border-radius:4px;padding:2px 10px;letter-spacing:.3px;text-transform:uppercase;}
.nd-modal-mode-badge.reply_all{background:#e6ffed;color:#2d8a4e;}
.nd-modal-mode-badge.forward{background:#fef3c7;color:#b45309;}
@media(max-width:900px){.nd-body{grid-template-columns:1fr;}.nd-sidebar{border-left:none;border-top:1px solid #e2e8f0;}}
@media(max-width:700px){.dt-two-col{grid-template-columns:1fr;}.dt-col{border-right:none;border-bottom:1px solid #f0f4f8;padding:10px 14px;}}
</style>

<script>
function escHtml(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

var _activeTab = <?= json_encode($active_tab) ?>;

function ndActivateTab(tabName) {
    document.querySelectorAll('.nd-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.nd-tab-pane').forEach(function(p) { p.classList.add('nd-hidden'); });
    var btn  = document.querySelector('.nd-tab[data-tab="' + tabName + '"]');
    var pane = document.getElementById('tab-' + tabName);
    if (btn)  btn.classList.add('active');
    if (pane) pane.classList.remove('nd-hidden');
}

document.querySelectorAll('.nd-tab').forEach(function(tab) {
    tab.addEventListener('click', function() { ndActivateTab(this.dataset.tab); });
});

function ndToggleSection(bodyId, headId) {
    var body = document.getElementById(bodyId);
    var head = document.getElementById(headId);
    if (!body || !head) return;
    var isCollapsed = body.classList.contains('nd-collapsed');
    body.classList.toggle('nd-collapsed', !isCollapsed);
    head.classList.toggle('collapsed', !isCollapsed);
}

function dtToggle(headEl) {
    var body  = headEl.nextElementSibling;
    var caret = headEl.querySelector('.dt-caret');
    if (!body) return;
    var isOpen = body.classList.contains('open');
    body.classList.toggle('open', !isOpen);
    if (caret) caret.classList.toggle('open', !isOpen);
}

function ndLightbox(url, filename) {
    document.getElementById('nd-lightbox-img').src = url;
    document.getElementById('nd-lightbox-title').textContent = filename || '';
    var dl = document.getElementById('nd-lightbox-dl');
    dl.href = url; dl.download = filename || 'image';
    document.getElementById('nd-lightbox').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function ndLightboxClose() {
    document.getElementById('nd-lightbox').style.display = 'none';
    document.body.style.overflow = '';
}

var _ndCurrentMode = 'reply';

function ndOpenModal(mode, toEmail, toName, subject, quotedBody, modeLabel) {
    _ndCurrentMode = mode;
    var modal = document.getElementById('nd-compose-modal');
    if (!modal) return;

    document.getElementById('nd-modal-editor').innerHTML = '';
    document.getElementById('qr-body-hidden').value = '';
    document.getElementById('qr-body-fallback').value = '';
    document.getElementById('qr-mode-input').value = mode;

    var titles = { reply: 'Reply', reply_all: 'Reply All', forward: 'Forward' };
    var sends  = { reply: 'Send Reply', reply_all: 'Send Reply All', forward: 'Forward' };
    var badges = { reply: 'Reply', reply_all: 'Reply All', forward: 'Forward' };
    var icons  = { reply: 'fa-reply', reply_all: 'fa-reply-all', forward: 'fa-share' };

    document.getElementById('nd-modal-title').textContent = titles[mode] || 'Compose';
    document.getElementById('nd-modal-send-label').textContent = sends[mode] || 'Send';
    var mb = document.getElementById('nd-modal-mode-badge');
    mb.textContent = badges[mode] || mode;
    mb.className = 'nd-modal-mode-badge ' + mode;
    var qi = document.getElementById('nd-quoted-icon');
    if (qi) qi.className = 'fas ' + (icons[mode] || 'fa-reply');
    var ql = document.getElementById('nd-quoted-label');

    var toDisplay = document.getElementById('qr-to-display');
    var toEmailIn = document.getElementById('qr-to-email-input');
    var toNameIn  = document.getElementById('qr-to-name-input');

    if (mode === 'reply') {
        toDisplay.className = 'nd-modal-to-display';
        toDisplay.innerHTML = '<strong>' + escHtml(toName) + '</strong> &lt;' + escHtml(toEmail) + '&gt;';
        toDisplay.contentEditable = 'false';
        toEmailIn.value = toEmail;
        toNameIn.value  = toName;
        if (ql) ql.textContent = 'Original message';
    } else if (mode === 'reply_all') {
        toDisplay.className = 'nd-modal-to-display';
        toDisplay.innerHTML = '<em style="color:#718096;">All inbound senders + notification recipient</em>';
        toDisplay.contentEditable = 'false';
        toEmailIn.value = '';
        toNameIn.value  = 'All Recipients';
        if (ql) ql.textContent = 'Original message';
    } else if (mode === 'forward') {
        toDisplay.className = 'nd-modal-to-display nd-modal-to-editable';
        toDisplay.contentEditable = 'true';
        toDisplay.textContent = '';
        if (!toDisplay.getAttribute('data-fwd-listener')) {
            toDisplay.setAttribute('data-fwd-listener', '1');
            toDisplay.addEventListener('input', function() {
                document.getElementById('qr-to-email-input').value = this.textContent.trim();
            });
        }
        toEmailIn.value = '';
        toNameIn.value  = 'Recipient';
        if (ql) ql.textContent = 'Forwarded message';
    }

    var subjIn = document.getElementById('qr-subject-input');
    if (subjIn) subjIn.value = subject || '';

    var quotedEl   = document.getElementById('nd-modal-quoted');
    var quotedBody = document.getElementById('nd-quoted-body');
    if (quotedEl && quotedBody) {
        var qText = arguments[4] || '';
        quotedEl.style.display = qText.trim() ? '' : 'none';
        if (qText.trim()) quotedBody.textContent = qText;
    }

    modal.style.display = 'flex';
    setTimeout(function() { document.getElementById('nd-modal-editor').focus(); }, 50);
}

function ndCloseModal() {
    var m = document.getElementById('nd-compose-modal');
    if (m) m.style.display = 'none';
}
function ndToggleQuoted() {
    var body = document.getElementById('nd-quoted-body');
    var btn  = document.getElementById('nd-quoted-toggle-btn');
    if (!body || !btn) return;
    var hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    btn.textContent = hidden ? 'Hide' : 'Show';
}
function ndMExec(cmd, val) {
    document.getElementById('nd-modal-editor').focus();
    document.execCommand(cmd, false, val || null);
}
function ndMInsertLink() { var u = prompt('Enter URL:'); if (u) ndMExec('createLink', u); }
function ndMSyncBody() {
    var e = document.getElementById('nd-modal-editor');
    var h = document.getElementById('qr-body-hidden');
    var f = document.getElementById('qr-body-fallback');
    if (!e || !h) return;
    var t = e.innerText.trim();
    h.value = t;
    if (f) f.value = t;
}

function rsExec(cmd, val) { document.getElementById('rs-editor').focus(); document.execCommand(cmd, false, val || null); }
function rsSyncBody() {
    var e = document.getElementById('rs-editor');
    var h = document.getElementById('res-body-hidden');
    var f = document.getElementById('res-body-text-fallback');
    if (!e || !h) return;
    var t = e.innerText.trim();
    h.value = t;
    if (f) f.value = t;
}
function rsClearEditor() {
    document.getElementById('rs-editor').innerHTML = '';
    document.getElementById('res-body-hidden').value = '';
    document.getElementById('res-body-text-fallback').value = '';
    document.getElementById('rs-file-list').innerHTML = '';
}
function rsInsertLink() { var u = prompt('Enter URL:'); if (u) rsExec('createLink', u); }
function rsShowFiles(input) {
    var list = document.getElementById('rs-file-list');
    list.innerHTML = '';
    Array.from(input.files).forEach(function(f) {
        var item = document.createElement('div');
        item.className = 'rs-file-item';
        item.innerHTML = '<i class="fas fa-file"></i><span>' + f.name + '</span><small style="color:#a0aec0;margin-left:auto;">' + (f.size/1024).toFixed(1) + ' KB</small>';
        list.appendChild(item);
    });
}
function rsHandleDrop(e) {
    e.preventDefault();
    document.getElementById('rs-dropzone').classList.remove('rs-drag-over');
    var dt = e.dataTransfer;
    if (dt.files.length) { var input = document.getElementById('res-file-input'); input.files = dt.files; rsShowFiles(input); }
}
var rsTemplates = {
    acknowledged: { subject: 'Your Concern Has Been Acknowledged', body: 'Dear Resident,\n\nWe have received your concern and would like to inform you that it has been acknowledged by the Barangay office. Our team is currently reviewing the matter and will provide an update as soon as possible.\n\nThank you for bringing this to our attention.\n\nSincerely,\nBarangay Office' },
    resolved:     { subject: 'Resolution Notice \u2013 Issue Resolved', body: 'Dear Resident,\n\nWe are pleased to inform you that your concern has been resolved. The Barangay has taken the necessary action to address the matter.\n\nIf you have further questions or concerns, please do not hesitate to contact our office.\n\nSincerely,\nBarangay Office' },
    followup:     { subject: 'Follow-Up Required \u2013 Barangay Office', body: 'Dear Resident,\n\nThis is to inform you that your concern is still under review and requires further follow-up. We appreciate your patience and cooperation.\n\nOur office will contact you shortly with an update.\n\nSincerely,\nBarangay Office' },
    noaction:     { subject: 'Notice \u2013 No Further Action Required', body: 'Dear Resident,\n\nAfter careful review of your concern, the Barangay has determined that no further action is required at this time.\n\nShould you have any additional information or a new concern, please feel free to reach out to our office.\n\nSincerely,\nBarangay Office' }
};
function rsApplyTemplate(key) {
    if (!key || !rsTemplates[key]) return;
    var t = rsTemplates[key];
    document.getElementById('res-subject').value = t.subject;
    document.getElementById('rs-editor').innerText = t.body;
    rsSyncBody();
    document.getElementById('res-template-sel').value = '';
}

var ndPolling = false;
var ndNotifId = <?= $notif_id ?>;

function ndCheckReplies(silent) {
    if (ndPolling) return;
    ndPolling = true;
    var btn    = document.getElementById('nd-check-replies-btn');
    var status = document.getElementById('nd-poll-status');
    if (btn) btn.classList.add('loading');
    if (!silent && status) { status.className = 'nd-poll-status'; status.textContent = 'Checking\u2026'; }

    var knownIds = [];
    document.querySelectorAll('#tab-conversations .nd-thread-reply[data-reply-id]').forEach(function(el) {
        var rid = parseInt(el.getAttribute('data-reply-id') || '0', 10);
        if (rid > 0) knownIds.push(rid);
    });

    var fd = new FormData();
    fd.append('notification_id', ndNotifId);
    knownIds.forEach(function(id) { fd.append('known_ids[]', id); });

    fetch('check_replies.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            ndPolling = false;
            if (btn) btn.classList.remove('loading');
            if (!data.success) {
                if (status) { status.className = 'nd-poll-status error'; status.textContent = '\u26a0 ' + (data.error || 'Error'); }
                return;
            }
            if (data.processed > 0 && data.new_replies && data.new_replies.length > 0) {
                data.new_replies.forEach(function(reply) { ndInjectReply(reply); });
                var nr = document.querySelector('.nd-no-replies'); if (nr) nr.remove();
                if (status) { status.className = 'nd-poll-status success'; status.textContent = '\u2713 ' + data.processed + ' new reply!'; setTimeout(function() { if (status) status.textContent = ''; }, 5000); }
            } else {
                if (!silent && status) { status.className = 'nd-poll-status'; status.textContent = 'No new replies.'; setTimeout(function() { if (status) status.textContent = ''; }, 3000); }
            }
        })
        .catch(function() {
            ndPolling = false;
            if (btn) btn.classList.remove('loading');
            if (status) { status.className = 'nd-poll-status error'; status.textContent = '\u26a0 Network error'; }
        });
}

function ndIsImage(att) {
    return !!att.is_image
        || (att.mime && att.mime.indexOf('image/') === 0)
        || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(att.filename || '');
}

function ndBuildAttachHtml(attachments) {
    if (!attachments || !attachments.length) return '';
    var html = '<div class="nd-attachments"><div class="nd-attach-label"><i class="fas fa-paperclip"></i> Attachments (' + attachments.length + ')</div><div class="nd-attach-grid">';
    attachments.forEach(function(att) {
        var url   = escHtml(att.url || '');
        var fname = escHtml(att.filename || 'file');
        var urlE  = (att.url || '').replace(/'/g,"\\'");
        var fnE   = (att.filename || 'file').replace(/'/g,"\\'");
        var isImg = ndIsImage(att);
        if (isImg) {
            html += '<div class="nd-attach-img-wrap">'
                + '<img src="' + url + '" alt="' + fname + '" class="nd-attach-thumb" onclick="ndLightbox(\'' + urlE + '\',\'' + fnE + '\')" loading="lazy">'
                + '<div class="nd-attach-img-meta"><span class="nd-attach-name">' + fname + '</span></div>'
                + '<div class="nd-attach-img-actions">'
                + '<a href="' + url + '" download="' + fname + '" class="nd-attach-dl" title="Download"><i class="fas fa-download"></i></a>'
                + '<button class="nd-attach-view" onclick="ndLightbox(\'' + urlE + '\',\'' + fnE + '\')" title="View"><i class="fas fa-expand-alt"></i></button>'
                + '</div></div>';
        } else {
            html += '<div class="nd-attach-file-wrap">'
                + '<i class="fas fa-file nd-attach-file-icon"></i>'
                + '<div class="nd-attach-file-info"><span class="nd-attach-name">' + fname + '</span></div>'
                + '<a href="' + url + '" download="' + fname + '" class="nd-attach-dl" title="Download"><i class="fas fa-download"></i></a>'
                + '</div>';
        }
    });
    html += '</div></div>';
    return html;
}

function ndInjectReply(reply) {
    var thread = document.querySelector('#tab-conversations');
    if (!thread) return;

    if (thread.querySelector('.nd-thread-reply[data-reply-id="' + reply.id + '"]')) return;

    var attachments = reply.attachments || [];
    var hasAttach   = attachments.length > 0;
    var avatarBg    = 'background:#ebf4ff;color:#3182ce;border:1.5px solid rgba(49,130,206,.13);';
    var avatarLtr   = (reply.from_name || reply.from_email || 'R').charAt(0).toUpperCase();
    var bodyText    = (reply.body_text || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    var fromEsc     = (reply.from_email || '').replace(/'/g,"\\'");
    var nameEsc     = (reply.from_name  || 'Resident').replace(/'/g,"\\'");
    var subjEsc     = ('Re: ' + (reply.subject || '')).replace(/'/g,"\\'");
    var attachHtml  = ndBuildAttachHtml(attachments);
    var attachInd   = hasAttach ? '<span class="nd-attach-indicator" title="' + attachments.length + ' attachment(s)"><i class="fas fa-paperclip"></i>' + attachments.length + '</span>' : '';

    var html = '<div class="nd-thread nd-thread-reply nd-thread-inbound" data-reply-id="' + reply.id + '">'
        + '<div class="nd-avatar-col"><div class="nd-avatar" style="' + avatarBg + ' font-size:13px;">' + avatarLtr + '</div><div class="nd-avatar-line"></div></div>'
        + '<div class="nd-msg-card nd-msg-inbound">'
        + '<div class="nd-msg-header"><span class="nd-msg-sender" style="color:#3182ce;">' + escHtml(reply.from_name || reply.from_email) + '<span class="nd-reply-badge">Resident Reply</span></span>'
        + '<span class="nd-msg-time">\u00b7 ' + escHtml(reply.created_at) + '</span>'
        + '<div class="nd-msg-header-end"><i class="fas fa-envelope nd-globe"></i><span class="nd-unread-dot"></span>' + attachInd + '</div></div>'
        + '<div class="nd-msg-body"><div class="nd-msg-subject-row"><span class="nd-msg-subj-prefix">Subject: </span><span class="nd-msg-subj">' + escHtml(reply.subject || '') + '</span></div>'
        + '<div class="nd-msg-to-row"><span class="nd-msg-to-lbl">From: </span><span class="nd-msg-to-val">' + escHtml(reply.from_email) + '</span></div>'
        + '<div class="nd-msg-text">' + bodyText + '</div>' + attachHtml + '</div>'
        + '<div class="nd-msg-footer">'
        + '<button class="nd-msg-action" onclick="ndOpenModal(\'reply\',\'' + fromEsc + '\',\'' + nameEsc + '\',\'' + subjEsc + '\',\'\',\'reply\')"><i class="fas fa-reply"></i> Reply</button>'
        + '<button class="nd-msg-action" onclick="ndOpenModal(\'reply_all\',\'\',\'All Recipients\',\'' + subjEsc + '\',\'\',\'reply_all\')"><i class="fas fa-reply-all"></i> Reply All</button>'
        + '<button class="nd-msg-action" onclick="ndOpenModal(\'forward\',\'\',\'\',\'Fwd: ' + (reply.subject || '').replace(/'/g,"\\'") + '\',\'\',\'forward\')"><i class="fas fa-share"></i> Forward</button>'
        + '<button class="nd-msg-action nd-msg-action-end"><i class="fas fa-ellipsis-h"></i></button>'
        + '</div></div></div>';

    var nr = thread.querySelector('.nd-no-replies');
    if (nr) nr.insertAdjacentHTML('beforebegin', html);
    else thread.insertAdjacentHTML('beforeend', html);
}

document.addEventListener('DOMContentLoaded', function() {

    ndActivateTab(_activeTab);

    var caret    = document.getElementById('nd-reply-caret-btn');
    var dropdown = document.getElementById('nd-reply-dropdown');
    if (caret && dropdown) {
        caret.addEventListener('click', function(e) { e.stopPropagation(); dropdown.classList.toggle('open'); });
        document.addEventListener('click', function() { dropdown.classList.remove('open'); });
        dropdown.addEventListener('click', function(e) { e.stopPropagation(); dropdown.classList.remove('open'); });
    }

    var closeBtn = document.getElementById('nd-modal-close-btn');
    if (closeBtn) closeBtn.addEventListener('click', ndCloseModal);
    var cancelBtn = document.getElementById('nd-modal-cancel-btn');
    if (cancelBtn) cancelBtn.addEventListener('click', ndCloseModal);

    var modal = document.getElementById('nd-compose-modal');
    if (modal) modal.addEventListener('click', function(e) { if (e.target === this) ndCloseModal(); });

    var qtBtn = document.getElementById('nd-quoted-toggle-btn');
    if (qtBtn) qtBtn.addEventListener('click', ndToggleQuoted);

    var composeForm = document.getElementById('nd-compose-form');
    if (composeForm) {
        composeForm.addEventListener('submit', function() {
            ndMSyncBody();
            if (_ndCurrentMode === 'forward') {
                var td = document.getElementById('qr-to-display');
                var ti = document.getElementById('qr-to-email-input');
                if (td && td.contentEditable === 'true') ti.value = td.textContent.trim();
            }
        });
    }

    var resForm = document.getElementById('resolution-form');
    if (resForm) resForm.addEventListener('submit', function() { rsSyncBody(); });

    var lbClose = document.getElementById('nd-lightbox-close');
    if (lbClose) lbClose.addEventListener('click', ndLightboxClose);
    var lb = document.getElementById('nd-lightbox');
    if (lb) lb.addEventListener('click', function(e) { if (e.target === this) ndLightboxClose(); });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var lb2 = document.getElementById('nd-lightbox');
            if (lb2 && lb2.style.display === 'flex') ndLightboxClose();
            ndCloseModal();
        }
    });

    setInterval(function() {
        var ct = document.getElementById('tab-conversations');
        if (ct && !ct.classList.contains('nd-hidden')) ndCheckReplies(true);
    }, 30000);
    setTimeout(function() { ndCheckReplies(true); }, 1500);
});
</script>

<?php include '../../includes/footer.php'; ?>