<?php
/**
 * Email History - modules/notifications/email-history.php
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

if ($user_role !== 'Super Administrator') {
    $_SESSION['error_message'] = 'Access denied. Super Administrator only.';
    header('Location: ../../dashboard.php');
    exit();
}

$per_page = 15;
$page     = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset   = ($page - 1) * $per_page;

$count_sql  = "SELECT COUNT(*) as total FROM tbl_email_history";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = (int)($count_result->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_pages = $total_records > 0 ? ceil($total_records / $per_page) : 1;

$history_sql = "SELECT 
                    eh.*,
                    COALESCE(
                        CONCAT(r.first_name, ' ', r.last_name),
                        u.username
                    ) as sender_name
                FROM tbl_email_history eh
                LEFT JOIN tbl_users u ON eh.sender_id = u.user_id
                LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                ORDER BY eh.sent_at DESC
                LIMIT ? OFFSET ?";

$history_stmt = $conn->prepare($history_sql);
$history_stmt->bind_param('ii', $per_page, $offset);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

$email_history = [];
while ($row = $history_result->fetch_assoc()) {
    $email_history[] = $row;
}
$history_stmt->close();

$stats = fetchOne($conn,
    "SELECT 
        COUNT(*) as total_emails,
        SUM(total_recipients) as total_recipients,
        SUM(successful_sends) as total_sent,
        SUM(failed_sends) as total_failed
     FROM tbl_email_history",
    [], ''
);

$page_title = 'Email History';
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

/* Stats */
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 180px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--danger{background:var(--db-danger-light);color:var(--db-danger);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--danger{background:linear-gradient(90deg,var(--db-danger),transparent);}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}

/* Badge */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--danger{background:var(--db-danger-light);color:#9f1239;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}

/* Table */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr.email-row:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-indigo);cursor:pointer;}
.db-table tbody td{padding:12px 16px;vertical-align:middle;}

/* Email icon cell */
.db-email-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.05rem;transition:transform .2s;}
.email-row:hover .db-email-icon{transform:scale(1.1);}

/* Stat mini */
.db-mini-stat{font-family:'DM Mono',monospace;font-size:11px;}

