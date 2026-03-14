<?php
/**
 * Email Residents - modules/notifications/email-residents.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/phpmailer/mailer.php';

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

$page_title = 'Email Residents';

$stats = fetchOne($conn, 
    "SELECT 
        COUNT(*) as total_residents,
        COUNT(CASE WHEN email IS NOT NULL AND email != '' THEN 1 END) as with_email,
        COUNT(CASE WHEN email IS NULL OR email = '' THEN 1 END) as without_email
     FROM tbl_residents",
    [], ''
);

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
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
.rm-hero__actions{display:flex;gap:10px;flex-wrap:wrap;}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);}
.db-btn--ghost:hover{background:rgba(255,255,255,.22);color:#fff;}
.db-btn--ghost-dark{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost-dark:hover{background:var(--db-border);}
.db-btn--info{background:linear-gradient(135deg,var(--db-sky),#0284c7);color:#fff;}
.db-btn--info:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.3);color:#fff;}

/* Stats */
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 200px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--warning{background:var(--db-warning-light);color:var(--db-warning);}
.db-panel__body{padding:24px;}

/* Form elements */
.db-form-group{margin-bottom:20px;}
.db-label{display:block;font-size:12px;font-weight:700;color:var(--db-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;font-family:'DM Mono',monospace;}
.db-label span{color:var(--db-rose);margin-left:2px;}
.db-input,.db-select,.db-textarea{width:100%;padding:10px 14px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13.5px;color:var(--db-text);background:var(--db-surf2);transition:border-color .18s,box-shadow .18s;outline:none;}
.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-indigo);box-shadow:0 0 0 3px rgba(99,102,241,.12);background:#fff;}
.db-textarea{resize:vertical;}
.db-hint{font-size:11.5px;color:var(--db-muted);margin-top:4px;}
.db-check{display:flex;align-items:center;gap:8px;cursor:pointer;}
.db-check input[type=checkbox]{width:16px;height:16px;accent-color:var(--db-indigo);cursor:pointer;}
.db-check-label{font-size:13px;font-weight:600;color:var(--db-text);}

/* Resident box */
.db-resident-box{border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px;max-height:300px;overflow-y:auto;background:var(--db-surf2);}
.db-resident-box::-webkit-scrollbar{width:5px;}
.db-resident-box::-webkit-scrollbar-track{background:#f0f2f5;border-radius:3px;}
.db-resident-box::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px;}
.db-resident-item{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:6px;transition:background .15s;cursor:pointer;}
.db-resident-item:hover{background:#fff;}
.db-resident-item input[type=checkbox]{accent-color:var(--db-indigo);}
.db-resident-item label{font-size:13px;cursor:pointer;line-height:1.4;}
.db-resident-item label small{color:var(--db-muted);font-family:'DM Mono',monospace;font-size:11px;}
.db-resident-actions{display:flex;align-items:center;justify-content:space-between;margin-top:8px;}
.db-count-badge{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);background:var(--db-indigo-light);padding:3px 10px;border-radius:20px;font-weight:500;}

/* Info sidebar */
.db-info-list{list-style:none;padding:0;margin:0;}
.db-info-list li{padding:9px 0;font-size:12.5px;color:var(--db-muted);line-height:1.6;border-bottom:1px solid var(--db-border);}
.db-info-list li:last-child{border-bottom:none;padding-bottom:0;}
.db-info-list li strong{color:var(--db-text);font-weight:600;}
.db-info-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;margin-bottom:12px;}

/* Submit button */
.db-submit-btn{width:100%;padding:13px;border-radius:var(--db-radius-sm);border:none;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:all .2s;letter-spacing:.2px;}
.db-submit-btn:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 6px 20px rgba(13,27,54,.3);}
.db-submit-btn:disabled{opacity:.6;cursor:not-allowed;transform:none;}

