<?php
/**
 * Attendance Reports Dashboard — UI matches index.php
 * modules/attendance/admin/attendance-reports.php
 */

date_default_timezone_set('Asia/Manila');

require_once '../../../config/config.php';
require_once '../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) {
    redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Admin', 'Super Admin'])) {
    redirect('/barangaylink/modules/dashboard/index.php', 'Access denied', 'error');
}

$page_title = 'Attendance Reports';

$current_month  = date('n');
$current_year   = date('Y');
$selected_month = isset($_GET['month']) ? intval($_GET['month']) : $current_month;
$selected_year  = isset($_GET['year'])  ? intval($_GET['year'])  : $current_year;

$first_day = date('Y-m-01', strtotime("$selected_year-$selected_month-01"));
$last_day  = date('Y-m-t',  strtotime("$selected_year-$selected_month-01"));

// ── Queries (identical to original) ─────────────────────────────────────────
$overall_stats = fetchOne($conn,
    "SELECT
        COUNT(DISTINCT user_id) as total_users,
        COUNT(*) as total_records,
        SUM(CASE WHEN status='Present'  THEN 1 ELSE 0 END) as total_present,
        SUM(CASE WHEN status='Late'     THEN 1 ELSE 0 END) as total_late,
        SUM(CASE WHEN status='Absent'   THEN 1 ELSE 0 END) as total_absent,
        SUM(CASE WHEN status='On Leave' THEN 1 ELSE 0 END) as total_leave,
        AVG(CASE WHEN status IN ('Present','Late') THEN 1 ELSE 0 END)*100 as attendance_rate
    FROM tbl_attendance
    WHERE attendance_date BETWEEN ? AND ?",
    [$first_day, $last_day], 'ss'
);

$user_summary = fetchAll($conn,
    "SELECT
        u.user_id,
        CONCAT(r.first_name,' ',r.last_name) as full_name,
        u.username, u.role,
        r.profile_photo,
        COUNT(*) as total_days,
        SUM(CASE WHEN a.status='Present'  THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN a.status='Late'     THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN a.status='Absent'   THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN a.status='On Leave' THEN 1 ELSE 0 END) as leave_days,
        ROUND((SUM(CASE WHEN a.status IN ('Present','Late') THEN 1 ELSE 0 END)/COUNT(*))*100,2) as attendance_percentage,
        SUM(CASE WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL
            THEN TIMESTAMPDIFF(MINUTE,a.time_in,a.time_out) ELSE 0 END) as total_minutes,
        (SELECT COUNT(*) FROM tbl_payslips
         WHERE user_id=u.user_id AND pay_period_start=? AND pay_period_end=?) as payslip_generated
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
    LEFT JOIN tbl_attendance a ON u.user_id=a.user_id AND a.attendance_date BETWEEN ? AND ?
    WHERE u.role IN ('Admin','Staff','Tanod','Driver')
    GROUP BY u.user_id, r.profile_photo
    ORDER BY attendance_percentage DESC",
    [$first_day, $last_day, $first_day, $last_day], 'ssss'
);

$daily_trend = fetchAll($conn,
    "SELECT attendance_date,
        COUNT(*) as total,
        SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN status='Late'    THEN 1 ELSE 0 END) as late,
        SUM(CASE WHEN status='Absent'  THEN 1 ELSE 0 END) as absent
    FROM tbl_attendance
    WHERE attendance_date BETWEEN ? AND ?
    GROUP BY attendance_date ORDER BY attendance_date",
    [$first_day, $last_day], 'ss'
);

$top_performers = fetchAll($conn,
    "SELECT u.user_id, CONCAT(r.first_name,' ',r.last_name) as full_name, u.role,
        r.profile_photo,
        COUNT(*) as total_days,
        SUM(CASE WHEN a.status IN ('Present','Late') THEN 1 ELSE 0 END) as attended_days,
        ROUND((SUM(CASE WHEN a.status IN ('Present','Late') THEN 1 ELSE 0 END)/COUNT(*))*100,2) as rate
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
    LEFT JOIN tbl_attendance a ON u.user_id=a.user_id AND a.attendance_date BETWEEN ? AND ?
    WHERE u.role IN ('Admin','Staff','Tanod','Driver')
    GROUP BY u.user_id, r.profile_photo HAVING attended_days>0
    ORDER BY rate DESC LIMIT 5",
    [$first_day, $last_day], 'ss'
);

$payslip_stats = fetchOne($conn,
    "SELECT COUNT(*) as total_payslips,
        SUM(gross_pay) as total_gross, SUM(net_pay) as total_net,
        SUM(overtime_pay) as total_overtime, SUM(late_deductions) as total_late_deductions
    FROM tbl_payslips WHERE pay_period_start=? AND pay_period_end=?",
    [$first_day, $last_day], 'ss'
);

$status_distribution = [
    'present' => $overall_stats['total_present'] ?? 0,
    'late'    => $overall_stats['total_late']    ?? 0,
    'absent'  => $overall_stats['total_absent']  ?? 0,
    'leave'   => $overall_stats['total_leave']   ?? 0,
];

