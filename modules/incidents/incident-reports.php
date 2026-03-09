<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Super Admin', 'Admin', 'Barangay Captain', 'Barangay Tanod']);

$page_title = 'Incident Reports';

$date_from     = isset($_GET['date_from'])     ? $_GET['date_from']                        : date('Y-m-01');
$date_to       = isset($_GET['date_to'])       ? $_GET['date_to']                          : date('Y-m-d');
$incident_type = isset($_GET['incident_type']) ? sanitizeInput($_GET['incident_type'])      : '';
$severity      = isset($_GET['severity'])      ? sanitizeInput($_GET['severity'])           : '';
$active_tab    = isset($_GET['tab'])           ? $_GET['tab']                               : 'overview';

// ── Statistics ───────────────────────────────────────────────────────────────
$stats_sql = "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN severity = 'Critical' THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN severity = 'High'     THEN 1 ELSE 0 END) as high,
    SUM(CASE WHEN severity = 'Medium'   THEN 1 ELSE 0 END) as medium,
    SUM(CASE WHEN severity = 'Low'      THEN 1 ELSE 0 END) as low,
    SUM(CASE WHEN status = 'Resolved'                         THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN status IN ('Reported','Pending')            THEN 1 ELSE 0 END) as reported,
    SUM(CASE WHEN status = 'Under Investigation'              THEN 1 ELSE 0 END) as investigating
    FROM tbl_incidents WHERE DATE(date_reported) BETWEEN ? AND ?";

$s_params = [$date_from, $date_to]; $s_types = 'ss';
if ($incident_type) { $stats_sql .= " AND incident_type = ?"; $s_params[] = $incident_type; $s_types .= 's'; }
if ($severity)      { $stats_sql .= " AND severity = ?";      $s_params[] = $severity;      $s_types .= 's'; }

$stmt = $conn->prepare($stats_sql);
$stmt->bind_param($s_types, ...$s_params);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$resolution_rate = $stats['total'] > 0 ? round(($stats['resolved'] / $stats['total']) * 100, 1) : 0;

// ── Type distribution ────────────────────────────────────────────────────────
$type_sql = "SELECT incident_type, COUNT(*) as count FROM tbl_incidents WHERE DATE(date_reported) BETWEEN ? AND ?";
$t_params = [$date_from, $date_to]; $t_types = 'ss';
if ($severity) { $type_sql .= " AND severity = ?"; $t_params[] = $severity; $t_types .= 's'; }
$type_sql .= " GROUP BY incident_type ORDER BY count DESC";
$stmt = $conn->prepare($type_sql); $stmt->bind_param($t_types, ...$t_params); $stmt->execute();
$type_distribution = $stmt->get_result(); $stmt->close();

// ── Status distribution ──────────────────────────────────────────────────────
$status_sql = "SELECT status, COUNT(*) as count FROM tbl_incidents WHERE DATE(date_reported) BETWEEN ? AND ?";
$st_params = [$date_from, $date_to]; $st_types = 'ss';
if ($incident_type) { $status_sql .= " AND incident_type = ?"; $st_params[] = $incident_type; $st_types .= 's'; }
if ($severity)      { $status_sql .= " AND severity = ?";      $st_params[] = $severity;      $st_types .= 's'; }
$status_sql .= " GROUP BY status ORDER BY count DESC";
$stmt = $conn->prepare($status_sql); $stmt->bind_param($st_types, ...$st_params); $stmt->execute();
$status_distribution = $stmt->get_result(); $stmt->close();

// ── Detailed list ────────────────────────────────────────────────────────────
$detail_sql = "SELECT i.*, CONCAT(r.first_name,' ',r.last_name) as reporter_name,
               u.username as responder_name
               FROM tbl_incidents i
               LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
               LEFT JOIN tbl_users u ON i.responder_id = u.user_id
               WHERE DATE(i.date_reported) BETWEEN ? AND ?";
