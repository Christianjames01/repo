<?php
/**
 * Email Details - modules/notifications/email-details.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$user_query = "SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1";
$stmt = $conn->prepare($user_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$current_user = $user_result->fetch_assoc();
$stmt->close();

$user_role = $current_user['role'] ?? '';

if ($user_role !== 'Super Admin') {
    $_SESSION['error_message'] = 'Access denied. Super Administrator only.';
    header('Location: ../../dashboard.php');
    exit();
}

// Accept both 'id' and 'history_id' query params for compatibility
$history_id = 0;
if (!empty($_GET['id']))         $history_id = (int)$_GET['id'];
elseif (!empty($_GET['history_id'])) $history_id = (int)$_GET['history_id'];

if ($history_id <= 0) {
    $_SESSION['error_message'] = 'Invalid email history ID.';
    header('Location: email-history.php');
    exit();
}

$email_query = "SELECT 
                    eh.*,
                    COALESCE(
                        CONCAT(r.first_name, ' ', r.last_name),
                        u.username
                    ) as sender_name
                FROM tbl_email_history eh
                LEFT JOIN tbl_users u ON eh.sender_id = u.user_id
                LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                WHERE eh.id = ?
                LIMIT 1";

$stmt = $conn->prepare($email_query);
$stmt->bind_param('i', $history_id);
$stmt->execute();
$email = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$email) {
    $_SESSION['error_message'] = 'Email history not found.';
    header('Location: email-history.php');
    exit();
}

$recipients_query = "SELECT * FROM tbl_email_recipients 
                     WHERE email_history_id = ? 
                     ORDER BY resident_name";

$stmt = $conn->prepare($recipients_query);
$stmt->bind_param('i', $history_id);
$stmt->execute();
$recipients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_recipients  = count($recipients);
$sent_successfully = 0;
$failed_to_send    = 0;
$no_email          = 0;

foreach ($recipients as $r) {
    if ($r['email_sent'])       $sent_successfully++;
    elseif (!$r['has_email'])   $no_email++;
    else                        $failed_to_send++;
}

$success_rate = $total_recipients > 0
    ? round(($sent_successfully / $total_recipients) * 100, 1)
    : 0;

$safeDate = function (string $format, ?string $value, string $fallback = '—'): string {
    if (empty($value)) return $fallback;
    if (in_array(trim($value), ['0000-00-00', '0000-00-00 00:00:00'], true)) return $fallback;
    $ts = strtotime($value);
    if ($ts === false || $ts < 0) return $fallback;
    return date($format, $ts);
};

$type_map = [
    'general'           => ['db-badge--muted',  'General',       'fa-info-circle',          'muted'],
    'announcement'      => ['db-badge--indigo',  'Announcement',  'fa-bullhorn',             'indigo'],
    'alert'             => ['db-badge--amber',   'Alert',         'fa-exclamation-triangle', 'amber'],
    'incident_reported' => ['db-badge--danger',  'Incident',      'fa-fire',                 'danger'],
    'status_update'     => ['db-badge--sky',     'Status Update', 'fa-sync-alt',             'sky'],
];
$type_info  = $type_map[$email['notification_type']] ?? ['db-badge--muted', ucfirst($email['notification_type']), 'fa-envelope', 'muted'];
$badge_cls  = $type_info[0];
$type_label = $type_info[1];
$type_icon  = $type_info[2];
$type_color = $type_info[3];

$bg_map  = ['indigo'=>'rgba(99,102,241,.12)','sky'=>'rgba(14,165,233,.12)','amber'=>'rgba(245,158,11,.12)','danger'=>'rgba(239,68,68,.12)','success'=>'rgba(16,185,129,.12)','muted'=>'#f1f5f9'];
$txt_map = ['indigo'=>'#6366f1','sky'=>'#0ea5e9','amber'=>'#b45309','danger'=>'#ef4444','success'=>'#10b981','muted'=>'#64748b'];

$page_title = 'Email Details';
include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* Hero */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-indigo),#4338ca);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px;}
.rm-hero__chip{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:20px;padding:3px 10px;font-size:11.5px;color:rgba(255,255,255,.85);font-family:'DM Mono',monospace;}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--ghost{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);}
.db-btn--ghost:hover{background:rgba(255,255,255,.22);color:#fff;}
.db-btn--ghost-dark{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost-dark:hover{background:var(--db-border);}

/* Stats */
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--danger{background:var(--db-danger-light);color:var(--db-danger);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--danger{background:linear-gradient(90deg,var(--db-danger),transparent);}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__body{padding:22px;}

/* Badge */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--danger{background:var(--db-danger-light);color:#9f1239;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}

/* Info rows */
.db-info-row{display:flex;align-items:flex-start;padding:11px 0;border-bottom:1px solid var(--db-border);gap:12px;}
.db-info-row:last-child{border-bottom:none;padding-bottom:0;}
.db-info-label{flex:0 0 160px;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);padding-top:2px;}
.db-info-value{flex:1;font-size:13.5px;color:var(--db-text);}

/* Message box */
.db-message-box{background:var(--db-surf2);border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);padding:14px 16px;line-height:1.7;font-size:13.5px;color:var(--db-text);}

/* Progress bars */
.db-prog-wrap{background:#e9ecef;border-radius:4px;overflow:hidden;height:6px;}
.db-prog-bar{height:100%;border-radius:4px;transition:width .6s ease;}

/* Success rate donut-ish display */
.db-rate-display{text-align:center;padding:16px 0 20px;}
.db-rate-num{font-size:52px;font-weight:800;letter-spacing:-2px;color:var(--db-indigo);line-height:1;}
.db-rate-label{font-family:'DM Mono',monospace;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-top:4px;}

/* Table */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:var(--db-surf2);}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}

/* Filter pill buttons */
.db-filter-group{display:flex;flex-wrap:wrap;gap:6px;}
.db-filter-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 13px;border-radius:20px;font-family:'DM Mono',monospace;font-size:11px;font-weight:500;cursor:pointer;border:1.5px solid var(--db-border);background:var(--db-surf2);color:var(--db-muted);transition:all .15s;white-space:nowrap;}
.db-filter-btn:hover{border-color:var(--db-indigo);color:var(--db-indigo);background:var(--db-indigo-light);}
.db-filter-btn.active{background:var(--db-navy);border-color:var(--db-navy);color:#fff;}
.db-filter-btn.active-success{background:var(--db-success);border-color:var(--db-success);color:#fff;}
.db-filter-btn.active-amber{background:var(--db-amber-dark);border-color:var(--db-amber-dark);color:#fff;}
.db-filter-btn.active-danger{background:var(--db-danger);border-color:var(--db-danger);color:#fff;}

/* URL link */
.db-link{color:var(--db-indigo);text-decoration:none;word-break:break-all;}
.db-link:hover{text-decoration:underline;}

/* Two-col layout */
.db-detail-grid{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;margin-bottom:18px;}
@media(max-width:960px){.db-detail-grid{grid-template-columns:1fr;}.rm-hero{padding:20px;border-radius:0;}}
/* ══════════════════════════════════════
   DARK MODE — email-details.php
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }

/* Hero */
body.dark-mode .rm-hero .db-btn--ghost {
    background: rgba(255,255,255,.1) !important;
    border-color: rgba(255,255,255,.2) !important;
    color: rgba(255,255,255,.85) !important;
}
body.dark-mode .rm-hero .db-btn--ghost:hover {
    background: rgba(255,255,255,.18) !important;
    color: #fff !important;
}
body.dark-mode .rm-hero__chip {
    background: rgba(255,255,255,.08) !important;
    border-color: rgba(255,255,255,.14) !important;
    color: rgba(255,255,255,.75) !important;
}

/* Stat cards */
body.dark-mode .db-stat-card { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-stat-card:hover { background: #243044 !important; }
body.dark-mode .db-stat-card__label { color: #94a3b8 !important; }

/* Panels */
body.dark-mode .db-panel { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-panel__header { border-color: #334155 !important; }
body.dark-mode .db-panel__title h2 { color: #f1f5f9 !important; }

/* Info rows */
body.dark-mode .db-info-row { border-color: #334155 !important; }
body.dark-mode .db-info-label { color: #64748b !important; }
body.dark-mode .db-info-value { color: #e2e8f0 !important; }

/* Message box */
body.dark-mode .db-message-box {
    background: #162032 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

/* Badges */
body.dark-mode .db-badge--muted {
    background: #243044 !important;
    color: #94a3b8 !important;
    border-color: #334155 !important;
}

/* Success rate display */
body.dark-mode .db-rate-num { color: #a5b4fc !important; }
body.dark-mode .db-rate-label { color: #64748b !important; }

/* Progress bars */
body.dark-mode .db-prog-wrap { background: #243044 !important; }

/* Success/failure mini boxes inside sidebar */
body.dark-mode [style*="background:var(--db-success-light)"] {
    background: rgba(16,185,129,.15) !important;
}
body.dark-mode [style*="background:var(--db-danger-light)"] {
    background: rgba(239,68,68,.15) !important;
}

/* Table */
body.dark-mode .db-table tbody tr { border-color: #334155 !important; }
body.dark-mode .db-table tbody tr:hover { background: #243044 !important; }
body.dark-mode .db-table tbody td { color: #e2e8f0 !important; }
body.dark-mode .db-table tbody td [style*="color:var(--db-muted)"],
body.dark-mode .db-table tbody td span[style*="color:var(--db-muted)"] { color: #64748b !important; }
body.dark-mode .db-table tbody td span[style*="color:var(--db-border)"] { color: #334155 !important; }
body.dark-mode .db-table tbody td span[style*="color:var(--db-danger)"] { color: #fca5a5 !important; }

/* Filter buttons */
body.dark-mode .db-filter-btn {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #94a3b8 !important;
}
body.dark-mode .db-filter-btn:hover {
    background: rgba(99,102,241,.15) !important;
    border-color: var(--db-indigo) !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-filter-btn.active {
    background: var(--db-navy-light) !important;
    border-color: var(--db-navy-light) !important;
    color: #fff !important;
}
body.dark-mode .db-filter-btn.active-success {
    background: var(--db-success) !important;
    border-color: var(--db-success) !important;
    color: #fff !important;
}
body.dark-mode .db-filter-btn.active-amber {
    background: var(--db-amber-dark) !important;
    border-color: var(--db-amber-dark) !important;
    color: #fff !important;
}
body.dark-mode .db-filter-btn.active-danger {
    background: var(--db-danger) !important;
    border-color: var(--db-danger) !important;
    color: #fff !important;
}

/* Action URL link */
body.dark-mode .db-link { color: #818cf8 !important; }
body.dark-mode .db-link:hover { color: #a5b4fc !important; }

/* Empty state */
body.dark-mode .db-table + div i,
body.dark-mode [style*="color:var(--db-border)"] { color: #334155 !important; }
body.dark-mode [style*="color:var(--db-muted)"] { color: #64748b !important; }

/* Ghost-dark button (quick actions) */
body.dark-mode .db-btn--ghost-dark {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-mode .db-btn--ghost-dark:hover {
    background: #2d3f58 !important;
    color: #e2e8f0 !important;
}
</style>

<!-- HERO -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon" style="background:linear-gradient(135deg,<?= $bg_map[$type_color] ?? 'rgba(99,102,241,.2)' ?>,rgba(255,255,255,.05));box-shadow:none;border:1px solid rgba(255,255,255,.15);">
                <i class="fas <?= $type_icon ?>" style="color:#fff;"></i>
            </div>
            <div>
                <div class="rm-hero__title"><?= htmlspecialchars($email['email_title']) ?></div>
                <div class="rm-hero__meta">
                    <span class="rm-hero__chip"><i class="fas <?= $type_icon ?>"></i><?= $type_label ?></span>
                    <span class="rm-hero__chip"><i class="far fa-calendar"></i><?= $safeDate('M d, Y', $email['sent_at']) ?></span>
                    <span class="rm-hero__chip"><i class="fas fa-user"></i><?= htmlspecialchars($email['sender_name'] ?? 'Unknown') ?></span>
                </div>
            </div>
        </div>
        <a href="email-history.php" class="db-btn db-btn--ghost">
            <i class="fas fa-arrow-left"></i>Back to History
        </a>
    </div>
</div>

<div style="padding:0 24px 32px;">

    <!-- Stats -->
    <div class="db-stats-row">
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div>
            <div>
                <div class="db-stat-card__num"><?= $total_recipients ?></div>
                <div class="db-stat-card__label">Total Recipients</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-success)"><?= $sent_successfully ?></div>
                <div class="db-stat-card__label">Sent Successfully</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--success"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?= $no_email ?></div>
                <div class="db-stat-card__label">No Email</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--danger"><i class="fas fa-times-circle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-danger)"><?= $failed_to_send ?></div>
                <div class="db-stat-card__label">Failed</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--danger"></div>
        </div>
    </div>

    <!-- Detail grid: info + success rate -->
    <div class="db-detail-grid">

        <!-- Email Information -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-envelope-open"></i></div>
                    <h2>Email Information</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-info-row">
                    <div class="db-info-label">Subject</div>
                    <div class="db-info-value" style="font-weight:700;"><?= htmlspecialchars($email['email_title']) ?></div>
                </div>
                <div class="db-info-row">
                    <div class="db-info-label">Sent By</div>
                    <div class="db-info-value"><?= htmlspecialchars($email['sender_name'] ?? 'Unknown') ?></div>
                </div>
                <div class="db-info-row">
                    <div class="db-info-label">Date &amp; Time</div>
                    <div class="db-info-value">
                        <?= $safeDate('F d, Y', $email['sent_at'], 'Not available') ?>
                        <?php if ($safeDate('H:i', $email['sent_at']) !== '—'): ?>
                            <span style="color:var(--db-muted);font-family:'DM Mono',monospace;font-size:12px;margin-left:6px;">
                                <?= $safeDate('h:i A', $email['sent_at']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="db-info-row">
                    <div class="db-info-label">Recipients</div>
                    <div class="db-info-value">
                        <span class="db-badge db-badge--muted"><?= htmlspecialchars($email['recipient_details'] ?? '—') ?></span>
                    </div>
                </div>
                <div class="db-info-row">
                    <div class="db-info-label">Type</div>
                    <div class="db-info-value">
                        <span class="db-badge <?= $badge_cls ?>">
                            <i class="fas <?= $type_icon ?>"></i><?= $type_label ?>
                        </span>
                    </div>
                </div>
                <?php if (!empty($email['action_url'])): ?>
                <div class="db-info-row">
                    <div class="db-info-label">Action URL</div>
                    <div class="db-info-value">
                        <a href="<?= htmlspecialchars($email['action_url']) ?>" target="_blank" class="db-link">
                            <?= htmlspecialchars($email['action_url']) ?>
                            <i class="fas fa-external-link-alt" style="font-size:10px;margin-left:4px;"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <div class="db-info-row" style="align-items:flex-start;">
                    <div class="db-info-label" style="padding-top:4px;">Message</div>
                    <div class="db-info-value">
                        <div class="db-message-box">
                            <?= nl2br(htmlspecialchars($email['email_message'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Rate -->
        <div class="db-panel" style="animation-delay:.08s;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--success"><i class="fas fa-chart-pie"></i></div>
                    <h2>Success Rate</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-rate-display">
                    <div class="db-rate-num"><?= $success_rate ?>%</div>
                    <div class="db-rate-label">Successfully Delivered</div>
                </div>

                <div style="display:flex;flex-direction:column;gap:14px;">
                    <!-- Sent -->
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                            <span style="font-size:12px;font-weight:600;color:var(--db-success);">
                                <i class="fas fa-check-circle me-1"></i>Sent
                            </span>
                            <span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--db-success);"><?= $sent_successfully ?></span>
                        </div>
                        <div class="db-prog-wrap">
                            <div class="db-prog-bar" style="width:<?= $total_recipients > 0 ? ($sent_successfully / $total_recipients * 100) : 0 ?>%;background:var(--db-success);"></div>
                        </div>
                    </div>
                    <!-- No Email -->
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                            <span style="font-size:12px;font-weight:600;color:var(--db-amber-dark);">
                                <i class="fas fa-exclamation-triangle me-1"></i>No Email
                            </span>
                            <span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--db-amber-dark);"><?= $no_email ?></span>
                        </div>
                        <div class="db-prog-wrap">
                            <div class="db-prog-bar" style="width:<?= $total_recipients > 0 ? ($no_email / $total_recipients * 100) : 0 ?>%;background:var(--db-amber);"></div>
                        </div>
                    </div>
                    <!-- Failed -->
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                            <span style="font-size:12px;font-weight:600;color:var(--db-danger);">
                                <i class="fas fa-times-circle me-1"></i>Failed
                            </span>
                            <span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--db-danger);"><?= $failed_to_send ?></span>
                        </div>
                        <div class="db-prog-wrap">
                            <div class="db-prog-bar" style="width:<?= $total_recipients > 0 ? ($failed_to_send / $total_recipients * 100) : 0 ?>%;background:var(--db-danger);"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Recipients Table -->
    <div class="db-panel" style="animation-delay:.14s;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-users"></i></div>
                <h2>Recipients</h2>
                <span class="db-badge db-badge--indigo"><?= $total_recipients ?></span>
            </div>
            <div class="db-filter-group">
                <button class="db-filter-btn active" onclick="filterRows(this,'all')">
                    All <span style="opacity:.7">(<?= $total_recipients ?>)</span>
                </button>
                <button class="db-filter-btn" onclick="filterRows(this,'sent')">
                    <i class="fas fa-check-circle" style="color:var(--db-success)"></i>
                    Sent <span style="opacity:.7">(<?= $sent_successfully ?>)</span>
                </button>
                <button class="db-filter-btn" onclick="filterRows(this,'no-email')">
                    <i class="fas fa-exclamation-triangle" style="color:var(--db-amber-dark)"></i>
                    No Email <span style="opacity:.7">(<?= $no_email ?>)</span>
                </button>
                <button class="db-filter-btn" onclick="filterRows(this,'failed')">
                    <i class="fas fa-times-circle" style="color:var(--db-danger)"></i>
                    Failed <span style="opacity:.7">(<?= $failed_to_send ?>)</span>
                </button>
            </div>
        </div>

        <?php if (empty($recipients)): ?>
            <div style="text-align:center;padding:48px 24px;">
                <i class="fas fa-inbox" style="font-size:40px;color:var(--db-border);"></i>
                <p style="color:var(--db-muted);margin-top:12px;">No recipient records found.</p>
            </div>
        <?php else: ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th>Resident Name</th>
                        <th>Email Address</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Sent At</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody id="recipientsTable">
                <?php foreach ($recipients as $r):
                    if ($r['email_sent'])     { $status = 'sent';     $badge = '<span class="db-badge db-badge--success"><i class="fas fa-check-circle"></i>Sent</span>'; }
                    elseif (!$r['has_email']) { $status = 'no-email'; $badge = '<span class="db-badge db-badge--amber"><i class="fas fa-exclamation-triangle"></i>No Email</span>'; }
                    else                     { $status = 'failed';   $badge = '<span class="db-badge db-badge--rose"><i class="fas fa-times-circle"></i>Failed</span>'; }

                    $date_part = $safeDate('M d, Y', $r['sent_at']);
                    $time_part = $safeDate('h:i:s A', $r['sent_at']);
                ?>
                <tr class="recipient-row" data-status="<?= $status ?>">
                    <td style="font-weight:600;"><?= htmlspecialchars($r['resident_name']) ?></td>
                    <td>
                        <?php if (!empty($r['resident_email'])): ?>
                            <span style="font-family:'DM Mono',monospace;font-size:11.5px;color:var(--db-muted);"><?= htmlspecialchars($r['resident_email']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--db-border);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?= $badge ?></td>
                    <td style="text-align:center;">
                        <?php if ($date_part !== '—'): ?>
                            <div style="font-size:12px;"><?= $date_part ?></div>
                            <div style="font-family:'DM Mono',monospace;font-size:10.5px;color:var(--db-muted);"><?= $time_part ?></div>
                        <?php else: ?>
                            <span style="color:var(--db-border);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['error_message'])): ?>
                            <span style="font-size:11.5px;color:var(--db-danger);">
                                <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($r['error_message']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:var(--db-border);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
const activeClsMap = { all: 'active', sent: 'active-success', 'no-email': 'active-amber', failed: 'active-danger' };

function filterRows(btn, status) {
    // Reset all buttons
    document.querySelectorAll('.db-filter-btn').forEach(b => {
        b.classList.remove('active', 'active-success', 'active-amber', 'active-danger');
    });
    // Set active class
    btn.classList.add(activeClsMap[status] || 'active');

    // Show/hide rows
    document.querySelectorAll('.recipient-row').forEach(row => {
        row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
    });
}
</script>

<?php include '../../includes/footer.php'; ?>