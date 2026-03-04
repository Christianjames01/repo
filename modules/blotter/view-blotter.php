<?php
/**
 * View Blotter Record Page
 * Path: modules/blotter/view-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

$current_user_id = getCurrentUserId();
$resident_id = getCurrentResidentId();

if ($user_role === 'Resident') {
    $res_stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $res_stmt->bind_param("i", $current_user_id);
    $res_stmt->execute();
    $res_row = $res_stmt->get_result()->fetch_assoc();
    $res_stmt->close();
    if (!empty($res_row['resident_id'])) {
        $resident_id = $res_row['resident_id'];
    }
}

$page_title = 'View Blotter Record';
$error_message = '';

$blotter_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($blotter_id <= 0) {
    $_SESSION['error_message'] = 'Invalid blotter record ID.';
    header($user_role === 'Resident' ? 'Location: my-blotter.php' : 'Location: manage-blotter.php');
    exit();
}

$sql = "SELECT b.*, 
        CONCAT(c.first_name, ' ', c.last_name) as complainant_name,
        c.contact_number as complainant_contact,
        c.address as complainant_address,
        CONCAT(r.first_name, ' ', COALESCE(r.last_name, '')) as respondent_resident_name,
        r.contact_number as respondent_contact,
        r.address as respondent_address,
        b.respondent_name as respondent_manual_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents c ON b.complainant_id = c.resident_id
        LEFT JOIN tbl_residents r ON b.respondent_id = r.resident_id
        WHERE b.blotter_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $blotter_id);
$stmt->execute();
$blotter = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$blotter) {
    $_SESSION['error_message'] = 'Blotter record not found.';
    header($user_role === 'Resident' ? 'Location: my-blotter.php' : 'Location: manage-blotter.php');
    exit();
}

$respondent_display_name = !empty($blotter['respondent_resident_name'])
    ? trim($blotter['respondent_resident_name'])
    : ($blotter['respondent_manual_name'] ?? 'N/A');

if ($user_role === 'Resident') {
    $verify_stmt = $conn->prepare("SELECT is_verified FROM tbl_residents WHERE resident_id = ?");
    $verify_stmt->bind_param("i", $resident_id);
    $verify_stmt->execute();
    $verify_data = $verify_stmt->get_result()->fetch_assoc();
    $verify_stmt->close();

    if (!$verify_data || $verify_data['is_verified'] != 1) {
        header('Location: not-verified-blotter.php'); exit();
    }
    if ($blotter['complainant_id'] != $resident_id && $blotter['respondent_id'] != $resident_id) {
        $_SESSION['error_message'] = 'You do not have permission to view this blotter record.';
        header('Location: my-blotter.php'); exit();
    }
}

function getBlotterStatusBadge($status) {
    $s = trim($status);
    $map = [
        'Pending'              => ['amber',   'clock'],
        'Under Investigation'  => ['sky',     'search'],
        'Resolved'             => ['success', 'check-circle'],
        'Closed'               => ['muted',   'times-circle'],
    ];
    [$color, $icon] = $map[$s] ?? ['muted', 'circle'];
    return "<span class='db-badge db-badge--$color'><i class='fas fa-$icon'></i> " . htmlspecialchars($s) . "</span>";
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-info:#3b82f6;--db-info-light:#dbeafe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a3a4a 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(14,165,233,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* ── Panels ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:14px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-panel__body{padding:22px;}

/* ── Field Groups ── */
.db-field-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);margin-bottom:5px;}
.db-field-value{font-size:13.5px;font-weight:600;color:var(--db-text);}
.db-field-value--muted{font-weight:400;color:var(--db-muted);}
.db-field-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;}
.db-field-row--3{grid-template-columns:1fr 1fr 1fr;}
.db-prose-box{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:14px 16px;font-size:13px;line-height:1.7;color:var(--db-text);}

/* ── Party Cards ── */
.db-party-card{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:16px;height:100%;}
.db-party-card--complainant{border-top:3px solid var(--db-sky);}
.db-party-card--respondent{border-top:3px solid var(--db-rose);}
.db-party-card__role{font-family:'DM Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;}
.db-party-card__role--complainant{color:var(--db-sky);}
.db-party-card__role--respondent{color:var(--db-rose);}
.db-party-card__name{font-size:15px;font-weight:700;margin-bottom:10px;}
.db-party-card__row{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--db-muted);margin-bottom:6px;}
.db-party-card__row i{width:14px;flex-shrink:0;}