$d_params = [$date_from, $date_to]; $d_types = 'ss';
if ($incident_type) { $detail_sql .= " AND i.incident_type = ?"; $d_params[] = $incident_type; $d_types .= 's'; }
if ($severity)      { $detail_sql .= " AND i.severity = ?";      $d_params[] = $severity;      $d_types .= 's'; }
$detail_sql .= " ORDER BY i.date_reported DESC";
$stmt = $conn->prepare($detail_sql); $stmt->bind_param($d_types, ...$d_params); $stmt->execute();
$incidents = $stmt->get_result(); $stmt->close();

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-info:#3b82f6;--db-info-light:#dbeafe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
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
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-rose),#be123c);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__actions{display:flex;gap:8px;flex-wrap:wrap;}

/* Alerts */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* Stat Cards */
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 130px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;color:inherit;cursor:default;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);transform:translateY(-3px);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--blue{background:var(--db-info-light);color:var(--db-info);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--blue{background:linear-gradient(90deg,var(--db-info),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__body{padding:20px 22px;}

/* Tabs */
.db-tab-nav{display:flex;gap:0;border-bottom:2px solid var(--db-border);margin-bottom:20px;}
.db-tab-btn{padding:11px 20px;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;color:var(--db-muted);background:none;border:none;border-bottom:3px solid transparent;cursor:pointer;text-decoration:none;transition:all .18s;display:inline-flex;align-items:center;gap:7px;margin-bottom:-2px;}
.db-tab-btn:hover{color:var(--db-navy);border-bottom-color:rgba(28,52,97,.2);}
.db-tab-btn.active{color:var(--db-navy);border-bottom-color:var(--db-navy);}

/* Form */
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--outline{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.3);}
.db-btn--outline:hover{background:rgba(255,255,255,.2);color:#fff;}

/* Badges */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--info{background:var(--db-info-light);color:#1e40af;}
.db-badge--dark{background:#1e293b;color:#f1f5f9;}

/* Table */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr.inc-row:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-rose);cursor:pointer;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}

/* Empty */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Mini stats */
.db-mini-stats{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.db-mini-stat{flex:1 1 120px;background:var(--db-surf);border-radius:var(--db-radius);padding:14px 16px;text-align:center;border:1px solid var(--db-border);box-shadow:var(--db-shadow);}
.db-mini-stat .num{font-size:24px;font-weight:800;letter-spacing:-1px;}
.db-mini-stat .lbl{font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}

/* Charts */
.db-charts-row{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:18px;}
.db-chart-card{flex:1 1 280px;background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);overflow:hidden;}
.db-chart-card__header{padding:16px 20px;border-bottom:1px solid var(--db-border);display:flex;align-items:center;gap:10px;}
.db-chart-card__header h3{font-size:14px;font-weight:700;}
.db-chart-card__body{padding:16px 20px;}

/* Hover preview */
.db-preview{position:fixed;z-index:9999;width:340px;background:var(--db-surf);border-radius:var(--db-radius);box-shadow:var(--db-shadow-lg);border:1px solid var(--db-border);overflow:hidden;pointer-events:none;animation:dbPrevIn .18s ease;}
@keyframes dbPrevIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.db-preview__header{display:flex;align-items:center;gap:12px;padding:14px 16px 10px;border-bottom:1px solid #f0f0f0;}
.db-preview__icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.db-preview__header-text{flex:1;min-width:0;}
.db-preview__type{font-family:'DM Mono',monospace;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:2px;}
.db-preview__title{font-size:.88rem;font-weight:700;color:var(--db-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.db-preview__body{padding:12px 16px 14px;}
.db-preview__row{display:flex;gap:8px;font-size:.8rem;margin-bottom:6px;}
.db-preview__label{color:var(--db-muted);font-weight:600;min-width:72px;flex-shrink:0;}
.db-preview__val{color:var(--db-text);}
.db-preview__footer{font-size:.72rem;color:#adb5bd;display:flex;align-items:center;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0;}

@media print{.no-print{display:none !important}}
@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-preview{display:none !important;}}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="rm-hero__title">Incident Reports &amp; Analytics</div>
                <div class="rm-hero__sub">Generate and analyze incident data for your barangay</div>
            </div>
        </div>
        <div class="rm-hero__actions no-print">
            <button onclick="window.print()" class="db-btn db-btn--outline db-btn--sm">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToCSV()" class="db-btn db-btn--success db-btn--sm">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <a href="manage-incidents.php" class="db-btn db-btn--ghost db-btn--sm">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<!-- Stat Cards -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total']; ?></div><div class="db-stat-card__label">Total</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-fire"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $stats['critical']; ?></div><div class="db-stat-card__label">Critical</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-exclamation-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['high']; ?></div><div class="db-stat-card__label">High</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-minus-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $stats['medium']; ?></div><div class="db-stat-card__label">Medium</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['resolved']; ?></div><div class="db-stat-card__label">Resolved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-percentage"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-teal)"><?php echo $resolution_rate; ?>%</div><div class="db-stat-card__label">Resolution Rate</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
</div>

<!-- Tab Nav -->
<div class="db-tab-nav no-print">
    <a href="?tab=overview<?php echo ($incident_type?'&incident_type='.urlencode($incident_type):'').($severity?'&severity='.urlencode($severity):'').'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
       class="db-tab-btn <?php echo $active_tab==='overview'?'active':''; ?>">
        <i class="fas fa-list"></i> Incident List
    </a>
    <a href="?tab=analytics<?php echo ($incident_type?'&incident_type='.urlencode($incident_type):'').($severity?'&severity='.urlencode($severity):'').'&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
       class="db-tab-btn <?php echo $active_tab==='analytics'?'active':''; ?>">
        <i class="fas fa-chart-bar"></i> Reports &amp; Analytics
    </a>
</div>

<!-- Filters Panel -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-filter"></i></div>
            <h2>Report Filters</h2>
        </div>
        <?php if ($incident_type || $severity): ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-size:12px;color:var(--db-muted)">Active:</span>
            <?php if ($incident_type): ?><span class="db-badge db-badge--rose"><?php echo htmlspecialchars($incident_type); ?></span><?php endif; ?>
            <?php if ($severity): ?><span class="db-badge db-badge--amber"><?php echo htmlspecialchars($severity); ?></span><?php endif; ?>
            <a href="?tab=<?php echo $active_tab; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
            <div class="db-form-row">
                <div>
                    <label class="db-filter-label">Date From</label>
                    <input type="date" name="date_from" class="db-input" value="<?php echo htmlspecialchars($date_from); ?>" required>
                </div>
                <div>
                    <label class="db-filter-label">Date To</label>
                    <input type="date" name="date_to" class="db-input" value="<?php echo htmlspecialchars($date_to); ?>" required>
                </div>
                <div>
                    <label class="db-filter-label">Incident Type</label>
                    <select name="incident_type" class="db-select">
                        <option value="">All Types</option>
                        <?php foreach(['Crime','Fire','Accident','Health Emergency','Violation','Natural Disaster','Others'] as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $incident_type===$t?'selected':''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="db-filter-label">Severity</label>
                    <select name="severity" class="db-select">
                        <option value="">All Levels</option>
                        <?php foreach(['Low','Medium','High','Critical'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $severity===$s?'selected':''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Apply Filters</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($active_tab === 'overview'): ?>

<!-- Incident List Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-list"></i></div>
            <h2>Incident List</h2>
            <span class="db-badge db-badge--rose"><?php echo $stats['total']; ?></span>
        </div>
        <span class="db-text-sm"><i class="fas fa-info-circle"></i> Hover to preview · Click to open</span>
    </div>
    <div class="db-table-wrap">
        <table class="db-table" id="incidentsTable">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Reporter</th>
                    <th>Type</th>
                    <th>Location</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Responder</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ($incidents && $incidents->num_rows > 0):
                $incidents->data_seek(0);
                while ($incident = $incidents->fetch_assoc()):
                    $sev = $incident['severity'];
                    $sev_badge = ['Low'=>'success','Medium'=>'indigo','High'=>'amber','Critical'=>'rose'];
                    $sev_cls = $sev_badge[$sev] ?? 'muted';

                    $stat = $incident['status'];
                    $stat_badge = ['Pending'=>'amber','Reported'=>'amber','Under Investigation'=>'indigo','In Progress'=>'sky','Resolved'=>'success','Closed'=>'muted'];
                    $stat_cls = $stat_badge[$stat] ?? 'muted';

                    $type_icon = ['Crime'=>'fa-user-secret','Fire'=>'fa-fire','Accident'=>'fa-car-crash','Health Emergency'=>'fa-ambulance','Violation'=>'fa-gavel','Natural Disaster'=>'fa-cloud-showers-heavy'];
                    $ticon = $type_icon[$incident['incident_type']] ?? 'fa-exclamation-triangle';

                    $loc = $incident['location'] ?? '';
                    $loc_short = strlen($loc) > 30 ? substr($loc, 0, 30) . '…' : $loc;
            ?>
            <tr class="inc-row"
                data-url="incident-details.php?id=<?php echo $incident['incident_id']; ?>"
                data-pref="<?php echo htmlspecialchars($incident['reference_no']); ?>"
                data-ptype="<?php echo htmlspecialchars($incident['incident_type']); ?>"
                data-picon="<?php echo $ticon; ?>"
                data-pc="<?php echo $sev_cls; ?>"
                data-psev="<?php echo htmlspecialchars($sev); ?>"
                data-pstat="<?php echo htmlspecialchars($stat); ?>"
                data-ploc="<?php echo htmlspecialchars($loc); ?>"
                data-preporter="<?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?>"
                data-presponder="<?php echo htmlspecialchars($incident['responder_name'] ?? 'Unassigned'); ?>"
                data-ptime="<?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($incident['date_reported']))); ?>">
                <td><strong class="db-id"><?php echo htmlspecialchars($incident['reference_no']); ?></strong></td>
                <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($incident['date_reported'])); ?></span></td>
                <td><strong><?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?></strong></td>
                <td>
                    <span class="db-badge db-badge--muted">
                        <i class="fas <?php echo $ticon; ?>"></i>
                        <?php echo htmlspecialchars($incident['incident_type']); ?>
                    </span>
                </td>
                <td>
                    <span class="db-text-sm">
                        <i class="fas fa-map-marker-alt" style="color:var(--db-rose);margin-right:3px;"></i>
                        <?php echo htmlspecialchars($loc_short); ?>
                    </span>
                </td>
                <td><span class="db-badge db-badge--<?php echo $sev_cls; ?>"><?php echo htmlspecialchars($sev); ?></span></td>
                <td><span class="db-badge db-badge--<?php echo $stat_cls; ?>"><?php echo htmlspecialchars($stat); ?></span></td>
                <td>
                    <?php if (!empty($incident['responder_name'])): ?>
                        <span class="db-text-sm"><?php echo htmlspecialchars($incident['responder_name']); ?></span>
                    <?php else: ?>
                        <span class="db-badge db-badge--muted">Unassigned</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile;
            else: ?>
            <tr>
                <td colspan="8">
                    <div class="db-empty">
                        <i class="fas fa-inbox"></i>
                        <p>No incidents found for the selected period</p>
                        <p class="db-text-sm">Try adjusting the date range or filters above.</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: /* Analytics tab */ ?>

<!-- Mini Stats -->
<div class="db-mini-stats">
    <div class="db-mini-stat"><div class="num"><?php echo $stats['total']; ?></div><div class="lbl">Total</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-rose)"><?php echo $stats['critical']; ?></div><div class="lbl">Critical</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-amber-dark)"><?php echo $stats['high']; ?></div><div class="lbl">High</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-success)"><?php echo $stats['resolved']; ?></div><div class="lbl">Resolved</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-amber-dark)"><?php echo $stats['reported']; ?></div><div class="lbl">Reported</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-teal);font-size:20px;"><?php echo $resolution_rate; ?>%</div><div class="lbl">Resolution Rate</div></div>
</div>

<!-- Charts -->
<div class="db-charts-row">
    <div class="db-chart-card">
        <div class="db-chart-card__header">
            <i class="fas fa-chart-bar" style="color:var(--db-rose)"></i>
            <h3>Incident Type Distribution</h3>
        </div>
        <div class="db-chart-card__body">
            <canvas id="typeChart" style="max-height:240px;"></canvas>
            <table class="db-table" style="margin-top:14px;">
                <tbody>
                <?php
                $type_distribution->data_seek(0);
                if ($type_distribution->num_rows > 0):
                    while ($r = $type_distribution->fetch_assoc()):
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['incident_type']); ?></td>
                    <td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $r['count']; ?></td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="2" style="text-align:center;color:var(--db-muted)">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="db-chart-card">
        <div class="db-chart-card__header">
            <i class="fas fa-chart-pie" style="color:var(--db-indigo)"></i>
            <h3>Severity Breakdown</h3>
        </div>
        <div class="db-chart-card__body">
            <canvas id="severityChart" style="max-height:240px;"></canvas>
            <table class="db-table" style="margin-top:14px;">
                <tbody>
                    <tr><td><span class="db-badge db-badge--rose">Critical</span></td><td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $stats['critical']; ?></td></tr>
                    <tr><td><span class="db-badge db-badge--amber">High</span></td><td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $stats['high']; ?></td></tr>
                    <tr><td><span class="db-badge db-badge--indigo">Medium</span></td><td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $stats['medium']; ?></td></tr>
                    <tr><td><span class="db-badge db-badge--success">Low</span></td><td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $stats['low']; ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Status Distribution -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-chart-bar"></i></div>
            <h2>Status Distribution</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <canvas id="statusChart" style="max-height:180px;"></canvas>
    </div>
</div>

<!-- Period Summary + Export -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-info-circle"></i></div>
            <h2>Period Summary</h2>
        </div>
    </div>
    <div class="db-panel__body" style="display:flex;gap:24px;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
            <p style="font-size:13px;margin-bottom:8px;"><strong>Date Range:</strong> <?php echo date('M d, Y', strtotime($date_from)) . ' – ' . date('M d, Y', strtotime($date_to)); ?></p>
            <?php $d1=new DateTime($date_from);$d2=new DateTime($date_to);$days=$d1->diff($d2)->days+1; ?>
            <p style="font-size:13px;margin-bottom:8px;"><strong>Total Days:</strong> <?php echo $days; ?></p>
            <p style="font-size:13px;margin-bottom:8px;"><strong>Avg/Day:</strong> <?php echo $days>0?number_format($stats['total']/$days,2):0; ?> incidents</p>
            <p style="font-size:13px;"><strong>Resolution Rate:</strong> <span style="color:var(--db-success);font-weight:700;"><?php echo $resolution_rate; ?>%</span></p>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;min-width:160px;">
            <button class="db-btn db-btn--primary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            <button class="db-btn db-btn--success" onclick="exportToCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
        </div>
    </div>
</div>

<!-- Detailed List in Analytics -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-list"></i></div>
            <h2>Detailed Incident List</h2>
            <span class="db-badge db-badge--rose"><?php echo $stats['total']; ?></span>
        </div>
        <small class="db-text-sm"><?php echo date('M d, Y', strtotime($date_from)); ?> – <?php echo date('M d, Y', strtotime($date_to)); ?></small>
    </div>
    <div class="db-table-wrap">
        <table class="db-table" id="incidentsTable">
            <thead>
                <tr><th>Reference</th><th>Date</th><th>Type</th><th>Location</th><th>Severity</th><th>Status</th><th>Reporter</th><th>Responder</th></tr>
            </thead>
            <tbody>
            <?php
            $incidents->data_seek(0);
            if ($incidents->num_rows > 0):
                while ($incident = $incidents->fetch_assoc()):
                    $sev_badge = ['Low'=>'success','Medium'=>'indigo','High'=>'amber','Critical'=>'rose'];
                    $sev_cls = $sev_badge[$incident['severity']] ?? 'muted';
                    $stat_badge = ['Pending'=>'amber','Reported'=>'amber','Under Investigation'=>'indigo','In Progress'=>'sky','Resolved'=>'success','Closed'=>'muted'];
                    $stat_cls = $stat_badge[$incident['status']] ?? 'muted';
                    $loc = $incident['location'] ?? '';
            ?>
            <tr>
                <td><span class="db-id"><?php echo htmlspecialchars($incident['reference_no']); ?></span></td>
                <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($incident['date_reported'])); ?></span></td>
                <td><?php echo htmlspecialchars($incident['incident_type']); ?></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars(strlen($loc)>30?substr($loc,0,30).'…':$loc); ?></span></td>
                <td><span class="db-badge db-badge--<?php echo $sev_cls; ?>"><?php echo htmlspecialchars($incident['severity']); ?></span></td>
                <td><span class="db-badge db-badge--<?php echo $stat_cls; ?>"><?php echo htmlspecialchars($incident['status']); ?></span></td>
                <td><?php echo htmlspecialchars($incident['reporter_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($incident['responder_name'] ?? 'Unassigned'); ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="8"><div class="db-empty"><i class="fas fa-inbox"></i><p>No incidents found</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>
</div><!-- /padding -->

<!-- Hover Preview Card -->
<div id="dbPreview" class="db-preview" style="display:none;">
    <div class="db-preview__header">
        <div class="db-preview__icon" id="dbPrevIcon"><i id="dbPrevIconI" class="fas fa-exclamation-triangle"></i></div>
        <div class="db-preview__header-text">
            <div class="db-preview__type" id="dbPrevType"></div>
            <div class="db-preview__title" id="dbPrevTitle"></div>
        </div>
    </div>
    <div class="db-preview__body">
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-user"></i> Reporter</span><span class="db-preview__val" id="dbPrevReporter"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-user-shield"></i> Responder</span><span class="db-preview__val" id="dbPrevResponder"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-map-marker-alt"></i> Location</span><span class="db-preview__val" id="dbPrevLoc"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-tachometer-alt"></i> Severity</span><span class="db-preview__val" id="dbPrevSev"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-info-circle"></i> Status</span><span class="db-preview__val" id="dbPrevStat"></span></div>
        <div class="db-preview__footer"><i class="far fa-calendar-alt"></i><span id="dbPrevTime"></span></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// ── Hover Preview ─────────────────────────────────────────────────────────────
(function(){
    const card = document.getElementById('dbPreview');
    if (!card) return;
    const iconBox = card.querySelector('#dbPrevIcon'), iconEl = card.querySelector('#dbPrevIconI');
    const cmap = {
        rose:    {bg:'rgba(225,29,72,.1)',   text:'#e11d48'},
        amber:   {bg:'rgba(245,158,11,.1)',  text:'#b45309'},
        success: {bg:'rgba(16,185,129,.1)',  text:'#10b981'},
        indigo:  {bg:'rgba(99,102,241,.1)',  text:'#6366f1'},
        muted:   {bg:'rgba(148,163,184,.1)', text:'#64748b'}
    };
    let timer;
    function pos(e){
        const cw=340, ch=card.offsetHeight||260, m=14;
        let x=e.clientX+m, y=e.clientY+m;
        if(x+cw>window.innerWidth-m) x=e.clientX-cw-m;
        if(y+ch>window.innerHeight-m) y=e.clientY-ch-m;
        card.style.left=x+'px'; card.style.top=y+'px';
    }
    document.querySelectorAll('.inc-row').forEach(row=>{
        row.addEventListener('mouseenter', function(e){
            clearTimeout(timer);
            const c = cmap[this.dataset.pc] || cmap.muted;
            document.getElementById('dbPrevTitle').textContent    = this.dataset.pref;
            document.getElementById('dbPrevType').textContent     = this.dataset.ptype;
            document.getElementById('dbPrevReporter').textContent = this.dataset.preporter;
            document.getElementById('dbPrevResponder').textContent= this.dataset.presponder;
            document.getElementById('dbPrevLoc').textContent      = this.dataset.ploc;
            document.getElementById('dbPrevSev').textContent      = this.dataset.psev;
            document.getElementById('dbPrevStat').textContent     = this.dataset.pstat;
            document.getElementById('dbPrevTime').textContent     = this.dataset.ptime;
            iconEl.className = 'fas ' + this.dataset.picon;
            iconBox.style.background = c.bg;
            iconEl.style.color = c.text;
            pos(e); card.style.display='block';
        });
        row.addEventListener('mousemove', pos);
        row.addEventListener('mouseleave', ()=>{ timer=setTimeout(()=>{ if(!card.matches(':hover')) card.style.display='none'; }, 150); });
        row.addEventListener('click', function(){ if(this.dataset.url) location.href=this.dataset.url; });
    });
})();

// ── CSV Export ────────────────────────────────────────────────────────────────
function exportToCSV() {
    const table = document.getElementById('incidentsTable');
    if (!table) return;
    const rows = [];
    rows.push([...table.querySelectorAll('thead th')].map(th => '"' + th.textContent.trim() + '"').join(','));
    table.querySelectorAll('tbody tr').forEach(tr => {
        const cells = [...tr.querySelectorAll('td')].map(td => '"' + td.textContent.trim().replace(/"/g,'""') + '"');
        if (cells.length) rows.push(cells.join(','));
    });
    const blob = new Blob([rows.join('\n')], {type:'text/csv'});
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(blob),
        download: 'incident_report_<?php echo date('Y-m-d'); ?>.csv'
    });
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

<?php if ($active_tab === 'analytics'): ?>
// ── Charts ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
    <?php
    $type_distribution->data_seek(0);
    $typeLabels = []; $typeCounts = [];
    while ($r = $type_distribution->fetch_assoc()) { $typeLabels[] = $r['incident_type']; $typeCounts[] = (int)$r['count']; }
    $status_distribution->data_seek(0);
    $statusLabels = []; $statusCounts = [];
    while ($r = $status_distribution->fetch_assoc()) { $statusLabels[] = $r['status']; $statusCounts[] = (int)$r['count']; }
    ?>
    const typeLabels   = <?php echo json_encode($typeLabels); ?>;
    const typeCounts   = <?php echo json_encode($typeCounts); ?>;
    const statusLabels = <?php echo json_encode($statusLabels); ?>;
    const statusCounts = <?php echo json_encode($statusCounts); ?>;

    const tc = document.getElementById('typeChart');
    if (tc) {
        new Chart(tc.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: typeLabels,
                datasets: [{
                    data: typeCounts,
                    backgroundColor: ['#e11d48','#f59e0b','#0ea5e9','#6366f1','#10b981','#0d9488','#3b82f6','#a855f7'],
                    borderWidth: 2, borderColor: '#fff', hoverOffset: 6
                }]
            },
            options: { responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'bottom', labels:{ font:{family:'Sora',size:12}, padding:12 } } } }
        });
    }

    const sc = document.getElementById('severityChart');
    if (sc) {
        new Chart(sc.getContext('2d'), {
            type: 'pie',
            data: {
                labels: ['Critical','High','Medium','Low'],
                datasets: [{
                    data: [<?php echo (int)$stats['critical'].','. (int)$stats['high'].','. (int)$stats['medium'].','. (int)$stats['low']; ?>],
                    backgroundColor: ['#e11d48','#f59e0b','#6366f1','#10b981'],
                    borderWidth: 2, borderColor: '#fff'
                }]
            },
            options: { responsive:true, maintainAspectRatio:true, plugins:{ legend:{ position:'bottom', labels:{ font:{family:'Sora',size:12}, padding:12 } } } }
        });
    }

    const stc = document.getElementById('statusChart');
    if (stc) {
        new Chart(stc.getContext('2d'), {
            type: 'bar',
            data: {
                labels: statusLabels,
                datasets: [{
                    label: 'Incidents',
                    data: statusCounts,
                    backgroundColor: ['#f59e0b','#6366f1','#0ea5e9','#10b981','#6c757d','#fd7e14'],
                    borderRadius: 6, borderSkipped: false
                }]
            },
            options: {
                indexAxis: 'y', responsive:true,
                plugins:{ legend:{ display:false } },
                scales:{
                    x:{ beginAtZero:true, ticks:{ stepSize:1, font:{family:'Sora',size:12} } },
                    y:{ ticks:{ font:{family:'Sora',size:12} } }
                }
            }
        });
    }
});
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>