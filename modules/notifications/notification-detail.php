<?php
/**
 * Notification Detail - modules/notifications/notification-detail.php
 * REDESIGNED: ManageEngine ServiceDesk ticket detail UI
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$user_id   = $_SESSION['user_id'];
$user_role = getCurrentUserRole();
$notif_id  = intval($_GET['id'] ?? 0);
$is_admin  = in_array($user_role, ['Super Admin','Super Administrator','Admin','Staff']);

if (!$notif_id) { header('Location: index.php'); exit(); }

if ($is_admin) {
    $notif = fetchOne($conn,"SELECT * FROM tbl_notifications WHERE notification_id=?",[$notif_id],'i');
} else {
    $notif = fetchOne($conn,"SELECT * FROM tbl_notifications WHERE notification_id=? AND user_id=?",[$notif_id,$user_id],'ii');
}
if (!$notif) {
    $_SESSION['error_message'] = 'Notification not found.';
    header('Location: index.php'); exit();
}

// Mark read
if (!$notif['is_read']) {
    if ($is_admin) {
        $s=$conn->prepare("UPDATE tbl_notifications SET is_read=1 WHERE notification_id=?");
        $s->bind_param('i',$notif_id);
    } else {
        $s=$conn->prepare("UPDATE tbl_notifications SET is_read=1 WHERE notification_id=? AND user_id=?");
        $s->bind_param('ii',$notif_id,$user_id);
    }
    $s->execute(); $s->close();
    $notif['is_read']=1;
}

$t  = $notif['type'] ?? '';
$rt = $notif['reference_type'] ?? '';
$is_email = ($t==='email_reply'||$rt==='email_inbox');

// Email replies
$email_replies = []; $tbl_replies_exists = false;
if ($is_email) {
    $chk=$conn->query("SHOW TABLES LIKE 'tbl_email_replies'");
    if ($chk&&$chk->num_rows>0) {
        $tbl_replies_exists=true;
        $email_replies=fetchAll($conn,"SELECT * FROM tbl_email_replies WHERE notification_id=? ORDER BY created_at ASC",[$notif_id],'i');
        if (empty($email_replies)&&!empty($notif['reference_id'])) {
            $email_replies=fetchAll($conn,"SELECT * FROM tbl_email_replies WHERE id=? ORDER BY created_at ASC",[intval($notif['reference_id'])],'i');
        }
    }
}

// Sender
$sender_email=''; $sender_name='';
if ($is_email) {
    $msg=$notif['message']??'';
    if (preg_match('/From:\s*([^<\n]+?)(?:\s*<([^>]+)>)?(?:\s|$)/i',$msg,$m)) {
        $sender_name=trim($m[1]); $sender_email=trim($m[2]??'');
    }
    if (!$sender_email&&preg_match('/[\w._%+\-]+@[\w.\-]+\.[a-z]{2,}/i',$msg,$em)) $sender_email=$em[0];
    if (!$sender_email) {
        foreach ($email_replies as $er) {
            if ($er['direction']==='inbound'&&!empty($er['from_email'])) {
                $sender_email=$er['from_email'];
                if (!$sender_name) $sender_name=$er['from_name']??'';
                break;
            }
        }
    }
}

// Reference URL (non-email only)
$ref_url='';
if (!$is_email&&!empty($notif['reference_id'])) {
    $rid=intval($notif['reference_id']);
    if      ($rt==='incident')                   $ref_url='../incidents/incident-details.php?id='.$rid;
    elseif  ($rt==='blotter')                    $ref_url='../blotter/view-blotter.php?id='.$rid;
    elseif  ($rt==='complaint')                  $ref_url='../complaints/complaint-details.php?id='.$rid;
    elseif  ($rt==='request'||$rt==='document')  $ref_url='../requests/view-request.php?id='.$rid;
    elseif  ($rt==='appointment')                $ref_url='../health/appointments.php';
    elseif  ($rt==='medical_assistance')         $ref_url='../health/medical-assistance.php';
}

// Icon
$icon='fa-bell'; $ico_class='ico-navy'; $type_label=ucwords(str_replace('_',' ',$t));
if ($is_email)                                           { $icon='fa-envelope';             $ico_class='ico-sky';    $type_label='Email Reply'; }
elseif($rt==='announcement'||in_array($t,['general','announcement','alert','status_update'])) {
    $icon='fa-bullhorn'; $ico_class='ico-navy'; $type_label='Announcement';
    if ($t==='alert')  { $icon='fa-exclamation-circle'; $ico_class='ico-red'; }
    elseif($t==='general') { $icon='fa-bell'; $ico_class='ico-navy'; }
} elseif(stripos($t,'incident')!==false||$rt==='incident')  { $icon='fa-exclamation-triangle'; $ico_class='ico-amber'; }
  elseif(stripos($t,'blotter')!==false||$rt==='blotter')    { $icon='fa-gavel';               $ico_class='ico-red'; }
  elseif(stripos($t,'complaint')!==false||$rt==='complaint') { $icon='fa-comments';            $ico_class='ico-orange'; }
  elseif(stripos($t,'request')!==false||stripos($t,'document')!==false) { $icon='fa-file-alt'; $ico_class='ico-blue'; }
  elseif(stripos($t,'appointment')!==false||$rt==='appointment')         { $icon='fa-calendar-check'; $ico_class='ico-green'; }
  elseif(stripos($t,'medical')!==false||$rt==='medical_assistance')      { $icon='fa-hand-holding-medical'; $ico_class='ico-purple'; }

// Quick reply
$send_success=''; $send_error='';
if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['quick_reply'])&&$is_admin&&$is_email) {
    $rb=trim($_POST['reply_body']??'');
    $rt2=trim($_POST['reply_to']??$sender_email);
    if (!$rb)   { $send_error='Reply cannot be empty.'; }
    elseif(!$rt2){ $send_error='No recipient email.'; }
    else {
        $hl=false;
        foreach([__DIR__.'/../../includes/email_helper.php',__DIR__.'/../../includes/phpmailer/mailer.php'] as $hp) {
            if(file_exists($hp)){ require_once $hp; $hl=true; break; }
        }
        if (!$hl) { $send_error='Email helper not found.'; }
        else {
            $subj='Re: '.($notif['title']??'Email');
            $html='<html><body><p>'.nl2br(htmlspecialchars($rb)).'</p></body></html>';
            $fn=function_exists('sendEmail')?'sendEmail':(function_exists('sendNotificationEmail')?'sendNotificationEmail':null);
            if (!$fn) { $send_error='No send function.'; }
            else {
                try {
                    $ok=call_user_func($fn,$rt2,$subj,$html,$sender_name?:$rt2);
                    if ($ok) {
                        if ($tbl_replies_exists) {
                            $mf=defined('MAIL_FROM_EMAIL')?MAIL_FROM_EMAIL:'';
                            $ins=$conn->prepare("INSERT INTO tbl_email_replies (notification_id,from_email,from_name,subject,body_text,direction,is_read,created_at) VALUES (?,?,'Barangay Office',?,?,'outbound',1,NOW())");
                            if ($ins){ $ins->bind_param('isss',$notif_id,$mf,$subj,$rb); $ins->execute(); $ins->close(); }
                        }
                        $send_success='Reply sent to '.htmlspecialchars($rt2).'.';
                        $email_replies=$tbl_replies_exists?fetchAll($conn,"SELECT * FROM tbl_email_replies WHERE notification_id=? ORDER BY created_at ASC",[$notif_id],'i'):[];
                    } else { $send_error='Send failed. Check SMTP config.'; }
                } catch(Throwable $e){ $send_error='Error: '.htmlspecialchars($e->getMessage()); }
            }
        }
    }
}

$page_title = htmlspecialchars($notif['title']);
include '../../includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --tk-bg:#0f1117;--tk-bg2:#161b27;--tk-bg3:#1c2333;--tk-bg4:#232b3e;
  --tk-surface:#1e2638;--tk-border:#2a3347;--tk-border2:#334060;
  --tk-text:#e2e8f0;--tk-text2:#94a3b8;--tk-text3:#4a5878;
  --tk-accent:#f59e0b;--tk-accent2:#fbbf24;
  --tk-blue:#3b82f6;--tk-blue2:#60a5fa;
  --tk-green:#10b981;--tk-red:#ef4444;--tk-orange:#f97316;
  --tk-purple:#8b5cf6;--tk-sky:#06b6d4;
  --tk-font:'Outfit',sans-serif;--tk-mono:'JetBrains Mono',monospace;
  --tk-radius:8px;--tk-shadow:0 4px 24px rgba(0,0,0,.4);
}
.tk-detail *,
.tk-detail *::before,
.tk-detail *::after { box-sizing:border-box; }
.tk-detail {
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
.tk-back {
  display:flex;align-items:center;gap:7px;
  font-size:13px;font-weight:600;color:var(--tk-text2);
  text-decoration:none;transition:color .12s;
}
.tk-back:hover { color:var(--tk-accent);text-decoration:none; }
.tk-back i { font-size:12px; }
.tk-topbar-divider { width:1px;height:20px;background:var(--tk-border);flex-shrink:0; }
.tk-topbar-title {
  font-size:13.5px;font-weight:600;color:var(--tk-text2);
  flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.tk-topbar-right { display:flex;align-items:center;gap:6px;flex-shrink:0; }

/* ── BUTTONS ── */
.tk-btn {
  display:inline-flex;align-items:center;gap:5px;
  padding:6px 14px;border-radius:var(--tk-radius);
  font-family:var(--tk-font);font-size:12.5px;font-weight:600;
  cursor:pointer;border:1px solid transparent;
  text-decoration:none;transition:all .14s;white-space:nowrap;
}
.tk-btn-sm { padding:5px 11px;font-size:12px; }
.tk-btn-ghost  { background:var(--tk-bg3);color:var(--tk-text2);border-color:var(--tk-border); }
.tk-btn-ghost:hover  { background:var(--tk-bg4);color:var(--tk-text);border-color:var(--tk-border2);text-decoration:none; }
.tk-btn-primary { background:var(--tk-accent);color:#0f1117;border-color:var(--tk-accent); }
.tk-btn-primary:hover { background:var(--tk-accent2);color:#0f1117;text-decoration:none; }
.tk-btn-sky  { background:rgba(6,182,212,.12);color:var(--tk-sky);border-color:rgba(6,182,212,.3); }
.tk-btn-sky:hover { background:rgba(6,182,212,.2);text-decoration:none;color:var(--tk-sky); }
.tk-btn-danger { background:rgba(239,68,68,.12);color:var(--tk-red);border-color:rgba(239,68,68,.25); }
.tk-btn-danger:hover { background:rgba(239,68,68,.2);text-decoration:none;color:var(--tk-red); }
.tk-btn-blue { background:rgba(59,130,246,.12);color:var(--tk-blue2);border-color:rgba(59,130,246,.25); }
.tk-btn-blue:hover { background:rgba(59,130,246,.2);text-decoration:none;color:var(--tk-blue2); }

/* ── TICKET HEADER ── */
.tk-ticket-hd {
  display:flex;align-items:flex-start;gap:14px;
  padding:18px 22px;background:var(--tk-bg2);
  border-bottom:1px solid var(--tk-border);flex-shrink:0;
}
.tk-ticket-hd-icon {
  width:48px;height:48px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;
  font-size:20px;flex-shrink:0;margin-top:2px;
}
.tk-ticket-hd-content { flex:1;min-width:0; }
.tk-ticket-badge {
  display:inline-flex;align-items:center;gap:5px;
  padding:3px 10px;border-radius:5px;
  font-size:11px;font-weight:700;letter-spacing:.3px;white-space:nowrap;
  margin-bottom:6px;
}
.badge-incident { background:rgba(245,158,11,.12);color:var(--tk-accent); }
.badge-email    { background:rgba(6,182,212,.12);color:var(--tk-sky); }
.badge-announce { background:rgba(255,255,255,.06);color:var(--tk-text2); }
.badge-other    { background:rgba(99,102,241,.12);color:#a78bfa; }
.tk-ticket-title {
  font-size:18px;font-weight:800;color:var(--tk-text);
  line-height:1.3;margin-bottom:8px;letter-spacing:-.3px;
}
.tk-ticket-meta {
  display:flex;flex-wrap:wrap;align-items:center;gap:10px;
  font-size:12px;color:var(--tk-text3);
}
.tk-ticket-meta-item { display:flex;align-items:center;gap:5px; }
.tk-ticket-meta strong { color:var(--tk-sky);font-weight:600; }

/* ── ACTION BAR ── */
.tk-action-bar {
  display:flex;align-items:center;gap:7px;
  padding:10px 22px;background:var(--tk-bg2);
  border-bottom:2px solid var(--tk-border);flex-shrink:0;flex-wrap:wrap;
}

/* ── STATUS BAR ── */
.tk-status-bar {
  display:flex;align-items:center;gap:10px;
  padding:11px 22px;background:var(--tk-bg3);
  border-bottom:1px solid var(--tk-border);flex-shrink:0;flex-wrap:wrap;
}
.tk-status-label { font-size:12px;color:var(--tk-text3);font-weight:500; }
.tk-status-badge {
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 12px;border-radius:6px;
  font-size:12px;font-weight:700;white-space:nowrap;
}
.sb-open     { background:rgba(59,130,246,.12);color:var(--tk-blue2); }
.sb-wip      { background:rgba(245,158,11,.12);color:var(--tk-accent); }
.sb-resolved { background:rgba(16,185,129,.12);color:var(--tk-green); }
.tk-transitions { display:flex;gap:6px;margin-left:auto; }

/* ── MAIN GRID ── */
.tk-main-grid {
  flex:1;display:grid;
  grid-template-columns:1fr 260px;
  min-height:0;overflow:hidden;
}
.tk-content { overflow-y:auto;display:flex;flex-direction:column;background:var(--tk-bg); }
.tk-content::-webkit-scrollbar { width:4px; }
.tk-content::-webkit-scrollbar-thumb { background:var(--tk-border);border-radius:4px; }
.tk-sidebar { overflow-y:auto;background:var(--tk-bg2);border-left:1px solid var(--tk-border); }
.tk-sidebar::-webkit-scrollbar { width:3px; }
.tk-sidebar::-webkit-scrollbar-thumb { background:var(--tk-border);border-radius:4px; }

/* ── CONV TABS ── */
.tk-conv-tabs {
  display:flex;align-items:center;gap:0;
  padding:0 22px;background:var(--tk-bg2);
  border-bottom:1px solid var(--tk-border);flex-shrink:0;
  position:sticky;top:52px;z-index:80;
}
.tk-conv-tab {
  padding:11px 16px;font-size:12.5px;font-weight:600;
  color:var(--tk-text3);cursor:pointer;
  border-bottom:2px solid transparent;margin-bottom:-1px;
  transition:color .12s,border-color .12s;white-space:nowrap;
}
.tk-conv-tab:hover { color:var(--tk-text2); }
.tk-conv-tab.active { color:var(--tk-accent);border-bottom-color:var(--tk-accent); }

/* ── CONV FILTER ── */
.tk-conv-filter {
  display:flex;align-items:center;gap:10px;
  padding:9px 22px;background:var(--tk-bg2);
  border-bottom:1px solid var(--tk-border);flex-shrink:0;flex-wrap:wrap;
}
.tk-conv-filter-lbl { font-size:11.5px;color:var(--tk-text3);font-weight:500; }
.tk-conv-filter-item { display:flex;align-items:center;gap:5px;font-size:12px;color:var(--tk-text2);cursor:pointer; }
.tk-conv-filter-item input { accent-color:var(--tk-accent); }
.tk-conv-sort { margin-left:auto;font-size:11.5px;color:var(--tk-text3);cursor:pointer;display:flex;align-items:center;gap:4px; }

/* ── MESSAGES ── */
.tk-thread { padding:20px 22px;display:flex;flex-direction:column;gap:18px; }
.tk-bubble { display:flex;gap:12px;align-items:flex-start; }
.tk-bubble--out { flex-direction:row-reverse; }
.tk-bubble-av {
  width:34px;height:34px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:13px;font-weight:700;color:#fff;flex-shrink:0;
}
.tk-bubble--in  .tk-bubble-av { background:linear-gradient(135deg,#6366f1,#4338ca); }
.tk-bubble--out .tk-bubble-av { background:linear-gradient(135deg,var(--tk-sky),#0284c7); }
.tk-bubble-wrap { flex:1;min-width:0;max-width:85%; }
.tk-bubble--out .tk-bubble-wrap { display:flex;flex-direction:column;align-items:flex-end; }
.tk-bubble-meta {
  font-size:11px;color:var(--tk-text3);margin-bottom:5px;
  display:flex;align-items:center;gap:6px;flex-wrap:wrap;
}
.tk-bubble--out .tk-bubble-meta { justify-content:flex-end; }
.tk-bubble-name { font-weight:700;color:var(--tk-text);font-size:12px; }
.tk-bubble-email { color:var(--tk-sky);font-size:10.5px; }
.tk-bubble-msg {
  background:var(--tk-bg3);border:1px solid var(--tk-border);
  border-radius:4px 12px 12px 12px;
  padding:12px 16px;font-size:13px;line-height:1.7;
  color:var(--tk-text2);white-space:pre-wrap;word-break:break-word;
}
.tk-bubble--out .tk-bubble-msg {
  background:rgba(6,182,212,.08);border-color:rgba(6,182,212,.2);
  color:var(--tk-text);border-radius:12px 4px 12px 12px;
}
.tk-bubble-badge {
  display:inline-flex;padding:2px 7px;border-radius:4px;
  font-size:9px;font-weight:700;letter-spacing:.4px;
}
.bbl-in  { background:rgba(99,102,241,.15);color:#a78bfa; }
.bbl-out { background:rgba(6,182,212,.15);color:var(--tk-sky); }
.bbl-orig { background:rgba(245,158,11,.12);color:var(--tk-accent); }

/* Email fields inside bubble */
.tk-email-field { margin-bottom:8px; }
.tk-email-field-lbl {
  font-family:var(--tk-mono);font-size:9.5px;font-weight:600;
  text-transform:uppercase;letter-spacing:.6px;color:var(--tk-text3);
  display:block;margin-bottom:2px;
}
.tk-email-field-val { font-size:13px;color:var(--tk-text); }
.tk-email-field-val a { color:var(--tk-sky); }
.tk-email-divider { border:none;border-top:1px solid var(--tk-border);margin:10px 0; }

/* Thread sep */
.tk-thread-sep {
  display:flex;align-items:center;gap:8px;
  font-family:var(--tk-mono);font-size:9.5px;color:var(--tk-text3);
  letter-spacing:.5px;text-transform:uppercase;
}
.tk-thread-sep::before,.tk-thread-sep::after { content:'';flex:1;height:1px;background:var(--tk-border); }

/* Attachments */
.tk-attachments { display:flex;flex-wrap:wrap;gap:5px;margin-top:8px; }
.tk-attach-file {
  display:inline-flex;align-items:center;gap:5px;
  font-size:11px;color:var(--tk-sky);font-weight:600;
  background:rgba(6,182,212,.08);border:1px solid rgba(6,182,212,.2);
  border-radius:5px;padding:3px 9px;text-decoration:none;transition:background .12s;
}
.tk-attach-file:hover { background:rgba(6,182,212,.15);text-decoration:none; }
.tk-attach-img { max-width:200px;max-height:140px;border-radius:7px;margin-top:6px;border:1px solid var(--tk-border);display:block; }

/* ── REPLY BOX ── */
.tk-reply-box {
  border-top:2px solid var(--tk-border);background:var(--tk-bg2);
  flex-shrink:0;padding:14px 22px;
}
.tk-reply-hdr {
  font-size:12.5px;font-weight:700;color:var(--tk-text2);
  margin-bottom:10px;display:flex;align-items:center;gap:7px;
}
.tk-reply-hdr strong { color:var(--tk-sky); }
.tk-reply-ta {
  width:100%;min-height:90px;background:var(--tk-bg4);
  border:1.5px solid var(--tk-border);border-radius:var(--tk-radius);
  padding:10px 13px;font-family:var(--tk-font);font-size:13px;
  color:var(--tk-text);resize:vertical;outline:none;transition:border-color .14s;
}
.tk-reply-ta:focus { border-color:var(--tk-sky); }
.tk-reply-ta::placeholder { color:var(--tk-text3); }
.tk-reply-foot { display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:7px; }
.tk-reply-hint { font-size:11px;color:var(--tk-text3);display:flex;align-items:center;gap:5px; }

/* ── EMPTY THREAD ── */
.tk-empty-thread {
  display:flex;flex-direction:column;align-items:center;
  justify-content:center;padding:50px 24px;gap:12px;text-align:center;
}
.tk-empty-thread i { font-size:40px;color:var(--tk-text3);opacity:.25; }
.tk-empty-thread p { font-size:12.5px;color:var(--tk-text3); }

/* ── NOTIF BODY (non-email) ── */
.tk-notif-body-wrap { padding:24px 22px; }
.tk-notif-body {
  font-size:14px;line-height:1.8;color:var(--tk-text);
  white-space:pre-wrap;word-break:break-word;
}
.tk-notif-ref {
  padding:14px 22px;border-top:1px solid var(--tk-border);
  background:var(--tk-bg2);flex-shrink:0;
}

/* ── ALERTS ── */
.tk-alert {
  display:flex;align-items:center;gap:10px;
  padding:10px 22px;font-size:13px;font-weight:500;flex-shrink:0;
}
.tk-alert-ok  { background:rgba(16,185,129,.1);color:#6ee7b7;border-bottom:1px solid rgba(16,185,129,.2); }
.tk-alert-err { background:rgba(239,68,68,.1); color:#fca5a5;border-bottom:1px solid rgba(239,68,68,.2); }

/* ── SIDEBAR ── */
.tk-sb-section { border-bottom:1px solid var(--tk-border); }
.tk-sb-hd {
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 16px;
  font-family:var(--tk-mono);font-size:9.5px;font-weight:600;
  letter-spacing:.7px;text-transform:uppercase;
  color:var(--tk-text3);background:var(--tk-bg3);
  cursor:pointer;border-bottom:1px solid var(--tk-border);
}
.tk-sb-hd i { font-size:9px;transition:transform .2s; }
.tk-sb-body { background:var(--tk-bg2); }
.tk-prop-row {
  display:grid;grid-template-columns:90px 1fr;gap:5px;
  padding:8px 16px;border-bottom:1px solid rgba(255,255,255,.03);align-items:start;
}
.tk-prop-row:last-child { border-bottom:none; }
.tk-prop-lbl { font-size:10.5px;font-weight:600;color:var(--tk-text3);padding-top:2px; }
.tk-prop-val { font-size:12px;color:var(--tk-text);font-weight:500;word-break:break-word; }
.tk-prop-val a { color:var(--tk-sky); }

/* Requester */
.tk-req-card {
  display:flex;flex-direction:column;align-items:center;
  padding:18px 16px;border-bottom:1px solid var(--tk-border);text-align:center;
}
.tk-req-av {
  width:52px;height:52px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:20px;font-weight:700;color:#fff;margin-bottom:8px;
  background:linear-gradient(135deg,#6366f1,#4338ca);
}
.tk-req-name { font-size:13px;font-weight:700;color:var(--tk-text);margin-bottom:2px; }
.tk-req-email { font-size:11px;color:var(--tk-sky); }
.tk-req-link { font-size:11px;color:var(--tk-blue2);margin-top:6px;cursor:pointer;font-weight:600; }

/* Nav links in sidebar */
.tk-sb-nav-item {
  display:flex;align-items:center;gap:8px;justify-content:center;
  padding:8px 14px;margin:4px 8px;border-radius:var(--tk-radius);
  font-size:12px;font-weight:600;color:var(--tk-text2);
  background:var(--tk-bg3);border:1px solid var(--tk-border);
  text-decoration:none;transition:all .12s;
}
.tk-sb-nav-item:hover { background:var(--tk-bg4);color:var(--tk-text);text-decoration:none; }

/* Icon helpers */
.ico-sky    { background:rgba(6,182,212,.15);color:var(--tk-sky); }
.ico-amber  { background:rgba(245,158,11,.15);color:var(--tk-accent); }
.ico-red    { background:rgba(239,68,68,.15);color:var(--tk-red); }
.ico-blue   { background:rgba(59,130,246,.15);color:var(--tk-blue2); }
.ico-green  { background:rgba(16,185,129,.15);color:var(--tk-green); }
.ico-orange { background:rgba(249,115,22,.15);color:var(--tk-orange); }
.ico-purple { background:rgba(139,92,246,.15);color:var(--tk-purple); }
.ico-navy   { background:rgba(255,255,255,.06);color:var(--tk-text2); }

.dot-online { width:8px;height:8px;border-radius:50%;background:var(--tk-green);display:inline-block;flex-shrink:0; }

@media(max-width:800px){
  .tk-main-grid { grid-template-columns:1fr; }
  .tk-sidebar { display:none; }
}
</style>

<div class="tk-detail">

<!-- ── TOPBAR ── -->
<div class="tk-topbar">
  <a href="index.php" class="tk-back"><i class="fas fa-arrow-left"></i> Back</a>
  <div class="tk-topbar-divider"></div>
  <span class="tk-topbar-title"><?= htmlspecialchars($notif['title']) ?></span>
  <div class="tk-topbar-right">
    <span style="font-family:var(--tk-mono);font-size:10px;background:var(--tk-bg3);color:var(--tk-text2);padding:3px 9px;border-radius:9px;border:1px solid var(--tk-border);">
      <?= $is_email ? 'Email Thread' : 'Notification' ?>
    </span>
    <?php if ($is_email && $is_admin && $sender_email): ?>
    <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=reply"
       class="tk-btn tk-btn-sky tk-btn-sm"><i class="fas fa-reply"></i> Reply</a>
    <?php endif; ?>
    <a href="index.php" class="tk-btn tk-btn-danger tk-btn-sm"><i class="fas fa-times"></i> Close</a>
  </div>
</div>

<!-- ── TICKET HEADER ── -->
<div class="tk-ticket-hd">
  <div class="tk-ticket-hd-icon <?= $ico_class ?>">
    <i class="fas <?= $icon ?>"></i>
  </div>
  <div class="tk-ticket-hd-content">
    <div>
      <span class="tk-ticket-badge <?= $is_email?'badge-email':($type_label==='Announcement'?'badge-announce':'badge-other') ?>">
        <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($type_label) ?>
      </span>
    </div>
    <div class="tk-ticket-title"><?= htmlspecialchars($notif['title']) ?></div>
    <div class="tk-ticket-meta">
      <div class="tk-ticket-meta-item"><i class="fas fa-hashtag" style="font-size:10px"></i> NTF-<?= str_pad($notif_id,4,'0',STR_PAD_LEFT) ?></div>
      <div class="tk-ticket-meta-item"><i class="far fa-clock" style="font-size:10px"></i> <?= date('M j, Y g:i A',strtotime($notif['created_at'])) ?></div>
      <?php if ($is_email && $sender_name): ?>
      <div class="tk-ticket-meta-item"><i class="fas fa-user" style="font-size:10px"></i> Requested By <strong><?= htmlspecialchars($sender_name) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── ACTION BAR ── -->
<div class="tk-action-bar">
  <?php if ($is_email && $is_admin && $sender_email): ?>
  <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=reply_all"
     class="tk-btn tk-btn-blue tk-btn-sm"><i class="fas fa-reply-all"></i> Reply All</a>
  <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=reply"
     class="tk-btn tk-btn-ghost tk-btn-sm"><i class="fas fa-reply"></i> Reply</a>
  <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=forward"
     class="tk-btn tk-btn-ghost tk-btn-sm"><i class="fas fa-share"></i> Forward</a>
  <?php endif; ?>
  <?php if ($ref_url): ?>
  <a href="<?= htmlspecialchars($ref_url) ?>" class="tk-btn tk-btn-primary tk-btn-sm">
    <i class="fas fa-external-link-alt"></i> View <?= htmlspecialchars(ucwords($rt)) ?>
  </a>
  <?php endif; ?>
  <span class="tk-btn tk-btn-ghost tk-btn-sm" style="margin-left:auto"><i class="fas fa-print"></i></span>
  <span class="tk-btn tk-btn-ghost tk-btn-sm"><i class="fas fa-ellipsis-h"></i> Actions</span>
</div>

<!-- ── STATUS BAR ── -->
<div class="tk-status-bar">
  <span class="tk-status-label">Status:</span>
  <span class="tk-status-badge sb-open"><span style="width:7px;height:7px;border-radius:50%;background:var(--tk-blue2);display:inline-block"></span> Open</span>
  <span class="tk-status-label" style="margin-left:10px">Transitions:</span>
  <div class="tk-transitions">
    <button class="tk-btn tk-btn-ghost tk-btn-sm">On Hold</button>
    <button class="tk-btn tk-btn-primary tk-btn-sm">Work In Progress</button>
  </div>
</div>

<!-- ── MAIN GRID ── -->
<div class="tk-main-grid">

  <!-- Content -->
  <div class="tk-content">

    <!-- Alerts -->
    <?php if ($send_success): ?>
    <div class="tk-alert tk-alert-ok"><i class="fas fa-check-circle"></i> <?= $send_success ?></div>
    <?php endif; ?>
    <?php if ($send_error): ?>
    <div class="tk-alert tk-alert-err"><i class="fas fa-exclamation-circle"></i> <?= $send_error ?></div>
    <?php endif; ?>

    <!-- Conv tabs -->
    <div class="tk-conv-tabs">
      <div class="tk-conv-tab active">Conversations</div>
      <div class="tk-conv-tab">Details</div>
      <div class="tk-conv-tab">Tasks</div>
      <div class="tk-conv-tab">Checklists</div>
      <div class="tk-conv-tab">Resolution</div>
      <div class="tk-conv-tab">Reminders</div>
      <div class="tk-conv-tab">Approvals</div>
    </div>

    <?php if ($is_email): ?>
    <!-- Conv filter -->
    <div class="tk-conv-filter">
      <span class="tk-conv-filter-lbl">Filter:</span>
      <label class="tk-conv-filter-item"><input type="checkbox" checked> <i class="fas fa-envelope" style="color:var(--tk-sky);font-size:11px"></i> Emails</label>
      <label class="tk-conv-filter-item"><input type="checkbox"> Auto Notifications</label>
      <label class="tk-conv-filter-item"><input type="checkbox" checked> <i class="fas fa-sticky-note" style="color:var(--tk-accent);font-size:11px"></i> Notes</label>
      <div class="tk-conv-sort"><i class="fas fa-sort"></i> Sort</div>
    </div>

    <!-- Thread -->
    <div class="tk-thread">
      <!-- Original message -->
      <div class="tk-bubble tk-bubble--in">
        <div class="tk-bubble-av"><?= strtoupper(substr($sender_name?:'R',0,1)) ?></div>
        <div class="tk-bubble-wrap">
          <div class="tk-bubble-meta">
            <span class="tk-bubble-name"><?= htmlspecialchars($sender_name?:'Resident') ?></span>
            <?php if ($sender_email): ?><span class="tk-bubble-email"><?= htmlspecialchars($sender_email) ?></span><?php endif; ?>
            <span><?= date('M j, Y g:i A',strtotime($notif['created_at'])) ?></span>
            <span class="tk-bubble-badge bbl-orig">Original</span>
          </div>
          <div class="tk-bubble-msg">
            <?php if ($sender_email): ?>
            <div class="tk-email-field">
              <span class="tk-email-field-lbl">To</span>
              <div class="tk-email-field-val">ict.support (Barangay Office)</div>
            </div>
            <hr class="tk-email-divider">
            <?php endif; ?>
            <?= nl2br(htmlspecialchars($notif['message'])) ?>
          </div>
        </div>
      </div>

      <?php if (!empty($email_replies)): ?>
      <div class="tk-thread-sep">Replies</div>
      <?php foreach ($email_replies as $rep):
        $out = ($rep['direction']==='outbound');
        $bc  = $out ? 'tk-bubble--out' : 'tk-bubble--in';
        $ini = $out ? 'B' : strtoupper(substr($rep['from_name']??'R',0,1));
        $dn  = $out ? 'Barangay Office' : htmlspecialchars($rep['from_name']?:$rep['from_email']??'Resident');
        $atts=[];
        if (!empty($rep['attachments'])) { $d=json_decode($rep['attachments'],true); if(is_array($d))$atts=$d; }
      ?>
      <div class="tk-bubble <?= $bc ?>">
        <div class="tk-bubble-av"><?= $ini ?></div>
        <div class="tk-bubble-wrap">
          <div class="tk-bubble-meta">
            <span class="tk-bubble-name"><?= $dn ?></span>
            <?php if (!$out && !empty($rep['from_email'])): ?><span class="tk-bubble-email"><?= htmlspecialchars($rep['from_email']) ?></span><?php endif; ?>
            <span><?= date('M j, Y g:i A',strtotime($rep['created_at'])) ?></span>
            <span class="tk-bubble-badge <?= $out?'bbl-out':'bbl-in' ?>"><?= $out?'Sent':'Received' ?></span>
          </div>
          <div class="tk-bubble-msg"><?= nl2br(htmlspecialchars($rep['body_text']?:strip_tags($rep['body_html']??''))) ?></div>
          <?php if (!empty($atts)): ?>
          <div class="tk-attachments">
            <?php foreach($atts as $a): ?>
              <?php if (!empty($a['is_image'])&&!empty($a['url'])): ?>
                <img src="<?= htmlspecialchars($a['url']) ?>" alt="<?= htmlspecialchars($a['filename']??'img') ?>" class="tk-attach-img">
              <?php elseif(!empty($a['url'])): ?>
                <a href="<?= htmlspecialchars($a['url']) ?>" target="_blank" class="tk-attach-file">
                  <i class="fas fa-paperclip"></i><?= htmlspecialchars($a['filename']??'file') ?>
                  <?php if(!empty($a['size'])): ?><span style="opacity:.6">(<?= round($a['size']/1024,1) ?>KB)</span><?php endif; ?>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="tk-empty-thread">
        <i class="fas fa-comment-slash"></i>
        <p>No replies yet in this thread.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Quick Reply -->
    <?php if ($is_admin && $sender_email): ?>
    <div class="tk-reply-box">
      <form method="POST">
        <input type="hidden" name="reply_to" value="<?= htmlspecialchars($sender_email) ?>">
        <div class="tk-reply-hdr">
          <i class="fas fa-reply" style="color:var(--tk-sky)"></i>
          Quick Reply to <strong><?= htmlspecialchars($sender_name?:$sender_email) ?></strong>
        </div>
        <textarea name="reply_body" class="tk-reply-ta" placeholder="Type your reply here…"></textarea>
        <div class="tk-reply-foot">
          <span class="tk-reply-hint"><i class="fas fa-paper-plane"></i> <?= htmlspecialchars($sender_email) ?></span>
          <div style="display:flex;gap:7px">
            <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=reply"
               class="tk-btn tk-btn-ghost tk-btn-sm"><i class="fas fa-expand-alt"></i> Full Compose</a>
            <button type="submit" name="quick_reply" value="1" class="tk-btn tk-btn-sky tk-btn-sm">
              <i class="fas fa-paper-plane"></i> Send
            </button>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- Non-email notification body -->
    <div class="tk-notif-body-wrap">
      <div class="tk-notif-body"><?= htmlspecialchars($notif['message']) ?></div>
    </div>
    <?php if ($ref_url): ?>
    <div class="tk-notif-ref">
      <a href="<?= htmlspecialchars($ref_url) ?>" class="tk-btn tk-btn-primary tk-btn-sm">
        <i class="fas fa-external-link-alt"></i> View <?= htmlspecialchars(ucwords($rt)) ?> Details
      </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div><!-- /content -->

  <!-- ── SIDEBAR ── -->
  <div class="tk-sidebar">

    <!-- Properties -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkToggleSb(this)">Properties <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body">
        <div class="tk-prop-row"><div class="tk-prop-lbl">Request ID</div><div class="tk-prop-val" style="font-family:var(--tk-mono);font-size:11.5px">NTF-<?= str_pad($notif_id,4,'0',STR_PAD_LEFT) ?></div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Status</div><div class="tk-prop-val"><span style="background:rgba(59,130,246,.12);color:var(--tk-blue2);padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;">Open</span></div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Type</div><div class="tk-prop-val"><?= htmlspecialchars($type_label) ?></div></div>
        <?php if ($rt): ?><div class="tk-prop-row"><div class="tk-prop-lbl">Category</div><div class="tk-prop-val"><?= htmlspecialchars(ucwords(str_replace('_',' ',$rt))) ?></div></div><?php endif; ?>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Priority</div><div class="tk-prop-val" style="color:var(--tk-text3)">Not Assigned</div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Technician</div><div class="tk-prop-val"><span class="dot-online"></span> Admin</div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Received</div><div class="tk-prop-val" style="font-size:11px;font-family:var(--tk-mono)"><?= date('M j, Y g:i A',strtotime($notif['created_at'])) ?></div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Tasks</div><div class="tk-prop-val">0/0</div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Checklists</div><div class="tk-prop-val">0/0</div></div>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Reminders</div><div class="tk-prop-val">0</div></div>
        <?php if (!empty($notif['reference_id'])): ?>
        <div class="tk-prop-row"><div class="tk-prop-lbl">Ref ID</div><div class="tk-prop-val" style="font-family:var(--tk-mono);font-size:11.5px">#<?= intval($notif['reference_id']) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($is_email && ($sender_name || $sender_email)): ?>
    <!-- Requester -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkToggleSb(this)">Requester Details <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body">
        <div class="tk-req-card">
          <div class="tk-req-av"><?= strtoupper(substr($sender_name?:'R',0,1)) ?></div>
          <div class="tk-req-name"><?= htmlspecialchars($sender_name?:'Resident') ?></div>
          <?php if ($sender_email): ?><div class="tk-req-email"><?= htmlspecialchars($sender_email) ?></div><?php endif; ?>
          <div class="tk-req-link">View Full Details ▾</div>
        </div>
        <?php if ($is_admin && $sender_email): ?>
        <div style="padding:8px 12px;display:flex;flex-direction:column;gap:6px;">
          <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=reply"
             class="tk-btn tk-btn-sky tk-btn-sm" style="justify-content:center"><i class="fas fa-reply"></i> Reply</a>
          <a href="compose-reply.php?notif_id=<?= $notif_id ?>&to_email=<?= urlencode($sender_email) ?>&to_name=<?= urlencode($sender_name) ?>&mode=forward"
             class="tk-btn tk-btn-ghost tk-btn-sm" style="justify-content:center"><i class="fas fa-share"></i> Forward</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($ref_url): ?>
    <!-- Linked Record -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkToggleSb(this)">Linked Record <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body" style="padding:10px 12px">
        <a href="<?= htmlspecialchars($ref_url) ?>" class="tk-btn tk-btn-primary tk-btn-sm" style="width:100%;justify-content:center">
          <i class="fas fa-external-link-alt"></i> View <?= htmlspecialchars(ucwords($rt)) ?>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Navigate -->
    <div class="tk-sb-section">
      <div class="tk-sb-hd" onclick="tkToggleSb(this)">Navigate <i class="fas fa-chevron-up"></i></div>
      <div class="tk-sb-body" style="padding:8px 0">
        <a href="index.php" class="tk-sb-nav-item"><i class="fas fa-list"></i> All Notifications</a>
        <a href="index.php?filter=unread" class="tk-sb-nav-item"><i class="fas fa-envelope"></i> Unread</a>
        <?php if ($is_email): ?>
        <a href="index.php?type=email_reply" class="tk-sb-nav-item"><i class="fas fa-inbox"></i> All Emails</a>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /sidebar -->

</div><!-- /main-grid -->
</div><!-- /tk-detail -->

<script>
function tkToggleSb(hd) {
  const body=hd.nextElementSibling, icon=hd.querySelector('i');
  if (!body) return;
  const vis=body.style.display!=='none';
  body.style.display=vis?'none':'';
  if (icon) icon.style.transform=vis?'rotate(180deg)':'';
}
</script>

<?php include '../../includes/footer.php'; ?>