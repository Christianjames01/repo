<?php
/**
 * Compose / Reply Page - modules/notifications/compose-reply.php
 * REDESIGNED: ManageEngine ServiceDesk compose UI
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$user_role = getCurrentUserRole();
$allowed_roles = ['Super Admin','Super Administrator','Admin','Staff'];
$is_admin = in_array($user_role, $allowed_roles);

if (!$is_admin) {
    $_SESSION['error_message'] = 'Access denied. Admin privileges required.';
    header('Location: index.php'); exit();
}

$notif_id  = intval($_GET['notif_id']  ?? $_GET['id'] ?? 0);
$email_id  = intval($_GET['email_id']  ?? 0);
$mode      = $_GET['mode']      ?? 'reply';
$to_email  = trim($_GET['to_email']  ?? '');
$to_name   = trim($_GET['to_name']   ?? 'Resident');
$subject   = trim($_GET['subject']   ?? '');
$reply_id  = intval($_GET['reply_id']  ?? 0);

// Load notification (5-layer fallback)
$notif = null; $email_row = null;
if ($notif_id>0) $notif=fetchOne($conn,"SELECT * FROM tbl_notifications WHERE notification_id=? LIMIT 1",[$notif_id],'i');
if (!$notif&&$email_id>0) {
    $notif=fetchOne($conn,"SELECT * FROM tbl_notifications WHERE reference_id=? AND (type='email_reply' OR reference_type='email_inbox') ORDER BY notification_id DESC LIMIT 1",[$email_id],'i');
    if ($notif) $notif_id=(int)$notif['notification_id'];
}
if (!$notif&&isset($_SESSION['user_id'])) {
    $uid_tmp=(int)$_SESSION['user_id'];
    $notif=fetchOne($conn,"SELECT * FROM tbl_notifications WHERE user_id=? AND (type='email_reply' OR reference_type='email_inbox') ORDER BY notification_id DESC LIMIT 1",[$uid_tmp],'i');
    if ($notif) $notif_id=(int)$notif['notification_id'];
}
if (!$notif) {
    $tbl_eh=$conn->query("SHOW TABLES LIKE 'tbl_email_history'");
    if ($tbl_eh&&$tbl_eh->num_rows>0) {
        if ($email_id>0) $email_row=fetchOne($conn,"SELECT * FROM tbl_email_history WHERE id=? LIMIT 1",[$email_id],'i');
        if (!$email_row) {
            $email_row=fetchOne($conn,"SELECT * FROM tbl_email_history ORDER BY id DESC LIMIT 1",[],'');
            if ($email_row) $email_id=(int)($email_row['id']??0);
        }
    }
    if ($email_row) {
        $notif=['notification_id'=>0,'title'=>$email_row['email_title']??'Email Notification',
                'message'=>$email_row['email_message']??'','created_at'=>$email_row['sent_at']??date('Y-m-d H:i:s'),
                'is_read'=>1,'type'=>'email_reply','reference_type'=>'email_inbox','reference_id'=>$email_id];
        if (empty($to_email)&&!empty($email_row['recipient_details'])) $to_email=$email_row['recipient_details'];
    }
}
if (!$notif) {
    $notif=['notification_id'=>0,'title'=>$subject?:'Compose Email','message'=>'',
            'created_at'=>date('Y-m-d H:i:s'),'is_read'=>1,
            'type'=>'email_reply','reference_type'=>'email_inbox','reference_id'=>0];
}

if (!$subject) { $subject=($mode==='forward'?'Fwd: ':'Re: ').($notif['title']??'Email'); }

// Quoted content
$quoted_body=''; $quoted_from=''; $quoted_time='';
$tbl_replies_exists=false;
$tblr=$conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
if ($tblr&&$tblr->num_rows>0) $tbl_replies_exists=true;
if ($reply_id&&$tbl_replies_exists) {
    $orig=fetchOne($conn,"SELECT * FROM tbl_email_replies WHERE id=?",[$reply_id],'i');
    if ($orig) {
        $quoted_body=strip_tags($orig['body_text']??$orig['body_html']??'');
        $quoted_from=$orig['from_name']?$orig['from_name'].' <'.$orig['from_email'].'>'  :$orig['from_email']??'';
        $quoted_time=$orig['created_at']?date('D, M j, Y \a\t g:i A',strtotime($orig['created_at'])):'';
    }
}
if (!$quoted_body) {
    $quoted_body=$notif['message']??'';
    $quoted_from='Barangay System';
    $quoted_time=!empty($notif['created_at'])?date('D, M j, Y \a\t g:i A',strtotime($notif['created_at'])):'';
}

// Reply-all recipients
$reply_all_recipients=[];
if ($mode==='reply_all'&&$tbl_replies_exists) {
    $ref_notif_id=$notif_id?:0;
    if ($ref_notif_id>0) {
        $inb=$conn->prepare("SELECT DISTINCT from_email,from_name FROM tbl_email_replies WHERE notification_id=? AND direction='inbound'");
        $inb->bind_param('i',$ref_notif_id); $inb->execute();
        $res=$inb->get_result();
        while ($row=$res->fetch_assoc()) {
            if (!empty($row['from_email'])) $reply_all_recipients[]=['email'=>$row['from_email'],'name'=>$row['from_name']?:$row['from_email']];
        }
        $inb->close();
    }
    if ($to_email&&!in_array($to_email,array_column($reply_all_recipients,'email')))
        $reply_all_recipients[]=['email'=>$to_email,'name'=>$to_name];
}

// Handle send
$send_success=''; $send_error='';
if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['compose_send'])) {
    $helper_loaded=false;
    foreach([__DIR__.'/../../includes/email_helper.php',__DIR__.'/../../includes/phpmailer/mailer.php'] as $hp) {
        if (file_exists($hp)){ require_once $hp; $helper_loaded=true; break; }
    }
    if (!$helper_loaded) {
        $send_error='Email helper not found.';
    } else {
        $post_subject  = trim($_POST['post_subject']  ?? '');
        $post_body_raw = trim($_POST['post_body_text'] ?? '');
        $post_mode     = $_POST['post_mode']     ?? 'reply';
        $post_to       = trim($_POST['post_to']   ?? '');
        if (!$post_subject)                               $send_error='Subject is required.';
        elseif (!$post_body_raw)                          $send_error='Message body is required.';
        elseif ($post_mode!=='reply_all'&&!$post_to)      $send_error='Recipient (To) is required.';
        else {
            $recipients=[];
            if ($post_mode==='reply_all') {
                foreach ($reply_all_recipients as $r) $recipients[]=['email'=>$r['email'],'full_name'=>$r['name']];
            } else {
                foreach (explode(',',$post_to) as $addr) { $addr=trim($addr); if ($addr) $recipients[]=['email'=>$addr,'full_name'=>$addr]; }
            }
            if (empty($recipients)) { $send_error='No valid recipients found.'; }
            else {
                $body_html=function_exists('getEmailTemplate')
                    ? getEmailTemplate(['title'=>htmlspecialchars($post_subject),'greeting'=>'Dear Resident,','message'=>nl2br(htmlspecialchars($post_body_raw)),'footer_text'=>(defined('APP_NAME')?APP_NAME:'Barangay System').' — Barangay Office'])
                    : '<html><body><h2>'.htmlspecialchars($post_subject).'</h2><p>'.nl2br(htmlspecialchars($post_body_raw)).'</p></body></html>';
                $send_fn=function_exists('sendEmail')?'sendEmail':(function_exists('sendNotificationEmail')?'sendNotificationEmail':null);
                if (!$send_fn) { $send_error='No send function available.'; }
                else {
                    $sent=0; $fails=0; $fail_list=[];
                    foreach ($recipients as $r) {
                        try { $ok=call_user_func($send_fn,$r['email'],$post_subject,$body_html,$r['full_name']); }
                        catch (Throwable $e){ error_log("compose-reply: ".$e->getMessage()); $ok=false; }
                        if ($ok) {
                            $sent++;
                            if ($tbl_replies_exists&&$notif_id>0) {
                                $mail_from=defined('MAIL_FROM_EMAIL')?MAIL_FROM_EMAIL:'';
                                $out=$conn->prepare("INSERT INTO tbl_email_replies (notification_id,from_email,from_name,subject,body_text,direction,is_read) VALUES (?,?,'Barangay System',?,?,'outbound',1)");
                                if ($out){ $out->bind_param('isss',$notif_id,$mail_from,$post_subject,$post_body_raw); $out->execute(); $out->close(); }
                            }
                        } else { $fails++; $fail_list[]=$r['email']; }
                    }
                    if ($sent>0) $send_success="Email sent to <strong>{$sent}</strong> recipient(s)".($fails>0?" ({$fails} failed)":'').".";
                    else $send_error="No emails sent. Failed: ".htmlspecialchars(implode(', ',$fail_list));
                }
            }
        }
    }
}

$mode_labels=['reply'=>'Reply','reply_all'=>'Reply All','forward'=>'Forward'];
$mode_label=$mode_labels[$mode]??'Compose';
$back_url=$notif_id>0?'notification-detail.php?id='.$notif_id:($email_id>0?'email-details.php?id='.$email_id:'index.php');
$base_qs='notif_id='.$notif_id.'&email_id='.$email_id.'&to_email='.urlencode($to_email).'&to_name='.urlencode($to_name).'&reply_id='.$reply_id;

$reply_all_to_display=implode(', ',array_map(fn($r)=>($r['name']!==$r['email'])?$r['name'].' <'.$r['email'].'>':$r['email'],$reply_all_recipients));

$page_title = $mode_label.' — '.htmlspecialchars($notif['title']??'Email');
include '../../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --tk-bg:#0f1117;--tk-bg2:#161b27;--tk-bg3:#1c2333;--tk-bg4:#232b3e;
  --tk-border:#2a3347;--tk-border2:#334060;
  --tk-text:#e2e8f0;--tk-text2:#94a3b8;--tk-text3:#4a5878;
  --tk-accent:#f59e0b;--tk-accent2:#fbbf24;
  --tk-blue:#3b82f6;--tk-blue2:#60a5fa;
  --tk-green:#10b981;--tk-red:#ef4444;--tk-orange:#f97316;
  --tk-sky:#06b6d4;--tk-purple:#8b5cf6;
  --tk-font:'Outfit',sans-serif;--tk-mono:'JetBrains Mono',monospace;
  --tk-radius:8px;--tk-shadow:0 4px 24px rgba(0,0,0,.4);
  --sb-w:260px;
}
.tk-compose *,
.tk-compose *::before,
.tk-compose *::after { box-sizing:border-box; }
.tk-compose {
  font-family:var(--tk-font);background:var(--tk-bg);
  color:var(--tk-text);font-size:13.5px;
  min-height:100vh;margin:-20px;padding:0;
  display:flex;flex-direction:column;
}

/* ── TOPBAR ── */
.tk-topbar {
  display:flex;align-items:center;gap:10px;height:52px;padding:0 20px;
  background:var(--tk-bg2);border-bottom:1px solid var(--tk-border);
  position:sticky;top:0;z-index:90;flex-shrink:0;
}
.tk-back { display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:var(--tk-text2);text-decoration:none;transition:color .12s; }
.tk-back:hover { color:var(--tk-accent);text-decoration:none; }
.tk-topbar-divider { width:1px;height:20px;background:var(--tk-border);flex-shrink:0; }
.tk-topbar-title { font-size:13.5px;font-weight:600;color:var(--tk-text2);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.tk-topbar-right { display:flex;align-items:center;gap:6px;flex-shrink:0; }

/* ── MODE BADGE ── */
.tk-mode-badge {
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 12px;border-radius:20px;
  font-size:11px;font-weight:700;letter-spacing:.3px;white-space:nowrap;
}
.mode-reply     { background:rgba(59,130,246,.12);color:var(--tk-blue2); }
.mode-reply_all { background:rgba(16,185,129,.12);color:var(--tk-green); }
.mode-forward   { background:rgba(245,158,11,.12);color:var(--tk-accent); }

/* ── BUTTONS ── */
.tk-btn {
  display:inline-flex;align-items:center;gap:5px;
  padding:6px 14px;border-radius:var(--tk-radius);
  font-family:var(--tk-font);font-size:12.5px;font-weight:600;
  cursor:pointer;border:1px solid transparent;
  text-decoration:none;transition:all .14s;white-space:nowrap;
}
.tk-btn-sm { padding:5px 11px;font-size:12px; }
.tk-btn-ghost   { background:var(--tk-bg3);color:var(--tk-text2);border-color:var(--tk-border); }
.tk-btn-ghost:hover   { background:var(--tk-bg4);color:var(--tk-text);border-color:var(--tk-border2);text-decoration:none; }
.tk-btn-primary { background:var(--tk-accent);color:#0f1117;border-color:var(--tk-accent); }
.tk-btn-primary:hover { background:var(--tk-accent2);color:#0f1117;text-decoration:none; }
.tk-btn-danger  { background:rgba(239,68,68,.12);color:var(--tk-red);border-color:rgba(239,68,68,.25); }
.tk-btn-danger:hover  { background:rgba(239,68,68,.2);color:var(--tk-red);text-decoration:none; }
.tk-btn-success { background:rgba(16,185,129,.12);color:var(--tk-green);border-color:rgba(16,185,129,.25); }
.tk-btn-success:hover { background:rgba(16,185,129,.2);color:var(--tk-green);text-decoration:none; }
.tk-btn-sky     { background:rgba(6,182,212,.12);color:var(--tk-sky);border-color:rgba(6,182,212,.25); }
.tk-btn-sky:hover     { background:rgba(6,182,212,.2);color:var(--tk-sky);text-decoration:none; }

/* ── STRIP / TICKET INFO BAR ── */
.tk-strip {
  display:flex;align-items:center;gap:12px;
  padding:0 22px;height:52px;
  background:var(--tk-bg3);border-bottom:1px solid var(--tk-border);flex-shrink:0;
}
.tk-strip-icon {
  width:34px;height:34px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  font-size:14px;color:#fff;flex-shrink:0;
}
.tk-strip-meta { flex:1;min-width:0; }
.tk-strip-name { font-size:13px;font-weight:700;color:var(--tk-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.tk-strip-sub  { font-size:11px;color:var(--tk-text3); }
.tk-strip-actions { display:flex;align-items:center;gap:4px;flex-shrink:0; }
.tk-mode-switch {
  width:28px;height:28px;border-radius:7px;
  display:flex;align-items:center;justify-content:center;
  font-size:11.5px;color:var(--tk-text3);
  background:var(--tk-bg4);border:1px solid var(--tk-border);
  text-decoration:none;transition:all .12s;
}
.tk-mode-switch:hover { background:var(--tk-border);color:var(--tk-text);text-decoration:none; }
.tk-mode-switch.sw-reply   { background:rgba(59,130,246,.15);color:var(--tk-blue2);border-color:rgba(59,130,246,.3); }
.tk-mode-switch.sw-forward { background:rgba(245,158,11,.12);color:var(--tk-accent);border-color:rgba(245,158,11,.25); }

/* ── MAIN GRID ── */
.tk-page {
  flex:1;display:grid;
  grid-template-columns:1fr var(--sb-w);
  min-height:0;overflow:hidden;
}
.tk-form-area { display:flex;flex-direction:column;background:var(--tk-bg);overflow-y:auto;min-height:0; }
.tk-form-area::-webkit-scrollbar { width:4px; }
.tk-form-area::-webkit-scrollbar-thumb { background:var(--tk-border);border-radius:4px; }
.tk-sidebar   { background:var(--tk-bg2);border-left:1px solid var(--tk-border);overflow-y:auto;min-height:0; }
.tk-sidebar::-webkit-scrollbar { width:3px; }
.tk-sidebar::-webkit-scrollbar-thumb { background:var(--tk-border);border-radius:4px; }

/* ── ALERTS ── */
.tk-alert { display:flex;align-items:center;gap:10px;padding:10px 22px;font-size:13px;font-weight:500;flex-shrink:0; }
.tk-alert-ok  { background:rgba(16,185,129,.1);color:#6ee7b7;border-bottom:1px solid rgba(16,185,129,.2); }
.tk-alert-err { background:rgba(239,68,68,.1); color:#fca5a5;border-bottom:1px solid rgba(239,68,68,.2); }

/* ── FORM FIELDS ── */
.tk-field {
  display:flex;align-items:flex-start;
  border-bottom:1px solid var(--tk-border);min-height:44px;flex-shrink:0;
}
.tk-field-lbl {
  font-size:11.5px;font-weight:700;color:var(--tk-text3);
  padding:12px 16px;min-width:76px;flex-shrink:0;
  border-right:1px solid var(--tk-border);
  display:flex;align-items:center;
  font-family:var(--tk-mono);text-transform:uppercase;letter-spacing:.5px;
}
.tk-field-lbl .req { color:var(--tk-red);margin-left:3px; }
.tk-field-input {
  flex:1;font-size:13.5px;font-family:var(--tk-font);
  color:var(--tk-text);border:none;outline:none;
  padding:10px 16px;background:transparent;width:100%;
}
.tk-field-input::placeholder { color:var(--tk-text3); }
.tk-field-input:focus { background:rgba(255,255,255,.02); }
.tk-sel-wrap { flex:1;position:relative; }
.tk-sel-wrap::after {
  content:"\f078";font-family:"Font Awesome 6 Free";font-weight:900;
  position:absolute;right:14px;top:50%;transform:translateY(-50%);
  font-size:9px;color:var(--tk-text3);pointer-events:none;
}
.tk-tpl-sel {
  width:100%;font-size:13px;font-family:var(--tk-font);
  color:var(--tk-text);border:none;outline:none;
  padding:11px 16px;background:transparent;cursor:pointer;
  appearance:none;-webkit-appearance:none;
}
.tk-tpl-sel option { background:var(--tk-bg2); }

/* ── TOOLBAR ── */
.tk-toolbar {
  display:flex;align-items:center;flex-wrap:wrap;gap:2px;
  padding:6px 12px;background:var(--tk-bg2);
  border-bottom:1px solid var(--tk-border);flex-shrink:0;
}
.tk-tb-btn {
  display:inline-flex;align-items:center;justify-content:center;
  min-width:28px;height:26px;padding:0 5px;
  font-size:12.5px;color:var(--tk-text2);
  background:transparent;border:1px solid transparent;border-radius:5px;
  cursor:pointer;font-family:var(--tk-font);transition:background .1s;
}
.tk-tb-btn:hover { background:var(--tk-bg3);border-color:var(--tk-border);color:var(--tk-text); }
.tk-tb-sep { width:1px;height:16px;background:var(--tk-border);margin:0 3px;flex-shrink:0; }
.tk-tb-sel {
  font-size:11.5px;color:var(--tk-text2);background:var(--tk-bg3);
  border:1px solid var(--tk-border);border-radius:5px;
  padding:2px 6px;cursor:pointer;outline:none;height:26px;
  font-family:var(--tk-font);
}
.tk-tb-sel option { background:var(--tk-bg2); }
.tk-font-sel { width:90px; } .tk-size-sel { width:44px; }

/* ── EDITOR ── */
.tk-editor {
  min-height:260px;padding:18px 20px;
  font-size:14px;line-height:1.8;color:var(--tk-text);
  outline:none;font-family:var(--tk-font);word-break:break-word;
}
.tk-editor:empty::before { content:attr(data-placeholder);color:var(--tk-text3);pointer-events:none; }
.tk-editor:focus { background:rgba(255,255,255,.01); }

/* ── QUOTED ── */
.tk-quoted { border-top:2px solid var(--tk-border);background:var(--tk-bg2);flex-shrink:0; }
.tk-quoted-hd {
  display:flex;align-items:center;gap:8px;
  padding:9px 20px;font-size:12px;font-weight:600;
  color:var(--tk-text3);border-bottom:1px solid var(--tk-border);
}
.tk-quoted-toggle { margin-left:auto;background:none;border:none;font-size:12px;font-weight:700;color:var(--tk-blue2);cursor:pointer;font-family:var(--tk-font); }
.tk-quoted-body {
  margin:12px 20px 14px;padding:12px 16px;
  font-size:13px;line-height:1.65;color:var(--tk-text3);
  white-space:pre-wrap;word-break:break-word;
  border-left:3px solid var(--tk-border2);border-radius:0 5px 5px 0;
  background:var(--tk-bg3);
}
.tk-quoted-meta { font-size:11.5px;color:var(--tk-text3);margin-bottom:7px;opacity:.8; }

/* ── OPTIONS ── */
.tk-opts {
  display:flex;align-items:center;flex-wrap:wrap;gap:14px;
  padding:10px 20px;background:var(--tk-bg2);
  border-top:1px solid var(--tk-border);flex-shrink:0;
}
.tk-opt-lbl { font-size:11.5px;font-weight:700;color:var(--tk-text3);white-space:nowrap; }
.tk-opt-sel {
  font-size:12.5px;color:var(--tk-text);background:var(--tk-bg3);
  border:1px solid var(--tk-border);border-radius:var(--tk-radius);
  padding:5px 28px 5px 10px;cursor:pointer;outline:none;
  appearance:none;-webkit-appearance:none;font-family:var(--tk-font);
}
.tk-opt-sel option { background:var(--tk-bg2); }
.tk-chk-row { display:flex;align-items:center;gap:7px;cursor:pointer;user-select:none; }
.tk-chk-row input[type=checkbox] { width:15px;height:15px;accent-color:var(--tk-accent);cursor:pointer; }
.tk-chk-lbl { font-size:13px;color:var(--tk-text2); }

/* ── ATTACHMENTS ── */
.tk-attach-bar {
  display:flex;align-items:center;gap:8px;
  padding:10px 20px;border-top:1px solid var(--tk-border);flex-shrink:0;
}
.tk-attach-add {
  display:inline-flex;align-items:center;gap:5px;
  font-size:12.5px;font-weight:700;color:var(--tk-sky);
  background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.2);
  border-radius:6px;padding:5px 11px;cursor:pointer;transition:background .12s;
  font-family:var(--tk-font);
}
.tk-attach-add:hover { background:rgba(6,182,212,.15); }
.tk-dropzone {
  margin:0 20px 12px;border:2px dashed var(--tk-border);border-radius:var(--tk-radius);
  padding:22px 20px;display:flex;align-items:center;justify-content:center;gap:8px;
  font-size:13px;color:var(--tk-text3);cursor:pointer;transition:all .15s;
}
.tk-dropzone:hover,.tk-drag-over { border-color:var(--tk-sky);background:rgba(6,182,212,.05);color:var(--tk-sky); }
.tk-file-list { padding:0 20px 10px;display:flex;flex-direction:column;gap:4px; }
.tk-file-item {
  display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--tk-text2);
  background:var(--tk-bg3);border:1px solid var(--tk-border);border-radius:var(--tk-radius);padding:5px 10px;
}
.tk-file-rm { background:none;border:none;color:var(--tk-text3);cursor:pointer;padding:0 2px;font-size:12px;margin-left:auto; }
.tk-file-rm:hover { color:var(--tk-red); }

/* ── FOOTER ── */
.tk-compose-foot {
  display:flex;align-items:center;gap:10px;
  padding:13px 20px;border-top:2px solid var(--tk-border);
  background:var(--tk-bg2);flex-shrink:0;
}
.tk-spin { animation:_spin .7s linear infinite;display:inline-block; }
@keyframes _spin { to{ transform:rotate(360deg) } }

/* ── SIDEBAR SECTIONS ── */
.tk-sb-section { border-bottom:1px solid var(--tk-border); }
.tk-sb-hd {
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 16px;
  font-family:var(--tk-mono);font-size:9.5px;font-weight:600;
  letter-spacing:.7px;text-transform:uppercase;color:var(--tk-text3);
  background:var(--tk-bg3);cursor:pointer;border-bottom:1px solid var(--tk-border);
}
.tk-sb-hd i { font-size:9px;transition:transform .2s; }
.tk-sb-body { background:var(--tk-bg2); }
.tk-prop-row {
  display:grid;grid-template-columns:82px 1fr;gap:5px;
  padding:8px 16px;border-bottom:1px solid rgba(255,255,255,.03);align-items:start;
}
.tk-prop-row:last-child { border-bottom:none; }
.tk-prop-lbl { font-size:10.5px;font-weight:600;color:var(--tk-text3);padding-top:2px; }
.tk-prop-val { font-size:12px;color:var(--tk-text);font-weight:500;word-break:break-word; }

/* Switch links */
.tk-sw-link {
  display:flex;align-items:center;gap:8px;
  padding:9px 14px;margin:2px 8px;border-radius:var(--tk-radius);
  text-decoration:none;font-size:12.5px;font-weight:600;transition:background .1s;
}
.tk-sw-link:hover { text-decoration:none; }

/* Recipients list */
.tk-recip-item {
  display:flex;align-items:center;gap:9px;
  padding:8px 16px;border-bottom:1px solid rgba(255,255,255,.03);
}
.tk-recip-av {
  width:26px;height:26px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:700;color:#fff;flex-shrink:0;
  background:linear-gradient(135deg,#6366f1,#4338ca);
}
.tk-recip-name { font-size:12px;font-weight:600;color:var(--tk-text); }
.tk-recip-email { font-size:10.5px;color:var(--tk-text3); }

@media(max-width:800px){
  .tk-page { grid-template-columns:1fr; }
  .tk-sidebar { display:none; }
}
</style>

<div class="tk-compose">

<!-- ── TOPBAR ── -->
<div class="tk-topbar">
  <a href="<?= htmlspecialchars($back_url) ?>" class="tk-back"><i class="fas fa-arrow-left"></i> Back</a>
  <div class="tk-topbar-divider"></div>
  <span class="tk-topbar-title"><?= htmlspecialchars($notif['title']??'Email') ?></span>
  <div class="tk-topbar-right">
    <span class="tk-mode-badge mode-<?= htmlspecialchars($mode) ?>">
      <?php if ($mode==='reply'): ?><i class="fas fa-reply"></i>
      <?php elseif ($mode==='reply_all'): ?><i class="fas fa-reply-all"></i>
      <?php else: ?><i class="fas fa-share"></i><?php endif; ?>
      <?= htmlspecialchars($mode_label) ?>
    </span>
    <a href="<?= htmlspecialchars($back_url) ?>" class="tk-btn tk-btn-danger tk-btn-sm">
      <i class="fas fa-times"></i> Cancel
    </a>
  </div>
</div>

<!-- ── STRIP ── -->
<div class="tk-strip">
  <div class="tk-strip-icon" style="background:<?= $mode==='forward'?'#d97706':($mode==='reply_all'?'#10b981':'#3b82f6') ?>">
    <?php if ($mode==='reply'): ?><i class="fas fa-reply"></i>
    <?php elseif ($mode==='reply_all'): ?><i class="fas fa-reply-all"></i>
    <?php else: ?><i class="fas fa-share"></i><?php endif; ?>
  </div>
  <div class="tk-strip-meta">
    <div class="tk-strip-name"><?= htmlspecialchars($notif['title']??'Email') ?></div>
    <div class="tk-strip-sub">
      <?= $notif_id>0?'NTF-'.str_pad($notif_id,4,'0',STR_PAD_LEFT):'EMAIL-'.str_pad($email_id,4,'0',STR_PAD_LEFT) ?>
      &middot; <?= !empty($notif['created_at'])?date('M j, Y g:i A',strtotime($notif['created_at'])):'' ?>
    </div>
  </div>
  <div class="tk-strip-actions">
    <a href="compose-reply.php?<?= $base_qs ?>&mode=reply"     class="tk-mode-switch <?= $mode==='reply'?'sw-reply':'' ?>"     title="Reply"><i class="fas fa-reply"></i></a>
    <a href="compose-reply.php?<?= $base_qs ?>&mode=reply_all" class="tk-mode-switch <?= $mode==='reply_all'?'sw-reply':'' ?>"  title="Reply All"><i class="fas fa-reply-all"></i></a>
    <a href="compose-reply.php?<?= $base_qs ?>&mode=forward"   class="tk-mode-switch <?= $mode==='forward'?'sw-forward':'' ?>"  title="Forward"><i class="fas fa-share"></i></a>
  </div>
</div>

<!-- ── MAIN GRID ── -->
<div class="tk-page">

  <!-- COMPOSE AREA -->
  <div class="tk-form-area">

    <?php if ($send_success): ?>
    <div class="tk-alert tk-alert-ok">
      <i class="fas fa-check-circle"></i> <?= $send_success ?>
      <a href="<?= htmlspecialchars($back_url) ?>" style="margin-left:auto;font-weight:700;color:#6ee7b7;font-size:12px">← Back to Notification</a>
    </div>
    <?php endif; ?>
    <?php if ($send_error): ?>
    <div class="tk-alert tk-alert-err"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($send_error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" enctype="multipart/form-data" id="tkComposeForm">
      <input type="hidden" name="compose_send" value="1">
      <input type="hidden" name="post_mode" value="<?= htmlspecialchars($mode) ?>">

      <!-- To -->
      <div class="tk-field">
        <label class="tk-field-lbl">To <span class="req">*</span></label>
        <?php if ($mode==='reply_all'): ?>
        <div style="flex:1;padding:11px 16px;font-size:13px;color:var(--tk-text3);display:flex;align-items:center;gap:8px;">
          <i class="fas fa-users" style="font-size:11px"></i>
          <em>All inbound senders</em>
          <?php if (!empty($reply_all_recipients)): ?>
          <span style="font-size:11.5px;color:var(--tk-sky);font-weight:700">(<?= count($reply_all_recipients) ?> recipient<?= count($reply_all_recipients)>1?'s':'' ?>)</span>
          <?php endif; ?>
          <input type="hidden" name="post_to" value="<?= htmlspecialchars($reply_all_to_display) ?>">
        </div>
        <?php else: ?>
        <input type="text" name="post_to" id="tkTo" class="tk-field-input"
               value="<?= htmlspecialchars($mode==='forward'?'':$to_email) ?>"
               placeholder="Recipient email address…" autocomplete="email">
        <?php endif; ?>
      </div>

      <!-- Cc -->
      <div class="tk-field">
        <label class="tk-field-lbl">Cc</label>
        <input type="text" name="post_cc" class="tk-field-input" placeholder="CC addresses (comma-separated)…">
      </div>

      <!-- Bcc (hidden) -->
      <div class="tk-field" id="tkBccRow" style="display:none">
        <label class="tk-field-lbl">Bcc</label>
        <input type="text" name="post_bcc" class="tk-field-input" placeholder="BCC addresses…">
      </div>

      <!-- Template -->
      <div class="tk-field">
        <label class="tk-field-lbl">Template</label>
        <div class="tk-sel-wrap">
          <select class="tk-tpl-sel" onchange="tkApplyTpl(this.value)">
            <option value="">Default Reply Template</option>
            <option value="acknowledged">Complaint Acknowledged</option>
            <option value="resolved">Issue Resolved</option>
            <option value="followup">Follow-Up Required</option>
            <option value="noaction">No Action Needed</option>
          </select>
        </div>
        <button type="button" onclick="document.getElementById('tkBccRow').style.display='flex'"
                style="background:none;border:none;font-size:12px;color:var(--tk-text3);cursor:pointer;padding:0 14px;white-space:nowrap;font-family:var(--tk-font);">
          + Bcc
        </button>
      </div>

      <!-- Subject -->
      <div class="tk-field">
        <label class="tk-field-lbl">Subject <span class="req">*</span></label>
        <input type="text" name="post_subject" id="tkSubject" class="tk-field-input"
               value="<?= htmlspecialchars($subject) ?>">
      </div>

      <!-- Toolbar -->
      <div class="tk-toolbar">
        <button type="button" class="tk-tb-btn" style="font-weight:900" onclick="tkExec('bold')" title="Bold"><b>B</b></button>
        <button type="button" class="tk-tb-btn" style="font-style:italic" onclick="tkExec('italic')" title="Italic"><i>I</i></button>
        <button type="button" class="tk-tb-btn" style="text-decoration:underline" onclick="tkExec('underline')" title="Underline"><u>U</u></button>
        <button type="button" class="tk-tb-btn" style="text-decoration:line-through" onclick="tkExec('strikethrough')" title="Strike"><s>S</s></button>
        <div class="tk-tb-sep"></div>
        <select class="tk-tb-sel tk-font-sel" onchange="tkExec('fontName',this.value)">
          <option value="Outfit" selected>Outfit</option>
          <option value="Arial">Arial</option>
          <option value="Georgia">Georgia</option>
          <option value="Courier New">Courier New</option>
          <option value="Times New Roman">Times New Roman</option>
        </select>
        <select class="tk-tb-sel tk-size-sel" onchange="tkExec('fontSize',this.value)">
          <option value="1">8</option><option value="2">10</option>
          <option value="3" selected>12</option><option value="4">14</option>
          <option value="5">18</option><option value="6">24</option>
        </select>
        <div class="tk-tb-sep"></div>
        <button type="button" class="tk-tb-btn" onclick="tkExec('justifyLeft')"   title="Left"><i class="fas fa-align-left"></i></button>
        <button type="button" class="tk-tb-btn" onclick="tkExec('justifyCenter')" title="Center"><i class="fas fa-align-center"></i></button>
        <button type="button" class="tk-tb-btn" onclick="tkExec('justifyRight')"  title="Right"><i class="fas fa-align-right"></i></button>
        <div class="tk-tb-sep"></div>
        <button type="button" class="tk-tb-btn" onclick="tkExec('insertUnorderedList')" title="Bullets"><i class="fas fa-list-ul"></i></button>
        <button type="button" class="tk-tb-btn" onclick="tkExec('insertOrderedList')"   title="Numbers"><i class="fas fa-list-ol"></i></button>
        <div class="tk-tb-sep"></div>
        <button type="button" class="tk-tb-btn" onclick="tkInsertLink()" title="Link"><i class="fas fa-link"></i></button>
        <button type="button" class="tk-tb-btn" onclick="tkExec('removeFormat')" title="Clear"><i class="fas fa-remove-format"></i></button>
        <button type="button" class="tk-tb-btn" onclick="tkFullscreen()" id="tkFsBtn" title="Fullscreen"><i class="fas fa-expand-alt"></i></button>
      </div>

      <!-- Editor -->
      <div id="tkEditorWrap">
        <div class="tk-editor" id="tkEditor"
             contenteditable="true"
             data-placeholder="Write your message here…"
             oninput="tkSync()"></div>
        <textarea name="post_body_text" id="tkBodyHidden" style="display:none"></textarea>
      </div>

      <!-- Quoted -->
      <?php if ($quoted_body): ?>
      <div class="tk-quoted">
        <div class="tk-quoted-hd">
          <i class="fas <?= $mode==='forward'?'fa-share':'fa-reply' ?>" style="color:var(--tk-text3)"></i>
          <?= $mode==='forward'?'Forwarded message':'Original message' ?>
          <button type="button" class="tk-quoted-toggle" id="tkQtToggle">Hide</button>
        </div>
        <div class="tk-quoted-body" id="tkQtBody">
          <div class="tk-quoted-meta">
            <?php if ($quoted_from): ?>From: <?= htmlspecialchars($quoted_from) ?><br><?php endif; ?>
            <?php if ($quoted_time): ?>Date: <?= htmlspecialchars($quoted_time) ?><?php endif; ?>
          </div>
          <?= nl2br(htmlspecialchars($quoted_body)) ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Options -->
      <div class="tk-opts">
        <div style="display:flex;align-items:center;gap:8px">
          <span class="tk-opt-lbl">Request Status</span>
          <select name="post_status" class="tk-opt-sel">
            <option value="work_in_progress">Work In Progress</option>
            <option value="sent">Sent</option>
            <option value="resolved">Resolved</option>
            <option value="on_hold">On Hold</option>
          </select>
        </div>
        <label class="tk-chk-row">
          <input type="checkbox" name="show_requester" checked>
          <span class="tk-chk-lbl">Show this mail to requester also</span>
        </label>
      </div>

      <!-- Attachments -->
      <div class="tk-attach-bar">
        <span style="font-size:13px;font-weight:700;color:var(--tk-text2)">
          <i class="fas fa-paperclip" style="color:var(--tk-text3);margin-right:5px"></i>Attachments
        </span>
        <button type="button" class="tk-attach-add" onclick="document.getElementById('tkFileInput').click()">
          <i class="fas fa-plus"></i> Add Files
        </button>
        <button type="button" style="background:none;border:none;font-size:12px;color:var(--tk-text3);cursor:pointer;padding:4px 5px" onclick="tkToggleDz()">
          <i class="fas fa-chevron-down" id="tkDzCaret"></i>
        </button>
        <input type="file" id="tkFileInput" name="post_attachments[]" multiple style="display:none" onchange="tkShowFiles(this)">
      </div>
      <div id="tkDzWrap" style="display:none">
        <div class="tk-dropzone" id="tkDropzone"
             onclick="document.getElementById('tkFileInput').click()"
             ondragover="event.preventDefault();this.classList.add('tk-drag-over')"
             ondragleave="this.classList.remove('tk-drag-over')"
             ondrop="tkHandleDrop(event)">
          <i class="fas fa-cloud-upload-alt" style="font-size:16px"></i>
          <span>Drag and drop files here, or click to browse</span>
        </div>
        <div class="tk-file-list" id="tkFileList"></div>
      </div>

      <!-- Footer -->
      <div class="tk-compose-foot">
        <button type="submit" class="tk-btn tk-btn-primary" id="tkSendBtn" onclick="tkSync()">
          <i class="fas fa-paper-plane"></i> Send
        </button>
        <button type="button" class="tk-btn tk-btn-ghost" onclick="tkSaveDraft()">
          <i class="fas fa-save"></i> Save Draft
        </button>
        <a href="<?= htmlspecialchars($back_url) ?>" class="tk-btn tk-btn-ghost" style="margin-left:auto">
          <i class="fas fa-times"></i> Cancel
        </a>
      </div>
    </form>
  </div><!-- /form-area -->

  <!-- ── SIDEBAR ── -->
  <div class="tk-sidebar">

    <!-- Ticket Info -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkSbToggle(this)">Ticket Info <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body">
        <div class="tk-prop-row">
          <div class="tk-prop-lbl">ID</div>
          <div class="tk-prop-val" style="font-family:var(--tk-mono);font-size:11.5px">
            <?= $notif_id>0?'NTF-'.str_pad($notif_id,4,'0',STR_PAD_LEFT):'EMAIL-'.str_pad($email_id,4,'0',STR_PAD_LEFT) ?>
          </div>
        </div>
        <div class="tk-prop-row">
          <div class="tk-prop-lbl">Mode</div>
          <div class="tk-prop-val"><span class="tk-mode-badge mode-<?= htmlspecialchars($mode) ?>" style="font-size:9.5px"><?= htmlspecialchars($mode_label) ?></span></div>
        </div>
        <div class="tk-prop-row">
          <div class="tk-prop-lbl">Received</div>
          <div class="tk-prop-val" style="font-size:11px;color:var(--tk-text3)">
            <?= !empty($notif['created_at'])?date('M j, Y g:i A',strtotime($notif['created_at'])):'—' ?>
          </div>
        </div>
        <?php if ($to_email): ?>
        <div class="tk-prop-row">
          <div class="tk-prop-lbl">To</div>
          <div class="tk-prop-val" style="font-size:11px">
            <?= htmlspecialchars($to_name) ?><br>
            <span style="color:var(--tk-text3)"><?= htmlspecialchars($to_email) ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($mode==='reply_all'&&!empty($reply_all_recipients)): ?>
    <!-- Recipients -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkSbToggle(this)">Recipients (<?= count($reply_all_recipients) ?>) <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body">
        <?php foreach ($reply_all_recipients as $r): ?>
        <div class="tk-recip-item">
          <div class="tk-recip-av"><?= strtoupper(substr($r['name'],0,1)) ?></div>
          <div>
            <div class="tk-recip-name"><?= htmlspecialchars($r['name']) ?></div>
            <div class="tk-recip-email"><?= htmlspecialchars($r['email']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Switch -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkSbToggle(this)">Quick Switch <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body" style="padding:6px 0">
        <a href="compose-reply.php?<?= $base_qs ?>&mode=reply"
           class="tk-sw-link" style="color:<?= $mode==='reply'?'var(--tk-blue2)':'var(--tk-text2)' ?>;background:<?= $mode==='reply'?'rgba(59,130,246,.1)':'transparent' ?>">
          <i class="fas fa-reply" style="width:14px;font-size:11px;color:<?= $mode==='reply'?'var(--tk-blue2)':'var(--tk-text3)' ?>"></i> Reply
        </a>
        <a href="compose-reply.php?<?= $base_qs ?>&mode=reply_all"
           class="tk-sw-link" style="color:<?= $mode==='reply_all'?'var(--tk-green)':'var(--tk-text2)' ?>;background:<?= $mode==='reply_all'?'rgba(16,185,129,.1)':'transparent' ?>">
          <i class="fas fa-reply-all" style="width:14px;font-size:11px;color:<?= $mode==='reply_all'?'var(--tk-green)':'var(--tk-text3)' ?>"></i> Reply All
        </a>
        <a href="compose-reply.php?<?= $base_qs ?>&mode=forward"
           class="tk-sw-link" style="color:<?= $mode==='forward'?'var(--tk-accent)':'var(--tk-text2)' ?>;background:<?= $mode==='forward'?'rgba(245,158,11,.1)':'transparent' ?>">
          <i class="fas fa-share" style="width:14px;font-size:11px;color:<?= $mode==='forward'?'var(--tk-accent)':'var(--tk-text3)' ?>"></i> Forward
        </a>
      </div>
    </div>

  </div><!-- /sidebar -->

</div><!-- /tk-page -->
</div><!-- /tk-compose -->

<script>
/* ── Editor ── */
function tkExec(cmd,val){ document.getElementById('tkEditor').focus(); document.execCommand(cmd,false,val||null); }
function tkSync(){
  const e=document.getElementById('tkEditor'),h=document.getElementById('tkBodyHidden');
  if(e&&h) h.value=e.innerText.trim();
}
function tkInsertLink(){ const u=prompt('Enter URL:'); if(u) tkExec('createLink',u); }

let _fs=false;
function tkFullscreen(){
  const w=document.getElementById('tkEditorWrap'),b=document.getElementById('tkFsBtn');
  _fs=!_fs;
  if(_fs){
    w.style.cssText='position:fixed;inset:0;z-index:9999;background:var(--tk-bg);display:flex;flex-direction:column;';
    document.getElementById('tkEditor').style.cssText='flex:1;min-height:unset;padding:28px 32px;font-size:15px;';
    b.innerHTML='<i class="fas fa-compress-alt"></i>';
  } else {
    w.style.cssText=''; document.getElementById('tkEditor').style.cssText='';
    b.innerHTML='<i class="fas fa-expand-alt"></i>';
  }
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded',function(){
  const tgl=document.getElementById('tkQtToggle'),bdy=document.getElementById('tkQtBody');
  if(tgl&&bdy) tgl.addEventListener('click',function(){
    const h=bdy.style.display==='none'; bdy.style.display=h?'':'none'; tgl.textContent=h?'Hide':'Show';
  });
  const form=document.getElementById('tkComposeForm');
  if(form) form.addEventListener('submit',function(){
    tkSync();
    const btn=document.getElementById('tkSendBtn');
    btn.innerHTML='<i class="fas fa-circle-notch tk-spin"></i> Sending…'; btn.disabled=true;
  });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape'&&_fs) tkFullscreen(); });
});

/* ── Attachments ── */
let _dz=false;
function tkToggleDz(){
  _dz=!_dz;
  document.getElementById('tkDzWrap').style.display=_dz?'':'none';
  document.getElementById('tkDzCaret').className=_dz?'fas fa-chevron-up':'fas fa-chevron-down';
}
function tkShowFiles(input){
  const list=document.getElementById('tkFileList'); list.innerHTML='';
  Array.from(input.files).forEach(f=>{
    const icon=f.type.startsWith('image/')?'fa-file-image':f.type==='application/pdf'?'fa-file-pdf':'fa-file';
    const el=document.createElement('div'); el.className='tk-file-item';
    el.innerHTML=`<i class="fas ${icon}" style="color:var(--tk-text3)"></i><span>${f.name}</span>`
      +`<span style="margin-left:auto;color:var(--tk-text3);font-size:11px">${(f.size/1024).toFixed(1)} KB</span>`
      +`<button class="tk-file-rm" type="button" onclick="this.closest('.tk-file-item').remove()"><i class="fas fa-times"></i></button>`;
    list.appendChild(el);
  });
  if(input.files.length){ _dz=true; document.getElementById('tkDzWrap').style.display=''; }
}
function tkHandleDrop(e){
  e.preventDefault(); document.getElementById('tkDropzone').classList.remove('tk-drag-over');
  const dt=e.dataTransfer;
  if(dt&&dt.files.length){ document.getElementById('tkFileInput').files=dt.files; tkShowFiles(document.getElementById('tkFileInput')); }
}

/* ── Templates ── */
const _tpls={
  acknowledged:{s:'Your Concern Has Been Acknowledged',b:'Dear Resident,\n\nWe have received your concern and would like to inform you that it has been acknowledged by the Barangay office. Our team is currently reviewing the matter.\n\nThank you for bringing this to our attention.\n\nSincerely,\nBarangay Office'},
  resolved:{s:'Resolution Notice — Issue Resolved',b:'Dear Resident,\n\nWe are pleased to inform you that your concern has been resolved. The Barangay has taken the necessary action.\n\nIf you have further questions, please contact our office.\n\nSincerely,\nBarangay Office'},
  followup:{s:'Follow-Up Required — Barangay Office',b:'Dear Resident,\n\nThis is to inform you that your concern is still under review and requires further follow-up. We appreciate your patience.\n\nOur office will contact you shortly.\n\nSincerely,\nBarangay Office'},
  noaction:{s:'Notice — No Further Action Required',b:'Dear Resident,\n\nAfter careful review, the Barangay has determined that no further action is required at this time.\n\nShould you have a new concern, please feel free to reach out.\n\nSincerely,\nBarangay Office'}
};
function tkApplyTpl(k){
  if(!k||!_tpls[k]) return;
  document.getElementById('tkSubject').value=_tpls[k].s;
  document.getElementById('tkEditor').innerText=_tpls[k].b;
  tkSync();
}
function tkSaveDraft(){ tkSync(); alert('Draft saved.'); }

/* ── Sidebar toggle ── */
function tkSbToggle(hd){
  const body=hd.nextElementSibling,icon=hd.querySelector('i');
  if(!body) return;
  const vis=body.style.display!=='none';
  body.style.display=vis?'none':'';
  if(icon) icon.style.transform=vis?'rotate(180deg)':'';
}
</script>

<?php include '../../includes/footer.php'; ?>