// Pending count
$pending_count = 0;
foreach ($user_summary as $u) { if ($u['payslip_generated'] == 0) $pending_count++; }
$total_staff      = count($user_summary);
$generated        = $total_staff - $pending_count;
$progress_percent = $total_staff > 0 ? ($generated / $total_staff) * 100 : 0;

// Role chart data
$role_stats = [];
foreach ($user_summary as $u) {
    $r = $u['role'];
    if (!isset($role_stats[$r])) $role_stats[$r] = ['total' => 0, 'count' => 0];
    $role_stats[$r]['total'] += $u['attendance_percentage'];
    $role_stats[$r]['count']++;
}
$role_labels = $role_percentages = [];
foreach ($role_stats as $r => $data) {
    $role_labels[]      = $r;
    $role_percentages[] = round($data['total'] / $data['count'], 2);
}

$period_label = date('F Y', strtotime($first_day));
$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     INLINE STYLES — mirrors index.php tokens
     ══════════════════════════════════════════════════════════════════════ -->
<style>
    /* ══════════════════════════════════════
   ATTENDANCE REPORTS DARK MODE OVERRIDES
══════════════════════════════════════ */

/* Filter card */
body.dark-mode .att-filter-card {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .att-filter-group label {
    color: #94a3b8;
}

/* Role pills */
body.dark-mode .role-pill--admin   { background: #3b0000; color: #fca5a5; }
body.dark-mode .role-pill--staff   { background: #2a1f00; color: #fcd34d; }
body.dark-mode .role-pill--tanod   { background: #0c1f4a; color: #93c5fd; }
body.dark-mode .role-pill--driver  { background: #052e1c; color: #6ee7b7; }

/* Staff name */
body.dark-mode .staff-name {
    color: #e2e8f0 !important;
}

/* Payslip stat cards */
body.dark-mode .pay-stat {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .pay-stat__lbl { color: #64748b; }
body.dark-mode .pay-stat__val { color: #e2e8f0; }
body.dark-mode .pay-stat__sub { color: #64748b; }

/* Quick action cards */
body.dark-mode .rpt-action-card {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .rpt-action-card h5 {
    color: #e2e8f0;
}
body.dark-mode .rpt-action-card p {
    color: #94a3b8;
}

/* Top performers */
body.dark-mode .perf-item {
    border-color: #334155;
}
body.dark-mode .perf-name {
    color: #e2e8f0;
}
body.dark-mode .perf-role {
    color: #64748b;
}
body.dark-mode .perf-rank--2 {
    background: #334155;
    color: #94a3b8;
}

/* Progress bar background */
body.dark-mode .rpt-bar {
    background: #334155;
}

/* Generation progress counters */
body.dark-mode .gen-counter {
    background: #243044;
    border-color: #334155;
}
body.dark-mode .gen-counter__lbl {
    color: #64748b;
}

/* Progress section text */
body.dark-mode div[style*="Generation Progress"] {
    color: #94a3b8 !important;
}
body.dark-mode div[style*="Generation Progress"] + div span {
    color: #e2e8f0 !important;
}

/* Pending alert */
body.dark-mode .rpt-pending-alert {
    background: #2a1f00;
    border-color: #854d0e;
}
body.dark-mode .rpt-pending-alert h6 {
    color: #fcd34d;
}
body.dark-mode .rpt-pending-alert p {
    color: #fcd34d;
}
body.dark-mode .rpt-pending-alert__icon {
    color: #f59e0b;
}

/* Table search input */
body.dark-mode #rpt-search {
    background: #334155;
    color: #e2e8f0;
    border-color: #475569;
}
body.dark-mode #rpt-search:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15);
    background: #3d4f68;
}
body.dark-mode #rpt-search::placeholder {
    color: #64748b;
}

/* Table total hours */
body.dark-mode span[style*="color:#475569"] {
    color: #94a3b8 !important;
}

/* Chart legend labels — update chart border color on dark mode */
body.dark-mode canvas {
    filter: none;
}

/* Progress percent label */
body.dark-mode div[style*="font-weight:800;font-family:'DM Mono'"] {
    color: #e2e8f0 !important;
}

/* Complete label inside progress bar */
body.dark-mode .rpt-bar__fill strong {
    color: #fff;
}

/* Payslip progress complete label */
body.dark-mode span[style*="color:#10b981;font-family:'DM Mono'"] {
    color: #34d399 !important;
}

/* Section heading text inside panels */
body.dark-mode div[style*="color:#64748b;font-weight:600"] {
    color: #94a3b8 !important;
}
/* ── Reuse index att-badge palette ────────────────────────────────────────── */
.att-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10.5px;
    font-weight: 600; letter-spacing: .3px; white-space: nowrap;
}
.att-badge--present  { background: #d1fae5; color: #065f46; }
.att-badge--late     { background: #fef3c7; color: #92400e; }
.att-badge--absent   { background: #fee2e2; color: #7f1d1d; }
.att-badge--leave    { background: #dbeafe; color: #1e40af; }
.att-badge--pending  { background: #fef3c7; color: #92400e; }
.att-badge--done     { background: #d1fae5; color: #065f46; }

.role-pill {
    display: inline-block; padding: 2px 9px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: .4px;
}
.role-pill--admin   { background: #fee2e2; color: #991b1b; }
.role-pill--staff   { background: #fef3c7; color: #92400e; }
.role-pill--tanod   { background: #dbeafe; color: #1e40af; }
.role-pill--driver  { background: #d1fae5; color: #065f46; }

/* ── Filter card (identical to index.php) ───────────────────────────────── */
.att-filter-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    box-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    padding: 18px 22px; margin-bottom: 20px;
}
.att-filter-row { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
.att-filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 140px; }
.att-filter-group label {
    font-size: 11.5px; font-weight: 600; color: #64748b;
    text-transform: uppercase; letter-spacing: .5px;
}

/* ── Two-column chart grid ───────────────────────────────────────────────── */
.rpt-chart-grid {
    display: grid; grid-template-columns: 1fr 2fr; gap: 18px; margin-bottom: 20px;
}
@media (max-width: 900px) { .rpt-chart-grid { grid-template-columns: 1fr; } }

/* ── Performer list ─────────────────────────────────────────────────────── */
.perf-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0; border-bottom: 1px solid #f1f5f9;
}
.perf-item:last-child { border-bottom: none; }
.perf-rank {
    width: 32px; height: 32px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800; flex-shrink: 0;
}
.perf-rank--1 { background: #fef3c7; color: #92400e; }
.perf-rank--2 { background: #f1f5f9; color: #475569; }
.perf-rank--n { background: #eff6ff; color: #1e40af; }
.perf-name { font-size: 13px; font-weight: 700; color: #0f172a; }
.perf-role { font-size: 11px; color: #94a3b8; }
.perf-rate {
    margin-left: auto; font-family: 'DM Mono', monospace;
    font-size: 14px; font-weight: 800; color: #10b981;
}
.perf-days { font-size: 10px; color: #94a3b8; text-align: right; }

/* ── Staff avatar (same as index) ────────────────────────────────────────── */
.staff-avatar {
    width: 34px; height: 34px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13px; color: #fff; flex-shrink: 0;
    background: linear-gradient(135deg, #0d1b36, #1c3461); overflow: hidden;
}
.staff-avatar img { width: 100%; height: 100%; object-fit: cover; }
.staff-info { display: flex; align-items: center; gap: 10px; }
.staff-name { font-weight: 700; font-size: 13px; color: #0f172a; }

/* ── Progress bar ────────────────────────────────────────────────────────── */
.rpt-bar-wrap { min-width: 100px; }
.rpt-bar {
    height: 22px; border-radius: 6px; background: #f1f5f9;
    overflow: hidden; position: relative;
}
.rpt-bar__fill {
    height: 100%; border-radius: 6px; display: flex; align-items: center;
    justify-content: center; font-size: 11px; font-weight: 700; color: #fff;
    transition: width 1s cubic-bezier(.34,1.56,.64,1);
}

/* ── Progress generation bar ─────────────────────────────────────────────── */
.gen-progress-row {
    display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: center;
}
.gen-counter-grid {
    display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-top: 14px;
}
.gen-counter {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 12px; text-align: center;
}
.gen-counter__num { font-size: 22px; font-weight: 800; line-height: 1; }
.gen-counter__lbl { font-size: 10px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }

/* ── Quick actions row ───────────────────────────────────────────────────── */
.rpt-actions-row {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px;
}
@media (max-width: 768px) { .rpt-actions-row { grid-template-columns: 1fr; } }
.rpt-action-card {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 22px; text-align: center;
    box-shadow: 0 1px 3px rgba(13,27,54,.05), 0 4px 16px rgba(13,27,54,.06);
    transition: transform .18s, box-shadow .18s;
}
.rpt-action-card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(13,27,54,.12); }
.rpt-action-card__icon {
    width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 14px;
    display: flex; align-items: center; justify-content: center; font-size: 22px;
}
.rpt-action-card h5 { font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 6px; }
.rpt-action-card p  { font-size: 12px; color: #94a3b8; line-height: 1.5; margin-bottom: 16px; }

/* ── Pending alert ───────────────────────────────────────────────────────── */
.rpt-pending-alert {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px;
    padding: 14px 18px; display: flex; align-items: center; gap: 14px; margin-top: 18px;
}
.rpt-pending-alert__icon { font-size: 22px; flex-shrink: 0; color: #f59e0b; }
.rpt-pending-alert h6 { font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 2px; }
.rpt-pending-alert p  { font-size: 12px; color: #92400e; margin: 0; }

/* ── Payslip stat mini-cards ─────────────────────────────────────────────── */
.pay-stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
@media (max-width: 900px) { .pay-stat-grid { grid-template-columns: repeat(2,1fr); } }
.pay-stat {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 16px 18px; box-shadow: 0 1px 3px rgba(13,27,54,.05);
    display: flex; align-items: center; gap: 14px;
}
.pay-stat__icon {
    width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.pay-stat__body {}
.pay-stat__lbl { font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
.pay-stat__val { font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1; }
.pay-stat__sub { font-size: 10.5px; color: #94a3b8; margin-top: 2px; }

/* ── Table search ────────────────────────────────────────────────────────── */
#rpt-search {
    width: 100%; padding: 9px 13px; border: 1.5px solid #e2e8f0; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 13px; color: #0f172a;
    background: #f8fafc; outline: none; transition: all .18s; margin-bottom: 14px;
}
#rpt-search:focus { border-color: #1c3461; box-shadow: 0 0 0 3px rgba(28,52,97,.1); background: #fff; }

@media print {
    .db-hero, .att-filter-card, .rpt-actions-row, nav, .sidebar, .header, .no-print { display: none !important; }
    .db-panel, .pay-stat, .pay-stat-grid { box-shadow: none !important; border: 1px solid #ddd !important; }
}
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar"><i class="fas fa-chart-bar" style="font-size:22px;color:#fff;"></i></div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($user_role); ?>
                </div>
                <h1 class="db-hero__title">Attendance Reports</h1>
                <p class="db-hero__sub">Analytics &amp; payroll insights for <?php echo $period_label; ?></p>
            </div>
        </div>
        <div class="db-hero__right">
            <div class="db-hero__datetime">
                <!-- Month / Year selectors -->
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="monthSelector" onchange="changeDate()"
                            style="padding:7px 12px;border:1.5px solid rgba(255,255,255,.25);border-radius:8px;
                                   background:rgba(255,255,255,.1);color:#fff;font-family:'Sora',sans-serif;
                                   font-size:13px;font-weight:600;outline:none;cursor:pointer;">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>
                                    style="background:#1c3461;color:#fff;">
                                <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select id="yearSelector" onchange="changeDate()"
                            style="padding:7px 12px;border:1.5px solid rgba(255,255,255,.25);border-radius:8px;
                                   background:rgba(255,255,255,.1);color:#fff;font-family:'Sora',sans-serif;
                                   font-size:13px;font-weight:600;outline:none;cursor:pointer;">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>
                                    style="background:#1c3461;color:#fff;">
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <button onclick="window.print()" class="db-btn db-btn--ghost"
                            style="border-color:rgba(255,255,255,.25);color:#fff;background:rgba(255,255,255,.1);">
                        <i class="fas fa-print"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ─── STAT CARDS — attendance ───────────────────────────────────────────── -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-clipboard-list"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($overall_stats['total_records'] ?? 0); ?></div>
            <div class="db-stat-card__label">Total Records</div>
            <div style="font-size:10.5px;color:#94a3b8;margin-top:2px;">
                <i class="fas fa-users" style="font-size:9px;"></i> <?php echo $overall_stats['total_users'] ?? 0; ?> staff
            </div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-chart-line"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;"><?php echo number_format($overall_stats['attendance_rate'] ?? 0, 1); ?>%</div>
            <div class="db-stat-card__label">Attendance Rate</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;"><?php echo number_format($overall_stats['total_present'] ?? 0); ?></div>
            <div class="db-stat-card__label">Present</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo number_format($overall_stats['total_late'] ?? 0); ?></div>
            <div class="db-stat-card__label">Late Arrivals</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-times-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#e11d48;"><?php echo number_format($overall_stats['total_absent'] ?? 0); ?></div>
            <div class="db-stat-card__label">Absences</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-calendar-check"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#6366f1;"><?php echo number_format($overall_stats['total_leave'] ?? 0); ?></div>
            <div class="db-stat-card__label">On Leave</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
</div>

<!-- ─── PAYSLIP STAT CARDS ────────────────────────────────────────────────── -->
<div class="pay-stat-grid">
    <div class="pay-stat">
        <div class="pay-stat__icon" style="background:#d1fae5;"><i class="fas fa-file-invoice-dollar" style="color:#10b981;"></i></div>
        <div class="pay-stat__body">
            <div class="pay-stat__lbl">Payslips Generated</div>
            <div class="pay-stat__val" style="color:#10b981;"><?php echo $payslip_stats['total_payslips'] ?? 0; ?></div>
            <div class="pay-stat__sub">of <?php echo $overall_stats['total_users'] ?? 0; ?> staff</div>
        </div>
    </div>
    <div class="pay-stat">
        <div class="pay-stat__icon" style="background:#e0f2fe;"><i class="fas fa-money-bill-wave" style="color:#0ea5e9;"></i></div>
        <div class="pay-stat__body">
            <div class="pay-stat__lbl">Total Gross Pay</div>
            <div class="pay-stat__val" style="color:#0ea5e9;">₱<?php echo number_format($payslip_stats['total_gross'] ?? 0, 2); ?></div>
            <div class="pay-stat__sub">Before deductions</div>
        </div>
    </div>
    <div class="pay-stat">
        <div class="pay-stat__icon" style="background:#eff6ff;"><i class="fas fa-hand-holding-usd" style="color:#3b82f6;"></i></div>
        <div class="pay-stat__body">
            <div class="pay-stat__lbl">Total Net Pay</div>
            <div class="pay-stat__val" style="color:#3b82f6;">₱<?php echo number_format($payslip_stats['total_net'] ?? 0, 2); ?></div>
            <div class="pay-stat__sub">After deductions</div>
        </div>
    </div>
    <div class="pay-stat">
        <div class="pay-stat__icon" style="background:#fef3c7;"><i class="fas fa-business-time" style="color:#f59e0b;"></i></div>
        <div class="pay-stat__body">
            <div class="pay-stat__lbl">Overtime Pay</div>
            <div class="pay-stat__val" style="color:#f59e0b;">₱<?php echo number_format($payslip_stats['total_overtime'] ?? 0, 2); ?></div>
            <div class="pay-stat__sub">Total overtime</div>
        </div>
    </div>
</div>

<!-- ─── QUICK ACTIONS ─────────────────────────────────────────────────────── -->
<div class="rpt-actions-row no-print">
    <div class="rpt-action-card">
        <div class="rpt-action-card__icon" style="background:#d1fae5;"><i class="fas fa-file-invoice-dollar" style="color:#10b981;font-size:22px;"></i></div>
        <h5>Generate Payslips</h5>
        <p>Create payslips for staff who haven't received one yet this period</p>
        <a href="generate-payslip.php?month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
           class="db-btn" style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;">
            <i class="fas fa-plus"></i> Generate
        </a>
    </div>
    <div class="rpt-action-card">
        <div class="rpt-action-card__icon" style="background:#e0f2fe;"><i class="fas fa-list-alt" style="color:#0ea5e9;font-size:22px;"></i></div>
        <h5>View All Payslips</h5>
        <p>Access and manage all generated payslips for the selected period</p>
        <a href="payslip-list.php?month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
           class="db-btn db-btn--ghost">
            <i class="fas fa-eye"></i> View List
        </a>
    </div>
    <div class="rpt-action-card">
        <div class="rpt-action-card__icon" style="background:#fef3c7;"><i class="fas fa-file-excel" style="color:#f59e0b;font-size:22px;"></i></div>
        <h5>Export Report</h5>
        <p>Download attendance and payroll data for external use or archiving</p>
        <button onclick="exportToExcel()" class="db-btn db-btn--ghost">
            <i class="fas fa-download"></i> Export Excel
        </button>
    </div>
</div>

<!-- ─── CHARTS ROW ────────────────────────────────────────────────────────── -->
<div class="rpt-chart-grid">
    <!-- Status Doughnut -->
    <div class="db-panel" style="margin-bottom:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-chart-pie"></i></span>
                <h2>Status Distribution</h2>
            </div>
        </div>
        <div style="padding:16px;display:flex;align-items:center;justify-content:center;min-height:260px;">
            <?php if (array_sum($status_distribution) > 0): ?>
                <canvas id="statusChart" style="max-height:260px;"></canvas>
            <?php else: ?>
                <div style="text-align:center;color:#94a3b8;">
                    <i class="fas fa-chart-pie" style="font-size:36px;color:#e2e8f0;display:block;margin-bottom:8px;"></i>
                    No data available
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Daily Trend Bar -->
    <div class="db-panel" style="margin-bottom:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-chart-bar"></i></span>
                <h2>Daily Attendance Overview</h2>
            </div>
        </div>
        <div style="padding:16px;">
            <?php if (empty($daily_trend)): ?>
                <div style="text-align:center;color:#94a3b8;padding:48px 0;">
                    <i class="fas fa-chart-bar" style="font-size:36px;color:#e2e8f0;display:block;margin-bottom:8px;"></i>
                    No attendance data for selected period
                </div>
            <?php else: ?>
                <canvas id="attendanceTrendChart" height="90"></canvas>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ─── PERFORMERS + ROLE CHART ───────────────────────────────────────────── -->
<div class="rpt-chart-grid" style="margin-top:18px;">
    <!-- Top Performers -->
    <div class="db-panel" style="margin-bottom:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon" style="background:#fef3c7;color:#f59e0b;"><i class="fas fa-trophy"></i></span>
                <h2>Top Performers</h2>
            </div>
        </div>
        <div style="padding:0 16px 16px;">
            <?php if (empty($top_performers)): ?>
                <div style="text-align:center;color:#94a3b8;padding:36px 0;">
                    <i class="fas fa-inbox" style="font-size:28px;color:#e2e8f0;display:block;margin-bottom:8px;"></i>
                    No data available
                </div>
            <?php else: ?>
                <?php foreach ($top_performers as $idx => $p): ?>
                <div class="perf-item">
                    <div class="perf-rank <?php echo $idx === 0 ? 'perf-rank--1' : ($idx === 1 ? 'perf-rank--2' : 'perf-rank--n'); ?>">
                        <?php echo $idx === 0 ? '<i class="fas fa-trophy"></i>' : ($idx + 1); ?>
                    </div>
                    <div class="staff-avatar" style="flex-shrink:0;">
                        <?php if (!empty($p['profile_photo']) && file_exists('../../../uploads/profiles/' . $p['profile_photo'])): ?>
                            <img src="../../../uploads/profiles/<?php echo htmlspecialchars($p['profile_photo']); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($p['full_name'], 0, 1)); ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="perf-name"><?php echo htmlspecialchars($p['full_name']); ?></div>
                        <div class="perf-role"><?php echo htmlspecialchars($p['role']); ?></div>
                    </div>
                    <div style="margin-left:auto;text-align:right;">
                        <div class="perf-rate"><?php echo $p['rate']; ?>%</div>
                        <div class="perf-days"><?php echo $p['attended_days']; ?>/<?php echo $p['total_days']; ?> days</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Role chart -->
    <div class="db-panel" style="margin-bottom:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-users-cog"></i></span>
                <h2>Attendance Rate by Role</h2>
            </div>
        </div>
        <div style="padding:16px;">
            <canvas id="roleChart" height="80"></canvas>
        </div>
    </div>
</div>

<!-- ─── PAYSLIP GENERATION PROGRESS ──────────────────────────────────────── -->
<div class="db-panel" style="margin-top:18px;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-chart-line"></i></span>
            <h2>Payslip Generation Progress</h2>
        </div>
        <span style="font-size:12px;font-weight:700;color:#10b981;font-family:'DM Mono',monospace;">
            <?php echo $generated; ?> / <?php echo $total_staff; ?> Complete
        </span>
    </div>
    <div style="padding:0 20px 20px;">
        <div class="gen-progress-row">
            <div>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                    <span style="font-size:12px;color:#64748b;font-weight:600;">Generation Progress</span>
                    <span style="font-size:12px;font-weight:800;font-family:'DM Mono',monospace;color:#0f172a;">
                        <?php echo number_format($progress_percent, 1); ?>%
                    </span>
                </div>
                <div class="rpt-bar">
                    <div class="rpt-bar__fill" style="width:<?php echo $progress_percent; ?>%;background:linear-gradient(90deg,#059669,#10b981);">
                        <strong><?php echo number_format($progress_percent, 1); ?>% Complete</strong>
                    </div>
                </div>
                <div class="gen-counter-grid">
                    <div class="gen-counter">
                        <div class="gen-counter__num" style="color:#10b981;"><?php echo $generated; ?></div>
                        <div class="gen-counter__lbl">Generated</div>
                    </div>
                    <div class="gen-counter">
                        <div class="gen-counter__num" style="color:#f59e0b;"><?php echo $pending_count; ?></div>
                        <div class="gen-counter__lbl">Pending</div>
                    </div>
                    <div class="gen-counter">
                        <div class="gen-counter__num" style="color:#3b82f6;"><?php echo $total_staff; ?></div>
                        <div class="gen-counter__lbl">Total Staff</div>
                    </div>
                </div>
            </div>
            <div style="width:170px;flex-shrink:0;">
                <canvas id="payslipProgressChart" width="170" height="170"></canvas>
            </div>
        </div>

        <?php if ($pending_count > 0): ?>
        <div class="rpt-pending-alert no-print">
            <i class="fas fa-exclamation-triangle rpt-pending-alert__icon"></i>
            <div style="flex:1;">
                <h6>Pending Payslips</h6>
                <p><strong><?php echo $pending_count; ?></strong> staff member(s) haven't received their payslip for this period.</p>
            </div>
            <a href="generate-payslip.php?month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
               class="db-btn" style="background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;border:none;white-space:nowrap;">
                <i class="fas fa-plus"></i> Generate Now
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── STAFF TABLE ───────────────────────────────────────────────────────── -->
<div class="db-panel" style="margin-top:18px;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-users"></i></span>
            <h2>Staff Attendance &amp; Payslip Summary</h2>
        </div>
        <div class="db-panel__actions">
            <a href="generate-payslip.php?month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
               class="db-btn db-btn--sm no-print" style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;">
                <i class="fas fa-plus"></i> Generate Payslip
            </a>
            <a href="payslip-list.php" class="db-btn db-btn--ghost db-btn--sm no-print">
                <i class="fas fa-list"></i> All Payslips
            </a>
        </div>
    </div>

    <div class="db-table-wrap">
        <input id="rpt-search" type="text" placeholder="&#xf002;  Search staff name or role…" class="no-print">

        <table class="db-table" id="staffTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th class="text-center">Present</th>
                    <th class="text-center">Late</th>
                    <th class="text-center">Absent</th>
                    <th class="text-center">Leave</th>
                    <th class="text-center">Total Hours</th>
                    <th>Rate</th>
                    <th class="text-center">Payslip</th>
                    <th class="text-center no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($user_summary)): ?>
                <tr>
                    <td colspan="10">
                        <div style="display:flex;flex-direction:column;align-items:center;padding:48px 24px;gap:8px;text-align:center;">
                            <i class="fas fa-inbox" style="font-size:36px;color:#e2e8f0;"></i>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">No data available for selected period</p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php
                $rolePillMap = [
                    'Admin'  => 'role-pill--admin',
                    'Staff'  => 'role-pill--staff',
                    'Tanod'  => 'role-pill--tanod',
                    'Driver' => 'role-pill--driver',
                ];
                foreach ($user_summary as $u):
                    $displayName = trim($u['full_name'] ?? '') ?: $u['username'];
                    $initials    = strtoupper(substr($displayName, 0, 1));
                    $rp          = $rolePillMap[$u['role']] ?? 'role-pill--staff';
                    $pct         = (float)$u['attendance_percentage'];
                    $barClass    = $pct >= 90 ? 'background:linear-gradient(90deg,#059669,#10b981)' : ($pct >= 75 ? 'background:linear-gradient(90deg,#d97706,#f59e0b)' : 'background:linear-gradient(90deg,#dc2626,#ef4444)');
                ?>
                <tr>
                    <td>
                        <div class="staff-info">
                          <div class="staff-avatar">
    <?php if (!empty($u['profile_photo']) && file_exists('../../../uploads/profiles/' . $u['profile_photo'])): ?>
        <img src="../../../uploads/profiles/<?php echo htmlspecialchars($u['profile_photo']); ?>" alt="">
    <?php else: ?>
        <?php echo $initials; ?>
    <?php endif; ?>
</div>
                            <div>
                                <div class="staff-name"><?php echo htmlspecialchars($displayName); ?></div>
                                <div style="font-size:11px;color:#94a3b8;font-family:'DM Mono',monospace;">#<?php echo str_pad($u['user_id'],4,'0',STR_PAD_LEFT); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="role-pill <?php echo $rp; ?>"><?php echo htmlspecialchars($u['role']); ?></span></td>
                    <td style="text-align:center;"><span class="att-badge att-badge--present"><?php echo $u['present_days']; ?></span></td>
                    <td style="text-align:center;"><span class="att-badge att-badge--late"><?php echo $u['late_days']; ?></span></td>
                    <td style="text-align:center;"><span class="att-badge att-badge--absent"><?php echo $u['absent_days']; ?></span></td>
                    <td style="text-align:center;"><span class="att-badge att-badge--leave"><?php echo $u['leave_days']; ?></span></td>
                    <td style="text-align:center;">
                        <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:600;color:#475569;">
                            <?php echo floor($u['total_minutes']/60).'h '.($u['total_minutes']%60).'m'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="rpt-bar-wrap">
                            <div class="rpt-bar">
                                <div class="rpt-bar__fill" style="width:<?php echo $pct; ?>%;<?php echo $barClass; ?>">
                                    <strong><?php echo number_format($pct,1); ?>%</strong>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($u['payslip_generated'] > 0): ?>
                            <span class="att-badge att-badge--done"><i class="fas fa-check-circle"></i> Generated</span>
                        <?php else: ?>
                            <span class="att-badge att-badge--pending"><i class="fas fa-clock"></i> Pending</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;" class="no-print">
                        <div class="btn-group btn-group-sm" role="group">
                            <?php if ($u['payslip_generated'] > 0): ?>
                                <a href="payslip-list.php?user_id=<?php echo $u['user_id']; ?>&month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
                                   class="db-btn db-btn--ghost db-btn--sm" title="View Payslip">
                                    <i class="fas fa-eye"></i>
                                </a>
                            <?php else: ?>
                                <a href="generate-payslip.php?user_id=<?php echo $u['user_id']; ?>&month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
                                   class="db-btn db-btn--sm" title="Generate Payslip"
                                   style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </a>
                            <?php endif; ?>
                            <a href="index.php?user_id=<?php echo $u['user_id']; ?>&month=<?php echo $selected_year.'-'.str_pad($selected_month,2,'0',STR_PAD_LEFT); ?>"
                               class="db-btn db-btn--ghost db-btn--sm" title="View Details">
                                <i class="fas fa-info-circle"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ─── CHARTS JS ──────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const chartColors = { present:'#10b981', late:'#f59e0b', absent:'#e11d48', leave:'#6366f1' };

<?php if (array_sum($status_distribution) > 0): ?>
new Chart(document.getElementById('statusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Present','Late','Absent','On Leave'],
        datasets: [{
            data: [<?php echo $status_distribution['present']; ?>,<?php echo $status_distribution['late']; ?>,<?php echo $status_distribution['absent']; ?>,<?php echo $status_distribution['leave']; ?>],
            backgroundColor: [chartColors.present, chartColors.late, chartColors.absent, chartColors.leave],
            borderWidth: 3, borderColor: '#fff', hoverBorderWidth: 3
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '65%',
        plugins: {
            legend: { position: 'bottom', labels: { padding: 14, font: { size: 12, family: "'Sora',sans-serif" }, usePointStyle: true } },
            tooltip: { callbacks: { label: ctx => { const t = ctx.dataset.data.reduce((a,b)=>a+b,0); return ctx.label+': '+ctx.parsed+' ('+((ctx.parsed/t)*100).toFixed(1)+'%)'; } } }
        }
    }
});
<?php endif; ?>

<?php if (!empty($daily_trend)): ?>
const trendData = <?php echo json_encode($daily_trend); ?>;
new Chart(document.getElementById('attendanceTrendChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: trendData.map(d => { const dt = new Date(d.attendance_date); return dt.toLocaleDateString('en-US',{month:'short',day:'numeric'}); }),
        datasets: [
            { label:'Present', data: trendData.map(d=>d.present), backgroundColor: chartColors.present, borderRadius: 5 },
            { label:'Late',    data: trendData.map(d=>d.late),    backgroundColor: chartColors.late,    borderRadius: 5 },
            { label:'Absent',  data: trendData.map(d=>d.absent),  backgroundColor: chartColors.absent,  borderRadius: 5 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        scales: {
            x: { stacked: true, grid: { display: false } },
            y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
        },
        plugins: {
            legend: { position:'top', labels: { usePointStyle:true, padding:14, font:{ family:"'Sora',sans-serif", size:12 } } },
            tooltip: { mode:'index', intersect:false }
        }
    }
});
<?php endif; ?>

new Chart(document.getElementById('roleChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($role_labels); ?>,
        datasets: [{
            label: 'Average Attendance Rate (%)',
            data: <?php echo json_encode($role_percentages); ?>,
            backgroundColor: ['rgba(16,185,129,.8)','rgba(245,158,11,.8)','rgba(99,102,241,.8)','rgba(14,165,233,.8)','rgba(239,68,68,.8)'],
            borderColor:     ['#10b981','#f59e0b','#6366f1','#0ea5e9','#ef4444'],
            borderWidth: 2, borderRadius: 8
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: true,
        scales: {
            x: { beginAtZero:true, max:100, ticks:{ callback: v => v+'%' } }
        },
        plugins: {
            legend: { display:false },
            tooltip: { callbacks:{ label: ctx => ctx.parsed.x.toFixed(1)+'%' } }
        }
    }
});

new Chart(document.getElementById('payslipProgressChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Generated','Pending'],
        datasets: [{
            data: [<?php echo $generated; ?>, <?php echo $pending_count; ?>],
            backgroundColor: ['rgba(16,185,129,.85)','rgba(245,158,11,.85)'],
            borderWidth: 3, borderColor: '#fff'
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true, cutout: '60%',
        plugins: {
            legend: { position:'bottom', labels:{ padding:10, font:{ size:11, family:"'Sora',sans-serif" }, usePointStyle:true } },
            tooltip: { callbacks:{ label: ctx => { const t=ctx.dataset.data.reduce((a,b)=>a+b,0); return ctx.label+': '+ctx.parsed+' ('+((ctx.parsed/t)*100).toFixed(1)+'%)'; } } }
        }
    }
});

function changeDate() {
    const month = document.getElementById('monthSelector').value;
    const year  = document.getElementById('yearSelector').value;
    window.location.href = '?month='+month+'&year='+year;
}

function exportToExcel() {
    const table = document.getElementById('staffTable');
    const month = document.getElementById('monthSelector').value;
    const year  = document.getElementById('yearSelector').value;
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const monthName = months[parseInt(month)-1];
    let html = '<table>';
    html += '<tr><th colspan="10" style="text-align:center;font-size:16px;font-weight:bold;">Attendance & Payslip Report</th></tr>';
    html += '<tr><th colspan="10" style="text-align:center;">Period: '+monthName+' '+year+'</th></tr><tr></tr>';
    table.querySelectorAll('thead th').forEach((th,i) => { if(i<9) html += '<th style="background:#0d1b36;color:#fff;font-weight:bold;">'+th.textContent+'</th>'; });
    html += '</tr>';
    table.querySelectorAll('tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if(cells.length>1){ html+='<tr>'; cells.forEach((td,i)=>{ if(i<9) html+='<td>'+td.textContent.trim()+'</td>'; }); html+='</tr>'; }
    });
    html += '</table>';
    const uri = 'data:application/vnd.ms-excel;base64,';
    const tpl = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="UTF-8"/></head><body>'+html+'</body></html>';
    const a = document.createElement('a');
    a.href = uri + btoa(unescape(encodeURIComponent(tpl)));
    a.download = 'Attendance_Report_'+monthName+'_'+year+'.xls';
    a.click();
}

// Table search
document.getElementById('rpt-search').addEventListener('keyup', function() {
    const f = this.value.toLowerCase();
    document.querySelectorAll('#staffTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(f) ? '' : 'none';
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>