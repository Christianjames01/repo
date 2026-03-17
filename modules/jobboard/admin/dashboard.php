<?php
require_once '../../../config/config.php';
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../config/helpers.php';

// Check if user is logged in and has admin privileges
if (!isLoggedIn() || !in_array($_SESSION['role'], ['Super Admin', 'Admin', 'Staff'])) {
    header('Location: ../../../modules/auth/login.php');
    exit();
}

$page_title = 'Job Board Dashboard';

// Get statistics
$stats = [];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_jobs WHERE status = 'active'");
$stmt->execute();
$stats['total_jobs'] = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_job_applications");
$stmt->execute();
$stats['total_applications'] = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_job_applications WHERE status = 'pending'");
$stmt->execute();
$stats['pending_applications'] = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_trainings WHERE status = 'active'");
$stmt->execute();
$stats['active_trainings'] = $stmt->get_result()->fetch_assoc()['total'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_livelihood_programs WHERE status = 'active'");
$stmt->execute();
$stats['active_programs'] = $stmt->get_result()->fetch_assoc()['total'];

// Recent applications
$recent_applications = [];
try {
    $stmt = $conn->prepare("
        SELECT
            ja.application_id,
            ja.application_date,
            ja.status,
            j.job_title,
            COALESCE(r.first_name, u.username) as first_name,
            COALESCE(r.last_name, '') as last_name
        FROM tbl_job_applications ja
        JOIN tbl_jobs j ON ja.job_id = j.job_id
        LEFT JOIN tbl_users u ON ja.user_id = u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        ORDER BY ja.application_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recent_applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    try {
        $stmt = $conn->prepare("
            SELECT
                ja.application_id,
                ja.application_date,
                ja.status,
                j.job_title,
                ja.applicant_name as first_name,
                '' as last_name
            FROM tbl_job_applications ja
            JOIN tbl_jobs j ON ja.job_id = j.job_id
            ORDER BY ja.application_date DESC
            LIMIT 10
        ");
        $stmt->execute();
        $recent_applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e2) {
        error_log("Job applications query failed: " . $e2->getMessage());
    }
}

// Jobs by category
$stmt = $conn->prepare("
    SELECT category, COUNT(*) as count
    FROM tbl_jobs
    WHERE status = 'active'
    GROUP BY category
");
$stmt->execute();
$jobs_by_category = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include '../../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root {
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-info:#3b82f6;--db-info-light:#dbeafe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.jb-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.jb-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.jb-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.jb-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.jb-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.jb-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.jb-hero__left{display:flex;align-items:center;gap:16px;}
.jb-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-teal),#0f766e);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.jb-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.jb-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.jb-hero__actions{display:flex;gap:10px;flex-wrap:wrap;}

/* ── Stats Row ── */
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 130px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__body{padding:20px 22px;}

/* ── Table ── */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr.app-row:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-teal);cursor:pointer;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));color:#fff;}
.db-btn--teal:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.3);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}
.db-btn--sky{background:linear-gradient(135deg,#0369a1,var(--db-sky));color:#fff;}
.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.35);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

/* ── Empty State ── */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* ── Category progress ── */
.db-progress-row{margin-bottom:14px;}
.db-progress-row:last-child{margin-bottom:0;}
.db-progress-meta{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;}
.db-progress-label{font-size:12.5px;font-weight:600;}
.db-progress-count{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);}
.db-progress-track{height:8px;border-radius:4px;background:var(--db-surf2);border:1px solid var(--db-border);overflow:hidden;}
.db-progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--db-teal),var(--db-sky));transition:width .6s cubic-bezier(.4,0,.2,1);}

/* ── Quick Actions grid ── */
.jb-actions-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;}
.jb-action-card{display:flex;flex-direction:column;align-items:center;gap:10px;padding:22px 16px;border-radius:var(--db-radius);border:1.5px solid var(--db-border);background:var(--db-surf2);text-decoration:none;color:var(--db-text);transition:all .2s;text-align:center;}
.jb-action-card:hover{transform:translateY(-4px);box-shadow:var(--db-shadow-lg);color:var(--db-text);}
.jb-action-card__icon{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:20px;}
.jb-action-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.jb-action-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.jb-action-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.jb-action-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.jb-action-card__label{font-size:13px;font-weight:700;}
.jb-action-card__desc{font-size:11px;color:var(--db-muted);}

