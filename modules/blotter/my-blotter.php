<?php
/**
 * Resident Blotter Page
 * Path: modules/blotter/my-blotter.php
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role   = getCurrentUserRole();
$resident_id = getCurrentResidentId();
$user_id     = getCurrentUserId();

if ($user_role !== 'Resident') {
    header('Location: ../dashboard/index.php'); exit();
}

$verify_stmt = $conn->prepare("SELECT is_verified, id_photo FROM tbl_residents WHERE resident_id = ?");
$verify_stmt->bind_param("i", $resident_id);
$verify_stmt->execute();
$verify_data = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$verify_data || $verify_data['is_verified'] != 1 || empty($verify_data['id_photo'])) {
    header('Location: not-verified-blotter.php'); exit();
}

$page_title = 'My Blotter Records';

$sql = "SELECT b.*,
        CONCAT(c.first_name,' ',c.last_name) as complainant_name,
        CONCAT(r.first_name,' ',COALESCE(r.last_name,'')) as respondent_name,
        b.respondent_name as respondent_manual_name
        FROM tbl_blotter b
        LEFT JOIN tbl_residents c ON b.complainant_id = c.resident_id
        LEFT JOIN tbl_residents r ON b.respondent_id  = r.resident_id
        WHERE b.complainant_id = ? OR b.respondent_id = ?
        ORDER BY b.incident_date DESC, b.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $resident_id, $resident_id);
$stmt->execute();
$blotter_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Build stats
$total_records  = count($blotter_records);
$as_complainant = 0; $as_respondent = 0;
$pending_count  = 0; $resolved_count = 0;
foreach ($blotter_records as $r) {
    if ($r['complainant_id'] == $resident_id) $as_complainant++;
    if ($r['respondent_id']  == $resident_id) $as_respondent++;
    if ($r['status'] === 'Pending')  $pending_count++;
    if ($r['status'] === 'Resolved') $resolved_count++;
}

function getBlotterStatusBadge($status) {
    $s = trim($status);
    $map = [
        'Pending'             => ['amber',   'clock'],
        'Under Investigation' => ['sky',     'search'],
        'Resolved'            => ['success', 'check-circle'],
        'Closed'              => ['muted',   'times-circle'],
    ];
    [$color, $icon] = $map[$s] ?? ['muted', 'circle'];
    return "<span class='db-badge db-badge--$color'><i class='fas fa-$icon'></i> ".htmlspecialchars($s)."</span>";
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a3a4a 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(14,165,233,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(16,185,129,.14);}
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

/* ── Stat Cards ── */
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--navy{background:linear-gradient(90deg,var(--db-navy),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--navy{background:#e8edf7;color:var(--db-navy);}

/* ── Table ── */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;cursor:pointer;}
.db-table tbody td{padding:12px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-sky);font-weight:600;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}

/* ── Role chip ── */
.db-role--complainant{background:var(--db-sky-light);color:#0369a1;border:1px solid #bae6fd;}
.db-role--respondent{background:var(--db-rose-light);color:#9f1239;border:1px solid #fecdd3;}

/* ── Empty ── */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-clipboard-list"></i></div>
            <div>
                <div class="rm-hero__title">My Blotter Records</div>
                <div class="rm-hero__sub">View all blotter cases where you are involved</div>
            </div>
        </div>
        <a href="file-blotter.php" class="db-btn db-btn--primary"><i class="fas fa-plus"></i> File a Blotter</a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['success_message']); endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['error_message']); endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--navy"><i class="fas fa-clipboard-list"></i></div>
        <div><div class="db-stat-card__num"><?= $total_records ?></div><div class="db-stat-card__label">Total Cases</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--navy"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-user"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?= $as_complainant ?></div><div class="db-stat-card__label">As Complainant</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-user-shield"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?= $as_respondent ?></div><div class="db-stat-card__label">As Respondent</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?= $pending_count ?></div><div class="db-stat-card__label">Pending</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?= $resolved_count ?></div><div class="db-stat-card__label">Resolved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
</div>

<!-- Table Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-list"></i></div>
            <h2>All Blotter Cases</h2>
            <span class="db-badge db-badge--sky"><?= $total_records ?></span>
        </div>
        <div style="display:flex;gap:8px;">
            <span class="db-badge db-badge--sky"><i class="fas fa-user"></i> Complainant</span>
            <span class="db-badge db-badge--rose"><i class="fas fa-user-shield"></i> Respondent</span>
        </div>
    </div>

    <?php if (empty($blotter_records)): ?>
    <div class="db-empty">
        <i class="fas fa-clipboard-list"></i>
        <p>No blotter records found</p>
        <span style="font-size:12px;color:var(--db-muted)">You don't have any blotter cases yet.</span>
        <a href="file-blotter.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> File Your First Blotter</a>
    </div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Case No.</th>
                    <th>Role</th>
                    <th>Incident Date</th>
                    <th>Type</th>
                    <th>Other Party</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th style="text-align:center">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($blotter_records as $record):
                $is_complainant  = ($record['complainant_id'] == $resident_id);
                $other_party     = $is_complainant
                    ? ($record['respondent_name'] ?: $record['respondent_manual_name'] ?: 'N/A')
                    : $record['complainant_name'];
                $case_number     = htmlspecialchars($record['case_number'] ?? '#' . str_pad($record['blotter_id'], 5, '0', STR_PAD_LEFT));
                $view_url        = 'view-blotter.php?id=' . intval($record['blotter_id']);
                $incident_type   = htmlspecialchars($record['incident_type']);
                $type_icons = [
                    'Physical Assault'  => 'fa-fist-raised',
                    'Noise Complaint'   => 'fa-volume-up',
                    'Verbal Abuse'      => 'fa-comment-slash',
                    'Theft'             => 'fa-user-secret',
                    'Property Damage'   => 'fa-house-damage',
                    'Boundary Dispute'  => 'fa-border-all',
                    'Domestic Violence' => 'fa-home',
                    'Harassment'        => 'fa-exclamation-circle',
                ];
                $t_icon = $type_icons[$record['incident_type']] ?? 'fa-gavel';
            ?>
            <tr onclick="location.href='<?= $view_url ?>'">
                <td><span class="db-id"><?= $case_number ?></span></td>
                <td>
                    <?php if ($is_complainant): ?>
                        <span class="db-badge db-role--complainant"><i class="fas fa-user"></i> Complainant</span>
                    <?php else: ?>
                        <span class="db-badge db-role--respondent"><i class="fas fa-user-shield"></i> Respondent</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="db-text-sm"><?= date('M d, Y', strtotime($record['incident_date'])) ?></span>
                </td>
                <td>
                    <span class="db-badge db-badge--amber">
                        <i class="fas <?= $t_icon ?>"></i> <?= $incident_type ?>
                    </span>
                </td>
                <td><span class="db-text-sm"><?= htmlspecialchars($other_party) ?></span></td>
                <td>
                    <span class="db-text-sm"><?= htmlspecialchars(mb_strimwidth($record['description'], 0, 55, '…')) ?></span>
                </td>
                <td><?= getBlotterStatusBadge($record['status']) ?></td>
                <td style="text-align:center">
                    <a href="<?= $view_url ?>" class="db-btn db-btn--sm db-btn--primary" onclick="event.stopPropagation()">
                        <i class="fas fa-eye"></i> View
                    </a>
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
setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => { a.style.opacity='0'; setTimeout(()=>a.remove(),400); }), 5000);
</script>
<?php include '../../includes/footer.php'; ?>