/* Modal overrides */
.modal-content{border-radius:var(--db-radius-lg)!important;border:none!important;box-shadow:var(--db-shadow-lg)!important;font-family:'Sora',sans-serif;}
.db-modal-icon{width:72px;height:72px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;}
.db-modal-icon--warning{background:var(--db-warning-light);}
.db-modal-icon--primary{background:var(--db-indigo-light);}

/* Progress */
.db-progress-wrap{background:#e9ecef;border-radius:10px;overflow:hidden;height:28px;margin-bottom:20px;}
.db-progress-bar{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--db-indigo),var(--db-sky));transition:width .5s ease;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;font-family:'DM Mono',monospace;}

/* Result stat boxes */
.db-result-stat{background:var(--db-surf2);border-radius:var(--db-radius-sm);padding:14px;text-align:center;border:1px solid var(--db-border);}
.db-result-stat .num{font-size:26px;font-weight:800;letter-spacing:-1px;line-height:1;}
.db-result-stat .lbl{font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-top:4px;}

/* Success checkmark */
.success-checkmark{width:80px;height:80px;margin:0 auto;}
.success-checkmark .check-icon{width:80px;height:80px;position:relative;border-radius:50%;box-sizing:content-box;border:4px solid #10b981;}
.success-checkmark .check-icon::before,.success-checkmark .check-icon::after{content:'';height:100px;position:absolute;background:#fff;transform:rotate(-45deg);}
.success-checkmark .check-icon::before{top:3px;left:-2px;width:30px;transform-origin:100% 50%;border-radius:100px 0 0 100px;}
.success-checkmark .check-icon::after{top:0;left:30px;width:60px;transform-origin:0 50%;border-radius:0 100px 100px 0;animation:rotate-circle 4.25s ease-in;}
.success-checkmark .check-icon .icon-line{height:5px;background-color:#10b981;display:block;border-radius:2px;position:absolute;z-index:10;}
.success-checkmark .check-icon .icon-line.line-tip{top:46px;left:14px;width:25px;transform:rotate(45deg);animation:icon-line-tip .75s;}
.success-checkmark .check-icon .icon-line.line-long{top:38px;right:8px;width:47px;transform:rotate(-45deg);animation:icon-line-long .75s;}
.success-checkmark .check-icon .icon-circle{top:-4px;left:-4px;z-index:10;width:80px;height:80px;border-radius:50%;position:absolute;box-sizing:content-box;border:4px solid rgba(16,185,129,.5);}
.success-checkmark .check-icon .icon-fix{top:8px;width:5px;left:26px;z-index:1;height:85px;position:absolute;transform:rotate(-45deg);background-color:#fff;}
@keyframes rotate-circle{0%{transform:rotate(-45deg)}5%{transform:rotate(-45deg)}12%{transform:rotate(-405deg)}100%{transform:rotate(-405deg)}}
@keyframes icon-line-tip{0%{width:0;left:1px;top:19px}54%{width:0;left:1px;top:19px}70%{width:50px;left:-8px;top:37px}84%{width:17px;left:21px;top:48px}100%{width:25px;left:14px;top:45px}}
@keyframes icon-line-long{0%{width:0;right:46px;top:54px}65%{width:0;right:46px;top:54px}84%{width:55px;right:0;top:35px}100%{width:47px;right:8px;top:38px}}
.error-icon{animation:shake .5s;}
@keyframes shake{0%,100%{transform:translateX(0)}10%,30%,50%,70%,90%{transform:translateX(-10px)}20%,40%,60%,80%{transform:translateX(10px)}}

/* Layout grid */
.db-grid-main{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;}
@media(max-width:960px){.db-grid-main{grid-template-columns:1fr;}.rm-hero{padding:20px;border-radius:0;}}
/* ══════════════════════════════════════
   DARK MODE — email-residents.php
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }

/* Stat cards */
body.dark-mode .db-stat-card { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-stat-card:hover { background: #243044 !important; }
body.dark-mode .db-stat-card__label { color: #94a3b8 !important; }

/* Panels */
body.dark-mode .db-panel { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .db-panel__header { border-color: #334155 !important; }
body.dark-mode .db-panel__title h2 { color: #f1f5f9 !important; }

/* Form inputs */
body.dark-mode .db-input,
body.dark-mode .db-select,
body.dark-mode .db-textarea {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-input:focus,
body.dark-mode .db-select:focus,
body.dark-mode .db-textarea:focus {
    background: #1e293b !important;
    border-color: var(--db-indigo) !important;
    box-shadow: 0 0 0 3px rgba(99,102,241,.2) !important;
}
body.dark-mode .db-input::placeholder,
body.dark-mode .db-textarea::placeholder { color: #64748b !important; }
body.dark-mode .db-label { color: #cbd5e1 !important; }
body.dark-mode .db-hint { color: #64748b !important; }
body.dark-mode .db-check-label { color: #cbd5e1 !important; }

/* Resident selection box */
body.dark-mode .db-resident-box {
    background: #162032 !important;
    border-color: #334155 !important;
}
body.dark-mode .db-resident-box::-webkit-scrollbar-track { background: #1e293b !important; }
body.dark-mode .db-resident-box::-webkit-scrollbar-thumb { background: #334155 !important; }
body.dark-mode .db-resident-item:hover { background: #1e293b !important; }
body.dark-mode .db-resident-item label { color: #cbd5e1 !important; }
body.dark-mode .db-resident-item label small { color: #64748b !important; }
body.dark-mode .db-count-badge {
    background: rgba(99,102,241,.2) !important;
    color: #a5b4fc !important;
}

/* Ghost-dark button (used inside the form, not on hero) */
body.dark-mode .db-btn--ghost-dark {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}
body.dark-mode .db-btn--ghost-dark:hover {
    background: #2d3f58 !important;
    color: #e2e8f0 !important;
}

/* Hero buttons */
body.dark-mode .rm-hero .db-btn--ghost {
    background: rgba(255,255,255,.1) !important;
    border-color: rgba(255,255,255,.2) !important;
    color: rgba(255,255,255,.85) !important;
}
body.dark-mode .rm-hero .db-btn--ghost:hover {
    background: rgba(255,255,255,.18) !important;
    color: #fff !important;
}

/* Info / Tips sidebar lists */
body.dark-mode .db-info-list li {
    border-color: #334155 !important;
    color: #94a3b8 !important;
}
body.dark-mode .db-info-list li strong { color: #e2e8f0 !important; }

/* Modals */
body.dark-mode .modal-content {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .modal-content h5 { color: #f1f5f9 !important; }
body.dark-mode .modal-content p { color: #94a3b8 !important; }
body.dark-mode .db-btn--ghost-dark.w-100 {
    background: #243044 !important;
    border-color: #334155 !important;
    color: #cbd5e1 !important;
}

/* Sending/progress modal */
body.dark-mode .db-progress-wrap { background: #243044 !important; }
body.dark-mode .db-result-stat {
    background: #243044 !important;
    border-color: #334155 !important;
}
body.dark-mode .db-result-stat .lbl { color: #94a3b8 !important; }

/* Warning/info callout boxes inside modals */
body.dark-mode [style*="background:var(--db-warning-light)"] {
    background: rgba(180,83,9,.18) !important;
    border-color: var(--db-amber) !important;
    color: #fcd34d !important;
}
body.dark-mode [style*="background:var(--db-sky-light)"] {
    background: rgba(14,165,233,.15) !important;
    border-color: var(--db-sky) !important;
    color: #7dd3fc !important;
}
body.dark-mode [style*="background:var(--db-danger-light)"] {
    background: rgba(239,68,68,.15) !important;
    border-color: var(--db-danger) !important;
    color: #fca5a5 !important;
}
</style>

<!-- HERO -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-envelope"></i></div>
            <div>
                <div class="rm-hero__title">Email Residents</div>
                <div class="rm-hero__sub">Send notifications via email to residents</div>
            </div>
        </div>
        <div class="rm-hero__actions">
            <a href="email-history.php" class="db-btn db-btn--info">
                <i class="fas fa-history"></i>Email History
            </a>
            <a href="index.php" class="db-btn db-btn--ghost">
                <i class="fas fa-arrow-left"></i>Back
            </a>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

    <!-- Stats -->
    <div class="db-stats-row">
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div>
            <div>
                <div class="db-stat-card__num"><?= $stats['total_residents'] ?></div>
                <div class="db-stat-card__label">Total Residents</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-envelope-open"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-success)"><?= $stats['with_email'] ?></div>
                <div class="db-stat-card__label">With Email Address</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--success"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-exclamation-circle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?= $stats['without_email'] ?></div>
                <div class="db-stat-card__label">Without Email</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
        </div>
    </div>

    <!-- Main grid -->
    <div class="db-grid-main">

        <!-- Compose Form -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-paper-plane"></i></div>
                    <h2>Compose Email</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <form method="POST" id="emailForm">

                    <!-- Recipients -->
                    <div class="db-form-group">
                        <label class="db-label">Recipients <span>*</span></label>
                        <select class="db-select" name="recipient_type" id="recipientType" required onchange="toggleRecipientOptions()">
                            <option value="">— Select Recipients —</option>
                            <option value="all">All Residents with Email</option>
                            <option value="selected">Select Specific Residents</option>
                        </select>
                    </div>

                    <!-- Resident list (conditional) -->
                    <div class="db-form-group d-none" id="residentSelection">
                        <label class="db-label">Select Residents</label>
                        <div class="db-form-group" style="margin-bottom:8px;">
                            <input type="text" class="db-input" id="residentSearch"
                                   placeholder="Search by name or email…" onkeyup="filterResidents()">
                        </div>
                        <div class="db-resident-box" id="residentList">
                            <div style="text-align:center;padding:20px 0;color:var(--db-muted);">
                                <div class="spinner-border spinner-border-sm mb-2" role="status"></div>
                                <div style="font-size:12px;">Loading residents…</div>
                            </div>
                        </div>
                        <div class="db-resident-actions">
                            <div style="display:flex;gap:8px;">
                                <button type="button" class="db-btn db-btn--sm db-btn--ghost-dark" onclick="selectAllResidents()">
                                    Select All
                                </button>
                                <button type="button" class="db-btn db-btn--sm db-btn--ghost-dark" onclick="deselectAllResidents()">
                                    Deselect All
                                </button>
                            </div>
                            <span class="db-count-badge"><span id="selectedCount">0</span> selected</span>
                        </div>
                    </div>

                    <!-- Subject -->
                    <div class="db-form-group">
                        <label class="db-label">Email Subject <span>*</span></label>
                        <input type="text" class="db-input" name="email_title"
                               placeholder="Enter email subject" required maxlength="200">
                    </div>

                    <!-- Notification type -->
                    <div class="db-form-group">
                        <label class="db-label">Notification Type</label>
                        <select class="db-select" name="notification_type">
                            <option value="general">General Notification</option>
                            <option value="announcement">Announcement</option>
                            <option value="alert">Alert / Warning</option>
                            <option value="incident_reported">Incident Report</option>
                            <option value="status_update">Status Update</option>
                        </select>
                    </div>

                    <!-- Message -->
                    <div class="db-form-group">
                        <label class="db-label">Message <span>*</span></label>
                        <textarea class="db-textarea" name="email_message" rows="8"
                                  placeholder="Enter your message here…" required></textarea>
                        <p class="db-hint">You can use simple HTML formatting if needed.</p>
                    </div>

                    <!-- Action link -->
                    <div class="db-form-group">
                        <label class="db-check">
                            <input type="checkbox" name="include_link" value="1"
                                   id="includeLink" onchange="toggleActionUrl()">
                            <span class="db-check-label">Include action button / link</span>
                        </label>
                        <div class="d-none" id="actionUrlDiv" style="margin-top:10px;">
                            <input type="url" class="db-input" name="action_url"
                                   placeholder="https://example.com/view-details">
                            <p class="db-hint">Optional: Add a link for residents to view more details.</p>
                        </div>
                    </div>

                    <button type="submit" name="send_emails" class="db-submit-btn" id="sendEmailBtn">
                        <i class="fas fa-paper-plane"></i>Send Emails
                    </button>
                </form>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <div class="db-panel" style="animation-delay:.08s;">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-info-circle"></i></div>
                        <h2>Information</h2>
                    </div>
                </div>
                <div class="db-panel__body" style="padding:18px 20px;">
                    <ul class="db-info-list">
                        <li>Emails sent from: <strong><?= MAIL_FROM_EMAIL ?></strong></li>
                        <li>Only residents with valid email addresses will receive notifications.</li>
                        <li>Notifications will also be saved in the system.</li>
                        <li>A small delay between emails prevents spam detection.</li>
                    </ul>
                </div>
            </div>

            <div class="db-panel" style="animation-delay:.14s;">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--warning"><i class="fas fa-lightbulb"></i></div>
                        <h2>Tips</h2>
                    </div>
                </div>
                <div class="db-panel__body" style="padding:18px 20px;">
                    <ul class="db-info-list">
                        <li>Keep your subject line clear and concise.</li>
                        <li>Use professional language.</li>
                        <li>Include all necessary details in the message.</li>
                        <li>Test with a small group first if unsure.</li>
                    </ul>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Validation Modal -->
<div class="modal fade" id="validationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="db-modal-icon db-modal-icon--warning">
                        <i class="fas fa-exclamation-triangle fa-2x" style="color:var(--db-warning)"></i>
                    </div>
                    <h5 class="fw-bold mb-2" id="validationModalLabel" style="font-family:'Sora',sans-serif;">Action Required</h5>
                    <p style="color:var(--db-muted);font-size:13.5px;" id="validationModalMessage">Please complete all required fields.</p>
                </div>
                <div class="px-4 pb-4">
                    <button type="button" class="db-btn db-btn--primary w-100 justify-content-center" data-bs-dismiss="modal">
                        <i class="fas fa-check"></i>Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
        <div class="modal-content">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="db-modal-icon db-modal-icon--primary">
                        <i class="fas fa-paper-plane fa-2x" style="color:var(--db-indigo)"></i>
                    </div>
                    <h5 class="fw-bold mb-2" style="font-family:'Sora',sans-serif;">Confirm Send</h5>
                    <p style="color:var(--db-muted);font-size:13.5px;">
                        You are about to send this email to <strong id="confirmRecipientCount">0</strong> resident(s).
                    </p>
                    <div style="background:var(--db-warning-light);border-left:3px solid var(--db-warning);border-radius:6px;padding:10px 14px;text-align:left;font-size:12.5px;color:#92400e;">
                        <i class="fas fa-info-circle me-1"></i>
                        This action cannot be undone. All selected recipients will receive the email immediately.
                    </div>
                </div>
                <div class="px-4 pb-4 d-flex gap-2">
                    <button type="button" class="db-btn db-btn--ghost-dark flex-fill justify-content-center" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i>Cancel
                    </button>
                    <button type="button" class="db-btn db-btn--primary flex-fill justify-content-center" id="confirmSendBtn">
                        <i class="fas fa-paper-plane"></i>Yes, Send Now
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sending Progress Modal -->
<div class="modal fade" id="sendingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-body p-4">

                <!-- Sending -->
                <div id="sendingState" class="text-center py-3">
                    <div style="width:64px;height:64px;border-radius:50%;background:var(--db-indigo-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;">
                        <div class="spinner-border text-primary" style="width:32px;height:32px;" role="status"></div>
                    </div>
                    <h4 style="font-family:'Sora',sans-serif;font-weight:800;margin-bottom:8px;">Sending Emails…</h4>
                    <p style="color:var(--db-muted);margin-bottom:24px;">Please wait while we send notifications to residents.</p>
                    <div class="db-progress-wrap">
                        <div class="db-progress-bar" style="width:0%;" id="emailProgress">
                            <span id="progressText">0%</span>
                        </div>
                    </div>
                    <div style="display:flex;justify-content:space-around;margin-bottom:16px;">
                        <div>
                            <div style="font-size:22px;font-weight:800;color:var(--db-success);font-family:'Sora',sans-serif;" id="sentCount">0</div>
                            <div style="font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;color:var(--db-muted);">Sent</div>
                        </div>
                        <div>
                            <div style="font-size:22px;font-weight:800;color:var(--db-indigo);font-family:'Sora',sans-serif;" id="totalCount">0</div>
                            <div style="font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;color:var(--db-muted);">Total</div>
                        </div>
                        <div>
                            <div style="font-size:22px;font-weight:800;color:var(--db-rose);font-family:'Sora',sans-serif;" id="failedCount">0</div>
                            <div style="font-family:'DM Mono',monospace;font-size:10px;text-transform:uppercase;color:var(--db-muted);">Failed</div>
                        </div>
                    </div>
                    <div style="background:var(--db-sky-light);border-left:3px solid var(--db-sky);border-radius:6px;padding:10px 14px;font-size:12px;color:#0369a1;text-align:left;">
                        <i class="fas fa-info-circle me-2"></i>This may take a few moments. Please don't close this window.
                    </div>
                </div>

                <!-- Success -->
                <div id="successState" class="text-center py-3 d-none">
                    <div class="success-checkmark mb-3">
                        <div class="check-icon">
                            <span class="icon-line line-tip"></span>
                            <span class="icon-line line-long"></span>
                            <div class="icon-circle"></div>
                            <div class="icon-fix"></div>
                        </div>
                    </div>
                    <h4 style="font-family:'Sora',sans-serif;font-weight:800;color:var(--db-success);margin-bottom:8px;">
                        Emails Sent Successfully!
                    </h4>
                    <p style="color:var(--db-muted);margin-bottom:20px;" id="successMessage"></p>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
                        <div class="db-result-stat">
                            <div class="num" style="color:var(--db-success)" id="successSentCount">0</div>
                            <div class="lbl">Sent</div>
                        </div>
                        <div class="db-result-stat">
                            <div class="num" style="color:var(--db-indigo)" id="successTotalCount">0</div>
                            <div class="lbl">Total</div>
                        </div>
                        <div class="db-result-stat">
                            <div class="num" style="color:var(--db-rose)" id="successFailedCount">0</div>
                            <div class="lbl">Failed</div>
                        </div>
                    </div>
                    <button type="button" class="db-btn db-btn--primary" onclick="location.reload()">
                        <i class="fas fa-check"></i>Close
                    </button>
                </div>

                <!-- Error -->
                <div id="errorState" class="text-center py-3 d-none">
                    <div class="error-icon mb-3" style="font-size:52px;color:var(--db-rose);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h4 style="font-family:'Sora',sans-serif;font-weight:800;color:var(--db-rose);margin-bottom:8px;">
                        Email Sending Failed
                    </h4>
                    <p style="color:var(--db-muted);margin-bottom:16px;" id="errorMessage"></p>
                    <div style="background:var(--db-danger-light);border-left:3px solid var(--db-danger);border-radius:6px;padding:12px 16px;text-align:left;margin-bottom:16px;font-size:12.5px;color:#7f1d1d;">
                        <strong>Error Details:</strong><br>
                        <span id="errorDetailsText"></span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
                        <div class="db-result-stat">
                            <div class="num" style="color:var(--db-success)" id="errorSentCount">0</div>
                            <div class="lbl">Sent</div>
                        </div>
                        <div class="db-result-stat">
                            <div class="num" style="color:var(--db-rose)" id="errorFailedCount">0</div>
                            <div class="lbl">Failed</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:center;">
                        <button type="button" class="db-btn db-btn--ghost-dark" onclick="location.reload()">
                            <i class="fas fa-times"></i>Cancel
                        </button>
                        <button type="button" class="db-btn db-btn--primary" onclick="retryEmailSending()">
                            <i class="fas fa-redo"></i>Try Again
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
let residentsLoaded = false;
let allResidentsData = [];
let sendingModalInstance = null;

function showValidationModal(message, title = 'Action Required') {
    document.getElementById('validationModalLabel').textContent = title;
    document.getElementById('validationModalMessage').textContent = message;
    new bootstrap.Modal(document.getElementById('validationModal')).show();
}

function showConfirmModal(recipientCount, onConfirm) {
    document.getElementById('confirmRecipientCount').textContent = recipientCount;
    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    const confirmBtn = document.getElementById('confirmSendBtn');
    const freshBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(freshBtn, confirmBtn);
    freshBtn.addEventListener('click', function () { modal.hide(); onConfirm(); });
    modal.show();
}

function toggleRecipientOptions() {
    const type = document.getElementById('recipientType').value;
    document.getElementById('residentSelection').classList.toggle('d-none', type !== 'selected');
    if (type === 'selected' && !residentsLoaded) loadResidents();
}

function loadResidents() {
    const listDiv = document.getElementById('residentList');
    listDiv.innerHTML = `<div style="text-align:center;padding:20px 0;color:var(--db-muted);"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><p style="font-size:12px;margin-top:8px;">Loading residents…</p></div>`;
    fetch('get-residents-ajax.php')
        .then(r => { if (!r.ok) throw new Error('Server error: ' + r.status); return r.json(); })
        .then(response => {
            if (response.error) throw new Error(response.message || 'Unknown error');
            allResidentsData = response.data || [];
            displayResidents(allResidentsData);
            residentsLoaded = true;
        })
        .catch(error => {
            listDiv.innerHTML = `<div style="background:var(--db-danger-light);border-left:3px solid var(--db-danger);border-radius:6px;padding:12px;font-size:12.5px;color:#7f1d1d;"><i class="fas fa-exclamation-circle me-1"></i>Failed to load residents: ${error.message}<br><small>Please refresh or contact administrator.</small></div>`;
        });
}

function displayResidents(residents) {
    const listDiv = document.getElementById('residentList');
    if (residents.length === 0) {
        listDiv.innerHTML = `<div style="background:var(--db-warning-light);border-left:3px solid var(--db-warning);border-radius:6px;padding:12px;font-size:12.5px;color:#92400e;"><i class="fas fa-info-circle me-1"></i>No residents found with email addresses.</div>`;
        return;
    }
    let html = '';
    residents.forEach(resident => {
        html += `<div class="db-resident-item">
            <input class="form-check-input resident-checkbox" type="checkbox"
                   name="selected_residents[]" value="${resident.id}"
                   id="res${resident.id}"
                   data-name="${resident.name.toLowerCase()}"
                   data-email="${resident.email.toLowerCase()}">
            <label for="res${resident.id}">${escapeHtml(resident.name)} <small>(${escapeHtml(resident.email)})</small></label>
        </div>`;
    });
    listDiv.innerHTML = html;
    document.querySelectorAll('.resident-checkbox').forEach(cb => cb.addEventListener('change', updateSelectedCount));
}

function filterResidents() {
    const term = document.getElementById('residentSearch').value.toLowerCase();
    let visible = 0;
    document.querySelectorAll('.db-resident-item').forEach(item => {
        const cb = item.querySelector('.resident-checkbox');
        const match = cb.dataset.name.includes(term) || cb.dataset.email.includes(term);
        item.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const noRes = document.querySelector('.db-no-results');
    if (visible === 0 && term) {
        if (!noRes) {
            const d = document.createElement('div');
            d.className = 'db-no-results';
            d.style.cssText = 'font-size:12px;color:var(--db-muted);padding:10px 0;text-align:center;';
            d.innerHTML = '<i class="fas fa-search me-1"></i>No residents match your search.';
            document.getElementById('residentList').prepend(d);
        }
    } else if (noRes) noRes.remove();
}

function escapeHtml(text) { const d=document.createElement('div');d.textContent=text;return d.innerHTML; }
function toggleActionUrl() { document.getElementById('actionUrlDiv').classList.toggle('d-none', !document.getElementById('includeLink').checked); }
function selectAllResidents() { document.querySelectorAll('.resident-checkbox').forEach(cb=>{ if(cb.closest('.db-resident-item').style.display!=='none')cb.checked=true; }); updateSelectedCount(); }
function deselectAllResidents() { document.querySelectorAll('.resident-checkbox').forEach(cb=>cb.checked=false); updateSelectedCount(); }
function updateSelectedCount() { document.getElementById('selectedCount').textContent = document.querySelectorAll('.resident-checkbox:checked').length; }

function showSendingModal(recipientCount) {
    sendingModalInstance = new bootstrap.Modal(document.getElementById('sendingModal'));
    document.getElementById('sendingState').classList.remove('d-none');
    document.getElementById('successState').classList.add('d-none');
    document.getElementById('errorState').classList.add('d-none');
    document.getElementById('totalCount').textContent = recipientCount;
    document.getElementById('sentCount').textContent = 0;
    document.getElementById('failedCount').textContent = 0;
    document.getElementById('emailProgress').style.width = '0%';
    document.getElementById('progressText').textContent = '0%';
    sendingModalInstance.show();
    let progress = 0;
    const interval = setInterval(() => {
        progress += Math.random() * 15;
        if (progress > 90) progress = 90;
        document.getElementById('emailProgress').style.width = progress + '%';
        document.getElementById('progressText').textContent = Math.round(progress) + '%';
        document.getElementById('sentCount').textContent = Math.floor((progress / 100) * recipientCount);
        if (progress >= 90) clearInterval(interval);
    }, 500);
}

function doSendEmails(formData, recipientCount) {
    showSendingModal(recipientCount);
    const btn = document.getElementById('sendEmailBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending…';
    fetch('send-email-ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            document.getElementById('sendingState').classList.add('d-none');
            if (data.success) {
                document.getElementById('successState').classList.remove('d-none');
                document.getElementById('successSentCount').textContent  = data.sent   || 0;
                document.getElementById('successTotalCount').textContent = data.total  || 0;
                document.getElementById('successFailedCount').textContent= data.failed || 0;
                document.getElementById('successMessage').textContent = data.failed > 0
                    ? `Successfully sent ${data.sent} email(s). ${data.failed} failed to send.`
                    : `All ${data.sent} notification(s) have been sent successfully!`;
            } else {
                document.getElementById('errorState').classList.remove('d-none');
                document.getElementById('errorMessage').textContent     = data.message || 'Unknown error occurred';
                document.getElementById('errorDetailsText').textContent = data.message || 'Unknown error occurred';
                document.getElementById('errorSentCount').textContent   = data.sent   || 0;
                document.getElementById('errorFailedCount').textContent = data.failed || data.total || 0;
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i>Send Emails';
        })
        .catch(error => {
            document.getElementById('sendingState').classList.add('d-none');
            document.getElementById('errorState').classList.remove('d-none');
            document.getElementById('errorMessage').textContent     = 'Network error occurred. Please try again.';
            document.getElementById('errorDetailsText').textContent = error.message || 'Unknown network error';
            document.getElementById('errorSentCount').textContent   = 0;
            document.getElementById('errorFailedCount').textContent = recipientCount;
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i>Send Emails';
        });
}

function retryEmailSending() { location.reload(); }

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('emailForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const recipientType = document.getElementById('recipientType').value;
        if (!recipientType) { showValidationModal('Please select a recipient type before sending.', 'Select Recipients'); return; }
        let recipientCount = 0;
        if (recipientType === 'all') {
            recipientCount = <?= $stats['with_email'] ?>;
        } else if (recipientType === 'selected') {
            recipientCount = document.querySelectorAll('.resident-checkbox:checked').length;
            if (recipientCount === 0) { showValidationModal('Please select at least one resident before sending.', 'No Residents Selected'); return; }
        }
        const formData = new FormData(this);
        showConfirmModal(recipientCount, function () { doSendEmails(formData, recipientCount); });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>