/* Empty state */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Pagination */
.db-pagination{display:flex;justify-content:center;gap:6px;padding:16px;}
.db-page-btn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;border:1.5px solid var(--db-border);background:var(--db-surf);color:var(--db-text);font-family:'DM Mono',monospace;font-size:12px;text-decoration:none;transition:all .15s;font-weight:500;}
.db-page-btn:hover{background:var(--db-indigo-light);border-color:var(--db-indigo);color:var(--db-indigo);}
.db-page-btn.active{background:var(--db-navy);border-color:var(--db-navy);color:#fff;}
.db-page-btn.disabled{opacity:.4;pointer-events:none;}
.db-page-btn--icon{font-size:10px;}

/* Hover preview */
.db-preview{position:fixed;z-index:9999;width:340px;background:var(--db-surf);border-radius:var(--db-radius);box-shadow:var(--db-shadow-lg);border:1px solid var(--db-border);overflow:hidden;pointer-events:none;animation:dbPrevIn .18s ease;}
@keyframes dbPrevIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.db-preview__header{display:flex;align-items:center;gap:12px;padding:14px 16px 10px;border-bottom:1px solid var(--db-border);}
.db-preview__icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.db-preview__header-text{flex:1;min-width:0;}
.db-preview__type{font-family:'DM Mono',monospace;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:2px;}
.db-preview__title{font-size:.88rem;font-weight:700;color:var(--db-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.db-preview__body{padding:12px 16px 14px;}
.db-preview__recipients{font-size:.79rem;color:var(--db-muted);line-height:1.5;margin-bottom:10px;}
.db-preview__stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;background:var(--db-surf2);border-radius:8px;padding:10px;margin-bottom:10px;}
.db-preview__stat-val{font-size:1.15rem;font-weight:800;line-height:1;margin-bottom:2px;}
.db-preview__stat-lbl{font-family:'DM Mono',monospace;font-size:.65rem;text-transform:uppercase;letter-spacing:.4px;color:var(--db-muted);}
.db-preview__footer{font-size:.72rem;color:var(--db-muted);display:flex;justify-content:space-between;border-top:1px solid var(--db-border);padding-top:8px;}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-preview{display:none!important;}}
</style>

<!-- HERO -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-history"></i></div>
            <div>
                <div class="rm-hero__title">Email History</div>
                <div class="rm-hero__sub">View all sent email notifications</div>
            </div>
        </div>
        <div class="rm-hero__actions">
            <a href="email-residents.php" class="db-btn db-btn--primary">
                <i class="fas fa-paper-plane"></i>Send New Email
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
            <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-envelope"></i></div>
            <div>
                <div class="db-stat-card__num"><?= number_format($stats['total_emails'] ?? 0) ?></div>
                <div class="db-stat-card__label">Total Emails Sent</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-users"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-sky)"><?= number_format($stats['total_recipients'] ?? 0) ?></div>
                <div class="db-stat-card__label">Total Recipients</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-success)"><?= number_format($stats['total_sent'] ?? 0) ?></div>
                <div class="db-stat-card__label">Successfully Delivered</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--success"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--danger"><i class="fas fa-times-circle"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-danger)"><?= number_format($stats['total_failed'] ?? 0) ?></div>
                <div class="db-stat-card__label">Failed / No Email</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--danger"></div>
        </div>
    </div>

    <!-- Table Panel -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></div>
                <h2>Email Records</h2>
                <span class="db-badge db-badge--indigo"><?= number_format($total_records) ?></span>
            </div>
            <small style="color:var(--db-muted);font-family:'DM Mono',monospace;font-size:11px;">
                Showing <?= min(($offset + 1), $total_records) ?>–<?= min($offset + $per_page, $total_records) ?> of <?= number_format($total_records) ?>
            </small>
        </div>

        <?php if (empty($email_history)): ?>
            <div class="db-empty">
                <i class="fas fa-inbox"></i>
                <p>No email records found</p>
                <a href="email-residents.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-paper-plane"></i>Send First Email</a>
            </div>
        <?php else: ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th style="width:44px;">#</th>
                            <th style="width:54px;"></th>
                            <th>Subject &amp; Recipients</th>
                            <th style="width:120px;">Type</th>
                            <th style="width:110px;text-align:center;">Recipients</th>
                            <th style="width:110px;">Sent By</th>
                            <th style="width:140px;">Date &amp; Time</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $type_map = [
                        'general'           => ['db-badge--muted',    'General',       'fa-info-circle',         'muted'],
                        'announcement'      => ['db-badge--indigo',   'Announcement',  'fa-bullhorn',            'indigo'],
                        'alert'             => ['db-badge--amber',    'Alert',         'fa-exclamation-triangle','amber'],
                        'incident_reported' => ['db-badge--danger',   'Incident',      'fa-fire',                'danger'],
                        'status_update'     => ['db-badge--sky',      'Status Update', 'fa-sync-alt',            'sky'],
                    ];
                    foreach ($email_history as $index => $record):
                        $info        = $type_map[$record['notification_type']] ?? ['db-badge--muted', ucfirst($record['notification_type']), 'fa-envelope', 'muted'];
                        $badge_cls   = $info[0];
                        $type_label  = $info[1];
                        $icon        = $info[2];
                        $color       = $info[3];

                        $success_rate = $record['total_recipients'] > 0
                            ? round(($record['successful_sends'] / $record['total_recipients']) * 100)
                            : 0;

                        $detail_url   = "email-details.php?id=" . (int)$record['id'];

                        $prev_recipients = htmlspecialchars($record['recipient_details'] ?? '');
                        $prev_time       = htmlspecialchars(date('M j, Y g:i A', strtotime($record['sent_at'])));
                        $prev_sender     = htmlspecialchars($record['sender_name'] ?? 'Unknown');
                    ?>
                    <tr class="email-row"
                        data-url="<?= htmlspecialchars($detail_url) ?>"
                        data-pt="<?= htmlspecialchars($record['email_title']) ?>"
                        data-ptype="<?= $type_label ?>"
                        data-pcolor="<?= $color ?>"
                        data-picon="<?= $icon ?>"
                        data-precipients="<?= $prev_recipients ?>"
                        data-ptime="<?= $prev_time ?>"
                        data-psender="<?= $prev_sender ?>"
                        data-ptotal="<?= number_format($record['total_recipients']) ?>"
                        data-psuccess="<?= number_format($record['successful_sends']) ?>"
                        data-pfailed="<?= number_format($record['failed_sends']) ?>"
                        data-prate="<?= $success_rate ?>%">

                        <td style="font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);"><?= $offset + $index + 1 ?></td>

                        <td>
                            <div class="db-email-icon bg-<?= $color ?>-subtle" style="
                                background:<?= $color==='muted' ? 'var(--db-surf2)' : "rgba(var(--c-{$color}),0.1)" ?>;
                                <?php
                                $bg_map=['indigo'=>'rgba(99,102,241,.12)','sky'=>'rgba(14,165,233,.12)','amber'=>'rgba(245,158,11,.12)','danger'=>'rgba(239,68,68,.12)','success'=>'rgba(16,185,129,.12)','muted'=>'#f1f5f9'];
                                $txt_map=['indigo'=>'var(--db-indigo)','sky'=>'var(--db-sky)','amber'=>'var(--db-amber-dark)','danger'=>'var(--db-danger)','success'=>'var(--db-success)','muted'=>'var(--db-muted)'];
                                echo "background:{$bg_map[$color]};color:{$txt_map[$color]};";
                                ?>">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                        </td>

                        <td>
                            <div style="font-weight:700;color:var(--db-text);margin-bottom:2px;"><?= htmlspecialchars($record['email_title']) ?></div>
                            <div style="font-size:11.5px;color:var(--db-muted);"><?= htmlspecialchars(mb_strimwidth($record['recipient_details'] ?? '', 0, 80, '…')) ?></div>
                        </td>

                        <td><span class="db-badge <?= $badge_cls ?>"><i class="fas <?= $icon ?>"></i> <?= $type_label ?></span></td>

                        <td style="text-align:center;">
                            <div style="font-weight:800;font-size:15px;"><?= number_format($record['total_recipients']) ?></div>
                            <div class="db-mini-stat">
                                <span style="color:var(--db-success)"><?= $record['successful_sends'] ?></span>
                                <span style="color:var(--db-muted);">/</span>
                                <span style="color:var(--db-danger)"><?= $record['failed_sends'] ?></span>
                            </div>
                        </td>

                        <td style="font-size:12px;"><?= htmlspecialchars($record['sender_name'] ?? 'Unknown') ?></td>

                        <td>
                            <div style="font-size:12px;"><i class="far fa-calendar me-1" style="color:var(--db-muted);"></i><?= date('M d, Y', strtotime($record['sent_at'])) ?></div>
                            <div style="font-size:11px;color:var(--db-muted);font-family:'DM Mono',monospace;"><i class="far fa-clock me-1"></i><?= date('h:i A', strtotime($record['sent_at'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="db-pagination">
                <a href="?page=<?= $page - 1 ?>" class="db-page-btn db-page-btn--icon <?= $page <= 1 ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                <a href="?page=<?= $p ?>" class="db-page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a href="?page=<?= $page + 1 ?>" class="db-page-btn db-page-btn--icon <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Hover Preview Card -->
<div id="dbPreview" class="db-preview" style="display:none;">
    <div class="db-preview__header">
        <div class="db-preview__icon" id="dbPrevIcon"><i class="fas fa-envelope" id="dbPrevIconI"></i></div>
        <div class="db-preview__header-text">
            <div class="db-preview__type" id="dbPrevType"></div>
            <div class="db-preview__title" id="dbPrevTitle"></div>
        </div>
    </div>
    <div class="db-preview__body">
        <p class="db-preview__recipients" id="dbPrevRecipients"></p>
        <div class="db-preview__stats">
            <div style="text-align:center;">
                <div class="db-preview__stat-val" style="color:var(--db-indigo);" id="dbPrevTotal">0</div>
                <div class="db-preview__stat-lbl">Total</div>
            </div>
            <div style="text-align:center;">
                <div class="db-preview__stat-val" style="color:var(--db-success);" id="dbPrevSuccess">0</div>
                <div class="db-preview__stat-lbl">Sent</div>
            </div>
            <div style="text-align:center;">
                <div class="db-preview__stat-val" style="color:var(--db-danger);" id="dbPrevFailed">0</div>
                <div class="db-preview__stat-lbl">Failed</div>
            </div>
        </div>
        <div class="db-preview__footer">
            <span><i class="far fa-clock me-1"></i><span id="dbPrevTime"></span></span>
            <span><i class="fas fa-user me-1"></i><span id="dbPrevSender"></span></span>
        </div>
    </div>
</div>

<script>
(function () {
    const card = document.getElementById('dbPreview');
    const iconBox = document.getElementById('dbPrevIcon');
    const iconEl  = document.getElementById('dbPrevIconI');

    const cmap = {
        indigo  : { bg: 'rgba(99,102,241,.12)',  text: 'var(--db-indigo)' },
        sky     : { bg: 'rgba(14,165,233,.12)',  text: 'var(--db-sky)' },
        amber   : { bg: 'rgba(245,158,11,.12)',  text: 'var(--db-amber-dark)' },
        danger  : { bg: 'rgba(239,68,68,.12)',   text: 'var(--db-danger)' },
        success : { bg: 'rgba(16,185,129,.12)',  text: 'var(--db-success)' },
        muted   : { bg: '#f1f5f9',               text: 'var(--db-muted)' },
    };

    let hideTimer;

    function pos(e) {
        const cw = card.offsetWidth  || 340, ch = card.offsetHeight || 200;
        const m  = 14;
        let x = e.clientX + m, y = e.clientY + m;
        if (x + cw > window.innerWidth  - m) x = e.clientX - cw - m;
        if (y + ch > window.innerHeight - m) y = e.clientY - ch - m;
        card.style.left = x + 'px';
        card.style.top  = y + 'px';
    }

    document.querySelectorAll('.email-row').forEach(row => {
        row.addEventListener('mouseenter', function (e) {
            clearTimeout(hideTimer);
            const c = cmap[this.dataset.pcolor] || cmap.muted;
            document.getElementById('dbPrevTitle').textContent      = this.dataset.pt;
            document.getElementById('dbPrevType').textContent       = this.dataset.ptype;
            document.getElementById('dbPrevRecipients').textContent = this.dataset.precipients;
            document.getElementById('dbPrevTime').textContent       = this.dataset.ptime;
            document.getElementById('dbPrevSender').textContent     = this.dataset.psender;
            document.getElementById('dbPrevTotal').textContent      = this.dataset.ptotal;
            document.getElementById('dbPrevSuccess').textContent    = this.dataset.psuccess;
            document.getElementById('dbPrevFailed').textContent     = this.dataset.pfailed;
            iconEl.className        = 'fas ' + this.dataset.picon;
            iconBox.style.background = c.bg;
            iconEl.style.color       = c.text;
            pos(e);
            card.style.display = 'block';
        });
        row.addEventListener('mousemove', pos);
        row.addEventListener('mouseleave', () => {
            hideTimer = setTimeout(() => { if (!card.matches(':hover')) card.style.display = 'none'; }, 150);
        });
        row.addEventListener('click', function () {
            if (this.dataset.url) window.location.href = this.dataset.url;
        });
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>