/* ── Timeline ── */
.db-timeline{display:flex;flex-direction:column;gap:0;}
.db-timeline__item{display:flex;gap:14px;padding-bottom:18px;position:relative;}
.db-timeline__item:not(:last-child)::before{content:'';position:absolute;left:16px;top:32px;bottom:0;width:2px;background:var(--db-border);}
.db-timeline__dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.db-timeline__dot--success{background:var(--db-success-light);color:var(--db-success);}
.db-timeline__dot--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-timeline__content{flex:1;}
.db-timeline__label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:2px;}
.db-timeline__date{font-size:13px;font-weight:700;}
.db-timeline__time{font-size:11px;color:var(--db-muted);}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--warning{background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;}
.db-btn--warning:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--navy{background:#e8edf7;color:var(--db-navy);}

/* ── Privacy Notice ── */
.db-privacy{display:flex;align-items:center;gap:8px;background:var(--db-amber-light);border:1px solid #fde68a;border-radius:var(--db-radius-sm);padding:10px 14px;font-size:12px;color:var(--db-amber-dark);margin-top:10px;}

/* ── Status Modal ── */
.db-modal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(13,27,54,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;}
.db-modal.open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);box-shadow:var(--db-shadow-lg);width:100%;max-width:480px;overflow:hidden;animation:dbFadeUp .2s ease;}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header h3{font-size:15px;font-weight:700;color:#fff;margin:0;}
.db-modal__close{background:none;border:none;color:rgba(255,255,255,.7);font-size:20px;cursor:pointer;line-height:1;padding:0;}
.db-modal__body{padding:22px;}
.db-modal__footer{padding:16px 22px;border-top:1px solid var(--db-border);display:flex;justify-content:flex-end;gap:10px;}
.db-form-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);display:block;margin-bottom:6px;}
.db-form-control{width:100%;border:2px solid var(--db-border);border-radius:var(--db-radius-sm);padding:9px 12px;font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);transition:border-color .18s;}
.db-form-control:focus{outline:none;border-color:var(--db-sky);}

/* ── Case # chip ── */
.db-case-chip{font-family:'DM Mono',monospace;font-size:12px;color:var(--db-sky);font-weight:600;background:var(--db-sky-light);padding:3px 10px;border-radius:20px;}