/* ══════════════════════════
   DARK MODE
══════════════════════════ */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9 !important;}
body.dark-mode .db-panel__icon--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .db-panel__icon--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-panel__icon--indigo{background:#1e1b4b !important;color:#a5b4fc !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-stat-card:hover{box-shadow:var(--db-shadow-lg) !important;}
body.dark-mode .db-stat-card__icon--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .db-stat-card__icon--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-stat-card__icon--sky{background:#0c2a40 !important;color:#38bdf8 !important;}
body.dark-mode .db-stat-card__icon--success{background:#052e16 !important;color:#4ade80 !important;}
body.dark-mode .db-stat-card__icon--indigo{background:#1e1b4b !important;color:#a5b4fc !important;}
body.dark-mode .db-stat-card__label{color:#64748b !important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b) !important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9) !important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155 !important;}
body.dark-mode .db-table tbody tr.app-row:hover{background:#1e293b !important;box-shadow:inset 3px 0 0 #2dd4bf !important;}
body.dark-mode .db-table tbody td{color:#e2e8f0 !important;}
body.dark-mode .db-text-sm{color:#94a3b8 !important;}
body.dark-mode .db-id{color:#a5b4fc !important;}
body.dark-mode .db-badge--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .db-badge--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-badge--sky{background:#0c2a40 !important;color:#38bdf8 !important;}
body.dark-mode .db-badge--success{background:#052e16 !important;color:#4ade80 !important;}
body.dark-mode .db-badge--rose{background:#2d1c1c !important;color:#fb7185 !important;}
body.dark-mode .db-badge--indigo{background:#1e1b4b !important;color:#a5b4fc !important;}
body.dark-mode .db-badge--muted{background:#1e293b !important;color:#94a3b8 !important;border-color:#475569 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-btn--ghost:hover{background:#334155 !important;}
body.dark-mode .db-empty i{color:#334155 !important;}
body.dark-mode .db-empty p{color:#64748b !important;}
body.dark-mode .db-progress-track{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-progress-label{color:#e2e8f0 !important;}
body.dark-mode .jb-action-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .jb-action-card:hover{background:#273549 !important;color:#f1f5f9 !important;}
body.dark-mode .jb-action-card__icon--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
body.dark-mode .jb-action-card__icon--sky{background:#0c2a40 !important;color:#38bdf8 !important;}
body.dark-mode .jb-action-card__icon--success{background:#052e16 !important;color:#4ade80 !important;}
body.dark-mode .jb-action-card__icon--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .jb-action-card__desc{color:#64748b !important;}
</style>

<!-- Hero Banner -->
<div class="jb-hero">
    <div class="jb-hero__ring jb-hero__ring--1"></div>
    <div class="jb-hero__ring jb-hero__ring--2"></div>
    <div class="jb-hero__ring jb-hero__ring--3"></div>
    <div class="jb-hero__inner">
        <div class="jb-hero__left">
            <div class="jb-hero__icon"><i class="fas fa-briefcase"></i></div>
            <div>
                <div class="jb-hero__title">Job Board Dashboard</div>
                <div class="jb-hero__sub">Manage jobs, applications, trainings &amp; livelihood programs</div>
            </div>
        </div>
        <div class="jb-hero__actions">
            <a href="manage-jobs.php?action=add" class="db-btn db-btn--teal">
                <i class="fas fa-plus-circle"></i> Post Job
            </a>
            <a href="applications.php" class="db-btn db-btn--ghost" style="color:#fff;border-color:rgba(255,255,255,.25);">
                <i class="fas fa-clipboard-list"></i> Applications
            </a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

    <!-- Stats -->
    <div class="db-stats-row">
        <a href="manage-jobs.php" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-briefcase"></i></div>
            <div>
                <div class="db-stat-card__num"><?php echo $stats['total_jobs']; ?></div>
                <div class="db-stat-card__label">Active Jobs</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
        </a>
        <a href="applications.php" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['total_applications']; ?></div>
                <div class="db-stat-card__label">Total Applications</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
        </a>
        <a href="applications.php?status=pending" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['pending_applications']; ?></div>
                <div class="db-stat-card__label">Pending</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
        </a>
        <a href="trainings.php" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-chalkboard-teacher"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['active_trainings']; ?></div>
                <div class="db-stat-card__label">Active Trainings</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--success"></div>
        </a>
        <a href="livelihood.php" class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-hands-helping"></i></div>
            <div>
                <div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $stats['active_programs']; ?></div>
                <div class="db-stat-card__label">Livelihood Programs</div>
            </div>
            <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
        </a>
    </div>

    <!-- Main content row -->
    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;">

        <!-- Recent Applications -->
        <div style="flex:2;min-width:300px;">
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list"></i></div>
                        <h2>Recent Applications</h2>
                        <span class="db-badge db-badge--teal"><?php echo count($recent_applications); ?></span>
                    </div>
                    <a href="applications.php" class="db-btn db-btn--ghost db-btn--sm">
                        <i class="fas fa-arrow-right"></i> View All
                    </a>
                </div>
                <div class="db-table-wrap">
                    <table class="db-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Applicant</th>
                                <th>Job Title</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_applications)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="db-empty">
                                        <i class="fas fa-inbox"></i>
                                        <p>No applications found</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_applications as $app):
                                $sc = ['pending'=>'amber','approved'=>'success','rejected'=>'rose'];
                                $si = ['pending'=>'clock','approved'=>'check-circle','rejected'=>'times-circle'];
                                $st = strtolower($app['status']);
                                $cls = $sc[$st] ?? 'muted';
                                $ico = $si[$st] ?? 'circle';
                                $name = htmlspecialchars(trim($app['first_name'].' '.$app['last_name']));
                            ?>
                            <tr class="app-row" data-url="applications.php?id=<?php echo $app['application_id']; ?>">
                                <td><span class="db-id">#<?php echo str_pad($app['application_id'],5,'0',STR_PAD_LEFT); ?></span></td>
                                <td><strong><?php echo $name; ?></strong></td>
                                <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($app['job_title']); ?></span></td>
                                <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($app['application_date'])); ?></span></td>
                                <td>
                                    <span class="db-badge db-badge--<?php echo $cls; ?>">
                                        <i class="fas fa-<?php echo $ico; ?>"></i>
                                        <?php echo ucfirst($app['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="applications.php?id=<?php echo $app['application_id']; ?>"
                                       class="db-btn db-btn--primary db-btn--sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Jobs by Category -->
        <div style="flex:1;min-width:220px;">
            <div class="db-panel">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-chart-pie"></i></div>
                        <h2>Jobs by Category</h2>
                    </div>
                </div>
                <div class="db-panel__body">
                    <?php if (empty($jobs_by_category)): ?>
                        <div class="db-empty" style="padding:32px 16px;">
                            <i class="fas fa-briefcase"></i>
                            <p>No active jobs</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($jobs_by_category as $cat):
                            $pct = $stats['total_jobs'] > 0
                                ? round(($cat['count'] / $stats['total_jobs']) * 100)
                                : 0;
                        ?>
                        <div class="db-progress-row">
                            <div class="db-progress-meta">
                                <span class="db-progress-label"><?php echo htmlspecialchars($cat['category'] ?: 'Uncategorized'); ?></span>
                                <span class="db-progress-count"><?php echo $cat['count']; ?> &middot; <?php echo $pct; ?>%</span>
                            </div>
                            <div class="db-progress-track">
                                <div class="db-progress-fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /main row -->

    <!-- Quick Actions -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bolt"></i></div>
                <h2>Quick Actions</h2>
            </div>
        </div>
        <div class="db-panel__body">
            <div class="jb-actions-grid">
                <a href="manage-jobs.php?action=add" class="jb-action-card">
                    <div class="jb-action-card__icon jb-action-card__icon--teal"><i class="fas fa-plus-circle"></i></div>
                    <div class="jb-action-card__label">Post New Job</div>
                    <div class="jb-action-card__desc">Create a new job listing</div>
                </a>
                <a href="applications.php" class="jb-action-card">
                    <div class="jb-action-card__icon jb-action-card__icon--sky"><i class="fas fa-clipboard-list"></i></div>
                    <div class="jb-action-card__label">View Applications</div>
                    <div class="jb-action-card__desc">Review all submitted applications</div>
                </a>
                <a href="trainings.php?action=add" class="jb-action-card">
                    <div class="jb-action-card__icon jb-action-card__icon--success"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="jb-action-card__label">Add Training</div>
                    <div class="jb-action-card__desc">Schedule a new training session</div>
                </a>
                <a href="livelihood.php?action=add" class="jb-action-card">
                    <div class="jb-action-card__icon jb-action-card__icon--amber"><i class="fas fa-hands-helping"></i></div>
                    <div class="jb-action-card__label">Add Program</div>
                    <div class="jb-action-card__desc">Register a livelihood program</div>
                </a>
            </div>
        </div>
    </div>

</div><!-- /padding wrapper -->

<script>
// Clickable rows
document.querySelectorAll('.app-row').forEach(row => {
    row.addEventListener('click', function () {
        if (this.dataset.url) location.href = this.dataset.url;
    });
});

// Animate progress bars on load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.db-progress-fill').forEach(bar => {
        const w = bar.style.width;
        bar.style.width = '0';
        requestAnimationFrame(() => {
            setTimeout(() => bar.style.width = w, 80);
        });
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>