/* ── Role Chip ── */
.db-role-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600;}
.db-role-chip--complainant{background:var(--db-sky-light);color:#0369a1;}
.db-role-chip--respondent{background:var(--db-rose-light);color:#9f1239;}
.db-role-chip--admin{background:var(--db-indigo-light);color:#4338ca;}

@media(max-width:768px){
    .rm-hero{padding:20px;border-radius:0;}
    .db-field-row{grid-template-columns:1fr;}
    .db-field-row--3{grid-template-columns:1fr 1fr;}
}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">Blotter Record Details</div>
                <div class="rm-hero__sub">
                    Case: <span style="color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;">
                        <?= htmlspecialchars($blotter['case_number'] ?? '#' . str_pad($blotter['blotter_id'], 5, '0', STR_PAD_LEFT)) ?>
                    </span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ($user_role !== 'Resident'): ?>
            <a href="edit-blotter.php?id=<?= $blotter_id ?>" class="db-btn db-btn--warning">
                <i class="fas fa-edit"></i> Edit
            </a>
            <?php endif; ?>
            <a href="<?= $user_role === 'Resident' ? 'my-blotter.php' : 'manage-blotter.php' ?>" class="db-btn db-btn--ghost">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['error_message']); endif; ?>
<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['success_message']); endif; ?>

<div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">

    <!-- Left Column -->
    <div>
        <!-- Incident Information -->
        <div class="db-panel" style="animation-delay:.05s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-info-circle"></i></div>
                    <h2>Incident Information</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-field-row">
                    <div>
                        <div class="db-field-label">Incident Date</div>
                        <div class="db-field-value"><i class="fas fa-calendar-alt" style="color:var(--db-sky);margin-right:6px;font-size:11px;"></i><?= date('F d, Y', strtotime($blotter['incident_date'])) ?></div>
                    </div>
                    <div>
                        <div class="db-field-label">Incident Time</div>
                        <div class="db-field-value"><i class="fas fa-clock" style="color:var(--db-sky);margin-right:6px;font-size:11px;"></i><?= !empty($blotter['incident_time']) ? date('h:i A', strtotime($blotter['incident_time'])) : '<span class="db-field-value--muted">Not specified</span>' ?></div>
                    </div>
                </div>
                <div class="db-field-row">
                    <div>
                        <div class="db-field-label">Incident Type</div>
                        <span class="db-badge db-badge--amber"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($blotter['incident_type']) ?></span>
                    </div>
                    <div>
                        <div class="db-field-label">Location</div>
                        <div class="db-field-value"><i class="fas fa-map-marker-alt" style="color:var(--db-rose);margin-right:6px;font-size:11px;"></i><?= htmlspecialchars($blotter['location']) ?></div>
                    </div>
                </div>
                <div style="margin-bottom:18px;">
                    <div class="db-field-label">Description</div>
                    <div class="db-prose-box"><?= nl2br(htmlspecialchars($blotter['description'])) ?></div>
                </div>
                <?php if (!empty($blotter['remarks'])): ?>
                <div>
                    <div class="db-field-label">Remarks / Additional Notes</div>
                    <div class="db-prose-box"><?= nl2br(htmlspecialchars($blotter['remarks'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Parties Involved -->
        <div class="db-panel" style="animation-delay:.1s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-users"></i></div>
                    <h2>Parties Involved</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <!-- Complainant -->
                    <div class="db-party-card db-party-card--complainant">
                        <div class="db-party-card__role db-party-card__role--complainant">
                            <i class="fas fa-user me-1"></i>Complainant
                            <?php if ($user_role === 'Resident' && $resident_id == $blotter['complainant_id']): ?>
                                <span class="db-badge db-badge--sky" style="margin-left:6px;">You</span>
                            <?php endif; ?>
                        </div>
                        <div class="db-party-card__name"><?= htmlspecialchars($blotter['complainant_name'] ?? 'N/A') ?></div>
                        <?php if ($user_role !== 'Resident' || $resident_id == $blotter['complainant_id']): ?>
                            <div class="db-party-card__row"><i class="fas fa-phone"></i><?= htmlspecialchars($blotter['complainant_contact'] ?? 'N/A') ?></div>
                            <div class="db-party-card__row"><i class="fas fa-home"></i><?= htmlspecialchars($blotter['complainant_address'] ?? 'N/A') ?></div>
                        <?php else: ?>
                            <div class="db-privacy"><i class="fas fa-lock"></i> Contact details hidden for privacy</div>
                        <?php endif; ?>
                    </div>
                    <!-- Respondent -->
                    <div class="db-party-card db-party-card--respondent">
                        <div class="db-party-card__role db-party-card__role--respondent">
                            <i class="fas fa-user-shield me-1"></i>Respondent
                            <?php if ($user_role === 'Resident' && $resident_id == $blotter['respondent_id']): ?>
                                <span class="db-badge db-badge--rose" style="margin-left:6px;">You</span>
                            <?php endif; ?>
                        </div>
                        <div class="db-party-card__name"><?= htmlspecialchars($respondent_display_name) ?></div>
                        <?php if ($user_role !== 'Resident' || $resident_id == $blotter['respondent_id']): ?>
                            <?php if (!empty($blotter['respondent_contact'])): ?>
                                <div class="db-party-card__row"><i class="fas fa-phone"></i><?= htmlspecialchars($blotter['respondent_contact']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($blotter['respondent_address'])): ?>
                                <div class="db-party-card__row"><i class="fas fa-home"></i><?= htmlspecialchars($blotter['respondent_address']) ?></div>
                            <?php endif; ?>
                            <?php if (empty($blotter['respondent_contact']) && empty($blotter['respondent_address'])): ?>
                                <div class="db-field-value--muted" style="font-size:12px;margin-top:6px;">No additional contact details on file</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="db-privacy"><i class="fas fa-lock"></i> Contact details hidden for privacy</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Sidebar -->
    <div>
        <!-- Case Status -->
        <div class="db-panel" style="animation-delay:.08s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--success"><i class="fas fa-flag"></i></div>
                    <h2>Case Status</h2>
                </div>
            </div>
            <div class="db-panel__body" style="text-align:center;">
                <div style="margin-bottom:14px;"><?= getBlotterStatusBadge($blotter['status']) ?></div>
                <?php if ($user_role !== 'Resident'): ?>
                <button class="db-btn db-btn--primary db-btn--sm" onclick="document.getElementById('statusModal').classList.add('open')">
                    <i class="fas fa-sync-alt"></i> Update Status
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Case Details -->
        <div class="db-panel" style="animation-delay:.12s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--muted"><i class="fas fa-clipboard-list"></i></div>
                    <h2>Case Details</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div style="margin-bottom:16px;">
                    <div class="db-field-label">Case Number</div>
                    <span class="db-case-chip"><?= htmlspecialchars($blotter['case_number'] ?? '#' . str_pad($blotter['blotter_id'], 5, '0', STR_PAD_LEFT)) ?></span>
                </div>
                <div>
                    <div class="db-field-label">Your Role</div>
                    <?php if ($user_role === 'Resident'): ?>
                        <?php if ($resident_id == $blotter['complainant_id']): ?>
                            <span class="db-role-chip db-role-chip--complainant"><i class="fas fa-user"></i> Complainant</span>
                        <?php elseif ($resident_id == $blotter['respondent_id']): ?>
                            <span class="db-role-chip db-role-chip--respondent"><i class="fas fa-user-shield"></i> Respondent</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="db-role-chip db-role-chip--admin"><i class="fas fa-user-tie"></i> Administrator</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Timeline -->
        <div class="db-panel" style="animation-delay:.16s">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--muted"><i class="fas fa-history"></i></div>
                    <h2>Timeline</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-timeline">
                    <div class="db-timeline__item">
                        <div class="db-timeline__dot db-timeline__dot--success"><i class="fas fa-calendar-plus"></i></div>
                        <div class="db-timeline__content">
                            <div class="db-timeline__label">Date Filed</div>
                            <div class="db-timeline__date"><?= date('M d, Y', strtotime($blotter['created_at'])) ?></div>
                            <div class="db-timeline__time"><?= date('h:i A', strtotime($blotter['created_at'])) ?></div>
                        </div>
                    </div>
                    <div class="db-timeline__item">
                        <div class="db-timeline__dot db-timeline__dot--sky"><i class="fas fa-sync-alt"></i></div>
                        <div class="db-timeline__content">
                            <div class="db-timeline__label">Last Updated</div>
                            <div class="db-timeline__date"><?= date('M d, Y', strtotime($blotter['updated_at'])) ?></div>
                            <div class="db-timeline__time"><?= date('h:i A', strtotime($blotter['updated_at'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?php if ($user_role !== 'Resident'): ?>
<!-- Status Modal -->
<div class="db-modal" id="statusModal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-sync-alt" style="margin-right:8px;"></i>Update Case Status</h3>
            <button class="db-modal__close" onclick="document.getElementById('statusModal').classList.remove('open')">×</button>
        </div>
        <form method="POST" action="manage-blotter.php">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="blotter_id" value="<?= $blotter_id ?>">
                <div style="background:var(--db-sky-light);border:1px solid #bae6fd;border-radius:var(--db-radius-sm);padding:12px 14px;margin-bottom:18px;font-size:13px;color:#0369a1;">
                    <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                    <strong>Current Status:</strong> <?= htmlspecialchars($blotter['status']) ?>
                </div>
                <div style="margin-bottom:16px;">
                    <label class="db-form-label">New Status <span style="color:var(--db-rose);">*</span></label>
                    <select name="status" class="db-form-control" required>
                        <option value="">— Select Status —</option>
                        <option value="Pending"             <?= $blotter['status']==='Pending'?'selected':'' ?>>Pending</option>
                        <option value="Under Investigation" <?= $blotter['status']==='Under Investigation'?'selected':'' ?>>Under Investigation</option>
                        <option value="Resolved"            <?= $blotter['status']==='Resolved'?'selected':'' ?>>Resolved</option>
                        <option value="Closed"              <?= $blotter['status']==='Closed'?'selected':'' ?>>Closed</option>
                    </select>
                </div>
                <div>
                    <label class="db-form-label">Remarks (Optional)</label>
                    <textarea name="status_remarks" class="db-form-control" rows="3" placeholder="Add any notes about this status update…"></textarea>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="document.getElementById('statusModal').classList.remove('open')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="db-btn db-btn--primary">
                    <i class="fas fa-check"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('statusModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>
<?php endif; ?>

<script>
setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => { a.style.opacity='0'; setTimeout(()=>a.remove(),400); }), 5000);
</script>
<?php include '../../includes/footer.php'; ?>