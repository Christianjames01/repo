<?php
/**
 * Staff View Individual Payslip Details
 * modules/attendance/view-my-payslip.php
 * RESTYLED TO MATCH ADMIN ATTENDANCE UI
 */

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /barangaylink1/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();

// Support both ?id=X and ?uid=X&start=Y&end=Z (for when payslip_id=0)
$payslip_id = 0;
$payslip    = null;

$select_cols = "SELECT
            p.payslip_id,
            p.user_id,
            p.pay_period_start,
            p.pay_period_end,
            p.basic_salary,
            p.allowances,
            p.overtime_hours,
            p.overtime_pay,
            p.late_minutes,
            p.late_deductions,
            p.other_deductions,
            p.total_deductions,
            p.gross_pay,
            p.net_pay,
            p.days_present,
            p.days_late,
            p.days_absent,
            p.hourly_rate,
            p.status,
            p.generated_by,
            p.generated_at,
            p.remarks,
            CONCAT(r.first_name, ' ', r.last_name) as staff_name,
            r.profile_photo,
            r.contact_number,
            r.address as staff_address,
            u.role as staff_role,
            CONCAT(cr.first_name, ' ', cr.last_name) as generated_by_name
    FROM tbl_payslips p
    LEFT JOIN tbl_users u ON p.user_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_users cu ON p.generated_by = cu.user_id
    LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id";

if (!empty($_GET['id']) && intval($_GET['id']) > 0) {
    // Normal path: payslip_id is set and non-zero
    $payslip_id = intval($_GET['id']);
    $payslip = fetchOne($conn,
        $select_cols . " WHERE p.payslip_id = ? AND p.user_id = ?",
        [$payslip_id, $current_user_id], 'ii'
    );
} elseif (!empty($_GET['uid']) && !empty($_GET['start']) && !empty($_GET['end'])) {
    // Fallback path: payslip_id=0 in DB, identify by user+period
    $uid   = intval($_GET['uid']);
    $start = $_GET['start'];
    $end   = $_GET['end'];
    if ($uid === $current_user_id) {
        $payslip = fetchOne($conn,
            $select_cols . " WHERE p.user_id = ? AND p.pay_period_start = ? AND p.pay_period_end = ?",
            [$current_user_id, $start, $end], 'iss'
        );
    }
} else {
    redirect('/barangaylink1/modules/attendance/my-payslips.php', 'Invalid payslip ID', 'error');
}

if (!$payslip) {
    redirect('/barangaylink1/modules/attendance/my-payslips.php', 'Payslip not found or access denied', 'error');
}

$payslip_id  = $payslip['payslip_id'] ?? 0;
// Build URL param for print/view links
$print_param = $payslip_id > 0
    ? 'id=' . $payslip_id
    : 'uid=' . $payslip['user_id'] . '&start=' . urlencode($payslip['pay_period_start']) . '&end=' . urlencode($payslip['pay_period_end']);

$page_title = 'Payslip Details - ' . date('F Y', strtotime($payslip['pay_period_start']));

$attendance_records = fetchAll($conn,
    "SELECT attendance_date, status, time_in, time_out, notes
    FROM tbl_attendance
    WHERE user_id = ?
    AND attendance_date BETWEEN ? AND ?
    AND time_in IS NOT NULL
    AND time_out IS NOT NULL
    ORDER BY attendance_date",
    [$current_user_id, $payslip['pay_period_start'], $payslip['pay_period_end']], 'iss'
);

$attendance_details = [];
$total_worked_hours = 0;
foreach ($attendance_records as $record) {
    $time_in  = strtotime($record['time_in']);
    $time_out = strtotime($record['time_out']);
    $worked   = ($time_out - $time_in) / 3600;
    if ($worked > 6) $worked -= 1; // deduct lunch
    $worked = round($worked, 2);
    $total_worked_hours += $worked;
    $attendance_details[] = [
        'date'         => $record['attendance_date'],
        'status'       => $record['status'],
        'time_in'      => $record['time_in'],
        'time_out'     => $record['time_out'],
        'worked_hours' => $worked,
        'notes'        => $record['notes'],
    ];
}

// Avatar helpers
$avatarPalette = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];
$staff_initial  = strtoupper(substr($payslip['staff_name'] ?? '?', 0, 1));
$avatar_bg      = $avatarPalette[ord($staff_initial) % count($avatarPalette)];

$roleColors = [
    'Barangay Captain'=>['bg'=>'#fce7f3','color'=>'#9f1239'],
    'Secretary'       =>['bg'=>'#fef9c3','color'=>'#713f12'],
    'Treasurer'       =>['bg'=>'#e0f2fe','color'=>'#075985'],
    'Staff'           =>['bg'=>'#fef3c7','color'=>'#92400e'],
    'Tanod'           =>['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Barangay Tanod'  =>['bg'=>'#dbeafe','color'=>'#1e40af'],
    'Driver'          =>['bg'=>'#d1fae5','color'=>'#065f46'],
    'Admin'           =>['bg'=>'#fee2e2','color'=>'#991b1b'],
    'Super Admin'     =>['bg'=>'#ede9fe','color'=>'#4c1d95'],
];
$role_rc = $roleColors[$payslip['staff_role'] ?? ''] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<style>
:root {
    --navy-deep:#0d1b36; --navy-mid:#1c3461;
    --green:#10b981; --rose:#e11d48; --amber:#f59e0b;
    --sky:#0ea5e9; --indigo:#6366f1;
    --slate-50:#f8fafc; --slate-100:#f1f5f9;
    --slate-200:#e2e8f0; --slate-400:#94a3b8;
    --slate-600:#475569; --slate-900:#0f172a;
}

/* ── layout grid ─────────────────────────────────────────────────── */
.vp-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 20px;
    align-items: start;
}
@media (max-width: 1024px) { .vp-grid { grid-template-columns: 1fr; } }

/* ── section card ────────────────────────────────────────────────── */
.vp-card {
    background: #fff;
    border: 1px solid var(--slate-200);
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    overflow: hidden;
    margin-bottom: 18px;
}
.vp-card:last-child { margin-bottom: 0; }
.vp-card__header {
    padding: 14px 20px;
    display: flex; align-items: center; gap: 9px;
    border-bottom: 1px solid var(--slate-200);
    font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
}
.vp-card__header--navy {
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    color: #fff; border-bottom: none;
}
.vp-card__header--green  { background: #f0fdf4; color: #065f46; }
.vp-card__header--rose   { background: #fff1f2; color: #9f1239; }
.vp-card__header--blue   { background: #eff6ff; color: #1e40af; }
.vp-card__header--slate  { background: var(--slate-50); color: var(--slate-600); }
.vp-card__body { padding: 20px; }

/* ── employee info strip ─────────────────────────────────────────── */
.vp-emp-strip {
    display: flex; align-items: center; gap: 16px;
}
.vp-emp-avatar {
    width: 60px; height: 60px; border-radius: 14px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; font-weight: 800; color: #fff; overflow: hidden;
    box-shadow: 0 2px 10px rgba(13,27,54,.2);
}
.vp-emp-avatar img { width: 100%; height: 100%; object-fit: cover; }
.vp-emp-name {
    font-family: 'Sora', sans-serif; font-size: 18px;
    font-weight: 800; color: var(--slate-900); margin-bottom: 4px;
}
.vp-role-pill {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 700;
    letter-spacing: .4px;
}
.vp-emp-meta {
    font-size: 12px; color: var(--slate-400); margin-top: 3px;
    font-family: 'DM Mono', monospace;
}
.vp-payslip-id {
    margin-left: auto; text-align: right; flex-shrink: 0;
}
.vp-payslip-id__num {
    font-family: 'DM Mono', monospace; font-size: 20px;
    font-weight: 800; color: var(--navy-mid);
}
.vp-payslip-id__sub {
    font-size: 11px; color: var(--slate-400);
    font-family: 'DM Mono', monospace; margin-top: 2px;
}

/* ── earnings / deductions rows ──────────────────────────────────── */
.vp-line-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 12px 0; border-bottom: 1px solid var(--slate-100);
}
.vp-line-row:last-child { border-bottom: none; }
.vp-line-row.total-row {
    border-top: 2px solid var(--slate-200);
    border-bottom: none; padding-top: 16px; margin-top: 4px;
}
.vp-line-label {
    font-family: 'Sora', sans-serif; font-size: 13px;
    font-weight: 600; color: var(--slate-900);
}
.vp-line-sub {
    font-family: 'DM Mono', monospace; font-size: 11px;
    color: var(--slate-400); margin-top: 2px;
}
.vp-line-amount {
    font-family: 'DM Mono', monospace; font-weight: 800;
    font-size: 15px; white-space: nowrap;
}
.vp-line-amount--earn   { color: var(--navy-mid); }
.vp-line-amount--ot     { color: var(--green); }
.vp-line-amount--deduct { color: var(--rose); }
.vp-line-amount--gross  { color: var(--green); font-size: 20px; }
.vp-line-amount--total-deduct { color: var(--rose); font-size: 18px; }
.vp-line-row.ot-row  { background: #f0fdf4; border-radius: 8px; padding: 10px 12px; margin: 4px -12px; }
.vp-line-row.late-row { background: #fffbeb; border-radius: 8px; padding: 10px 12px; margin: 4px -12px; }

/* ── NET PAY banner ──────────────────────────────────────────────── */
.vp-net-banner {
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    border-radius: 16px; padding: 24px 28px;
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 18px;
    box-shadow: 0 8px 30px rgba(13,27,54,.3);
    position: relative; overflow: hidden;
}
.vp-net-banner::before {
    content: ''; position: absolute; right: -40px; top: -40px;
    width: 180px; height: 180px; border-radius: 50%;
    background: rgba(255,255,255,.05);
}
.vp-net-banner::after {
    content: ''; position: absolute; right: 40px; bottom: -60px;
    width: 140px; height: 140px; border-radius: 50%;
    background: rgba(255,255,255,.04);
}
.vp-net-banner__label {
    font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: rgba(255,255,255,.6); margin-bottom: 4px;
}
.vp-net-banner__title {
    font-family: 'Sora', sans-serif; font-size: 18px;
    font-weight: 800; color: #fff; margin-bottom: 2px;
}
.vp-net-banner__sub {
    font-size: 12px; color: rgba(255,255,255,.5);
    font-family: 'DM Mono', monospace;
}
.vp-net-banner__amount {
    font-family: 'DM Mono', monospace; font-size: 38px;
    font-weight: 900; color: #fff; letter-spacing: -1px;
    position: relative; z-index: 1;
}
.vp-net-banner__amount span {
    font-size: 22px; vertical-align: super; margin-right: 2px;
    font-weight: 700; color: rgba(255,255,255,.7);
}

/* ── info table (right sidebar) ─────────────────────────────────── */
.vp-info-table { width: 100%; border-collapse: collapse; }
.vp-info-table tr td {
    padding: 9px 0; font-size: 12.5px;
    border-bottom: 1px solid var(--slate-100);
    font-family: 'Sora', sans-serif;
}
.vp-info-table tr:last-child td { border-bottom: none; }
.vp-info-table td:first-child { color: var(--slate-400); font-size: 11.5px; }
.vp-info-table td:last-child {
    text-align: right; font-weight: 700; color: var(--slate-900);
    font-family: 'DM Mono', monospace; font-size: 12px;
}

/* ── attendance summary mini-cards ──────────────────────────────── */
.vp-att-mini {
    border-radius: 10px; padding: 12px 14px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 10px;
}
.vp-att-mini:last-child { margin-bottom: 0; }
.vp-att-mini__icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.vp-att-mini__num {
    font-family: 'DM Mono', monospace; font-size: 22px;
    font-weight: 800; line-height: 1;
}
.vp-att-mini__label {
    font-family: 'Sora', sans-serif; font-size: 11px;
    font-weight: 600; text-transform: uppercase; letter-spacing: .4px;
    color: var(--slate-400); margin-top: 2px;
}
.vp-att-mini__sub {
    font-family: 'DM Mono', monospace; font-size: 10.5px;
    color: var(--slate-400); margin-top: 1px;
}
.vp-att-mini--present { background: #f0fdf4; }
.vp-att-mini--late    { background: #fffbeb; }
.vp-att-mini--absent  { background: #fff1f2; }
.vp-att-mini--ot      { background: #eff6ff; }

/* ── att badges ──────────────────────────────────────────────────── */
.att-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10.5px;
    font-weight: 600; white-space: nowrap;
}
.att-badge--present { background: #d1fae5; color: #065f46; }
.att-badge--late    { background: #fef3c7; color: #92400e; }
.att-badge--absent  { background: #fee2e2; color: #7f1d1d; }

.time-in  { color: var(--green); font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 600; }
.time-out { color: var(--rose);  font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 600; }
.hrs-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    background: #dbeafe; color: #1e40af;
    font-family: 'DM Mono', monospace; font-size: 10.5px; font-weight: 700;
}

/* ── help strip ──────────────────────────────────────────────────── */
.vp-help-strip {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 1px solid #bfdbfe; border-radius: 14px;
    padding: 16px 20px; margin-top: 20px;
    display: flex; gap: 14px; align-items: flex-start;
    font-family: 'Sora', sans-serif; font-size: 12.5px; color: #1e40af;
}
.vp-help-strip strong { font-size: 13px; display: block; margin-bottom: 4px; }

/* ── print button ────────────────────────────────────────────────── */
.vp-print-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 22px; border-radius: 9px; border: none;
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    color: #fff; font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 700; cursor: pointer;
    transition: all .18s; text-decoration: none;
}
.vp-print-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(13,27,54,.3);
    color: #fff;
}
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar"><i class="fas fa-file-invoice-dollar" style="font-size:22px;color:#fff;"></i></div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($payslip['staff_role'] ?? 'Staff'); ?>
                </div>
                <h1 class="db-hero__title">Payslip Details</h1>
                <p class="db-hero__sub">
                    Pay period:
                    <?php echo date('F d', strtotime($payslip['pay_period_start'])); ?> –
                    <?php echo date('F d, Y', strtotime($payslip['pay_period_end'])); ?>
                </p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <a href="my-payslips.php" class="db-btn db-btn--ghost">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="print-payslip.php?<?php echo $print_param; ?>"
               target="_blank" class="vp-print-btn">
                <i class="fas fa-print"></i> Print Payslip
            </a>
        </div>
    </div>
</div>

<!-- ─── ALERTS ───────────────────────────────────────────────────────────── -->
<?php if (!empty($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ─── MAIN GRID ──────────────────────────────────────────────────────────── -->
<div class="vp-grid">

    <!-- ── LEFT COLUMN ──────────────────────────────────────────────────── -->
    <div>

        <!-- Employee Info -->
        <div class="vp-card">
            <div class="vp-card__header vp-card__header--navy">
                <i class="fas fa-id-card"></i> Employee Information
            </div>
            <div class="vp-card__body">
                <div class="vp-emp-strip">
                    <div class="vp-emp-avatar" style="background:<?php echo $avatar_bg; ?>;">
                        <?php if (!empty($payslip['profile_photo']) && file_exists('../../uploads/profiles/' . $payslip['profile_photo'])): ?>
                            <img src="../../uploads/profiles/<?php echo htmlspecialchars($payslip['profile_photo']); ?>" alt="">
                        <?php else: ?>
                            <?php echo $staff_initial; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="vp-emp-name"><?php echo htmlspecialchars($payslip['staff_name']); ?></div>
                        <span class="vp-role-pill" style="background:<?php echo $role_rc['bg']; ?>;color:<?php echo $role_rc['color']; ?>;">
                            <?php echo htmlspecialchars($payslip['staff_role'] ?? ''); ?>
                        </span>
                        <?php if ($payslip['contact_number']): ?>
                            <div class="vp-emp-meta"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($payslip['contact_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="vp-payslip-id">
                        <div class="vp-payslip-id__num">#<?php echo str_pad($payslip['payslip_id'], 6, '0', STR_PAD_LEFT); ?></div>
                        <div class="vp-payslip-id__sub">Payslip ID</div>
                        <?php if (!empty($payslip['generated_at'])): ?>
                        <div class="vp-payslip-id__sub" style="margin-top:4px;">
                            Generated <?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Earnings -->
        <div class="vp-card">
            <div class="vp-card__header vp-card__header--green">
                <i class="fas fa-plus-circle"></i> Earnings
            </div>
            <div class="vp-card__body">
                <div class="vp-line-row">
                    <div>
                        <div class="vp-line-label">Basic Salary</div>
                        <div class="vp-line-sub">Monthly base pay</div>
                    </div>
                    <div class="vp-line-amount vp-line-amount--earn">
                        ₱<?php echo number_format($payslip['basic_salary'], 2); ?>
                    </div>
                </div>
                <?php if (!empty($payslip['allowances']) && $payslip['allowances'] > 0): ?>
                <div class="vp-line-row">
                    <div>
                        <div class="vp-line-label">Allowances</div>
                        <div class="vp-line-sub">Transportation, meal, etc.</div>
                    </div>
                    <div class="vp-line-amount vp-line-amount--earn">
                        ₱<?php echo number_format($payslip['allowances'], 2); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($payslip['overtime_hours']) && $payslip['overtime_hours'] > 0): ?>
                <div class="vp-line-row ot-row">
                    <div>
                        <div class="vp-line-label" style="color:var(--green);">
                            <i class="fas fa-moon me-1"></i> Overtime Pay
                        </div>
                        <div class="vp-line-sub">
                            <?php echo number_format($payslip['overtime_hours'], 2); ?> hrs
                            @ ₱<?php echo number_format(($payslip['hourly_rate'] ?? 0) * 1.25, 2); ?>/hr
                        </div>
                    </div>
                    <div class="vp-line-amount vp-line-amount--ot">
                        +₱<?php echo number_format($payslip['overtime_pay'], 2); ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="vp-line-row total-row">
                    <div>
                        <div class="vp-line-label" style="font-size:15px;">Gross Pay</div>
                        <div class="vp-line-sub">Total before deductions</div>
                    </div>
                    <div class="vp-line-amount vp-line-amount--gross">
                        ₱<?php echo number_format($payslip['gross_pay'], 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Deductions -->
        <div class="vp-card">
            <div class="vp-card__header vp-card__header--rose">
                <i class="fas fa-minus-circle"></i> Deductions
            </div>
            <div class="vp-card__body">
                <?php if (!empty($payslip['late_minutes']) && $payslip['late_minutes'] > 0): ?>
                <div class="vp-line-row late-row">
                    <div>
                        <div class="vp-line-label" style="color:var(--amber);">
                            <i class="fas fa-clock me-1"></i> Late Deductions
                        </div>
                        <div class="vp-line-sub">
                            <?php echo $payslip['late_minutes']; ?> minutes late
                            (<?php echo $payslip['days_late']; ?> day<?php echo $payslip['days_late'] != 1 ? 's' : ''; ?>)
                        </div>
                    </div>
                    <div class="vp-line-amount vp-line-amount--deduct">
                        −₱<?php echo number_format($payslip['late_deductions'], 2); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($payslip['other_deductions']) && $payslip['other_deductions'] > 0): ?>
                <div class="vp-line-row">
                    <div>
                        <div class="vp-line-label">Other Deductions</div>
                        <div class="vp-line-sub">SSS, PhilHealth, taxes, etc.</div>
                    </div>
                    <div class="vp-line-amount vp-line-amount--deduct">
                        −₱<?php echo number_format($payslip['other_deductions'], 2); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (empty($payslip['late_minutes']) && empty($payslip['other_deductions'])): ?>
                <div style="text-align:center;padding:18px 0;font-family:'Sora',sans-serif;font-size:13px;color:var(--slate-400);">
                    <i class="fas fa-check-circle" style="color:var(--green);margin-right:6px;"></i>
                    No deductions this pay period
                </div>
                <?php endif; ?>
                <div class="vp-line-row total-row">
                    <div class="vp-line-label" style="font-size:15px;">Total Deductions</div>
                    <div class="vp-line-amount vp-line-amount--total-deduct">
                        −₱<?php echo number_format($payslip['total_deductions'], 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- NET PAY Banner -->
        <div class="vp-net-banner">
            <div>
                <div class="vp-net-banner__label">Take-Home Pay</div>
                <div class="vp-net-banner__title">Net Pay</div>
                <div class="vp-net-banner__sub">
                    <?php echo date('F d', strtotime($payslip['pay_period_start'])); ?> –
                    <?php echo date('F d, Y', strtotime($payslip['pay_period_end'])); ?>
                </div>
            </div>
            <div class="vp-net-banner__amount">
                <span>₱</span><?php echo number_format($payslip['net_pay'], 2); ?>
            </div>
        </div>

    </div><!-- end left -->

    <!-- ── RIGHT COLUMN ─────────────────────────────────────────────────── -->
    <div>

        <!-- Attendance Summary -->
        <div class="vp-card">
            <div class="vp-card__header vp-card__header--blue">
                <i class="fas fa-clipboard-check"></i> Attendance Summary
            </div>
            <div class="vp-card__body" style="padding:16px;">
                <div class="vp-att-mini vp-att-mini--present">
                    <div class="vp-att-mini__icon" style="background:#d1fae5;color:#10b981;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="vp-att-mini__num" style="color:#065f46;"><?php echo $payslip['days_present']; ?></div>
                        <div class="vp-att-mini__label">Days Present</div>
                    </div>
                </div>

                <?php if (!empty($payslip['days_late']) && $payslip['days_late'] > 0): ?>
                <div class="vp-att-mini vp-att-mini--late">
                    <div class="vp-att-mini__icon" style="background:#fef3c7;color:#f59e0b;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="vp-att-mini__num" style="color:#92400e;"><?php echo $payslip['days_late']; ?></div>
                        <div class="vp-att-mini__label">Days Late</div>
                        <div class="vp-att-mini__sub"><?php echo $payslip['late_minutes']; ?> total minutes</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($payslip['days_absent']) && $payslip['days_absent'] > 0): ?>
                <div class="vp-att-mini vp-att-mini--absent">
                    <div class="vp-att-mini__icon" style="background:#fee2e2;color:#e11d48;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div>
                        <div class="vp-att-mini__num" style="color:#7f1d1d;"><?php echo $payslip['days_absent']; ?></div>
                        <div class="vp-att-mini__label">Days Absent</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($payslip['overtime_hours']) && $payslip['overtime_hours'] > 0): ?>
                <div class="vp-att-mini vp-att-mini--ot">
                    <div class="vp-att-mini__icon" style="background:#dbeafe;color:#1e40af;">
                        <i class="fas fa-business-time"></i>
                    </div>
                    <div>
                        <div class="vp-att-mini__num" style="color:#1e40af;"><?php echo number_format($payslip['overtime_hours'], 1); ?></div>
                        <div class="vp-att-mini__label">Overtime Hours</div>
                        <div class="vp-att-mini__sub">Beyond schedule</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($attendance_details)): ?>
                <div class="vp-att-mini" style="background:var(--slate-50);">
                    <div class="vp-att-mini__icon" style="background:var(--slate-100);color:var(--slate-600);">
                        <i class="fas fa-business-time"></i>
                    </div>
                    <div>
                        <div class="vp-att-mini__num" style="color:var(--navy-mid);"><?php echo number_format($total_worked_hours, 1); ?></div>
                        <div class="vp-att-mini__label">Total Hours Worked</div>
                        <div class="vp-att-mini__sub">This pay period</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payslip Info -->
        <div class="vp-card">
            <div class="vp-card__header vp-card__header--slate">
                <i class="fas fa-info-circle"></i> Payslip Information
            </div>
            <div class="vp-card__body" style="padding:16px 20px;">
                <table class="vp-info-table">
                    <tr>
                        <td>Pay Period</td>
                        <td>
                            <?php echo date('M d', strtotime($payslip['pay_period_start'])); ?> –
                            <?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?>
                        </td>
                    </tr>
                    <?php if (!empty($payslip['hourly_rate'])): ?>
                    <tr>
                        <td>Hourly Rate</td>
                        <td>₱<?php echo number_format($payslip['hourly_rate'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Generated By</td>
                        <td><?php echo htmlspecialchars($payslip['generated_by_name'] ?? 'System'); ?></td>
                    </tr>
                    <?php if (!empty($payslip['generated_at'])): ?>
                    <tr>
                        <td>Generated On</td>
                        <td><?php echo date('M d, Y g:i A', strtotime($payslip['generated_at'])); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>Status</td>
                        <td>
                            <?php
                            $sc = ['Draft'=>['bg'=>'#f1f5f9','color'=>'#475569'],
                                   'Approved'=>['bg'=>'#d1fae5','color'=>'#065f46'],
                                   'Paid'=>['bg'=>'#dbeafe','color'=>'#1e40af']];
                            $si = ['Draft'=>'fa-file-alt','Approved'=>'fa-check-circle','Paid'=>'fa-check-double'];
                            $st = $payslip['status'] ?? 'Draft';
                            $sc_c = $sc[$st] ?? $sc['Draft'];
                            $si_c = $si[$st] ?? 'fa-file-alt';
                            echo "<span style='display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;background:{$sc_c['bg']};color:{$sc_c['color']};font-family:\"DM Mono\",monospace;font-size:10.5px;font-weight:700;'>
                                    <i class='fas {$si_c}'></i> $st
                                  </span>";
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Quick print button in sidebar -->
        <a href="print-payslip.php?<?php echo $print_param; ?>"
           target="_blank" class="vp-print-btn" style="width:100%;justify-content:center;">
            <i class="fas fa-print"></i> Print This Payslip
        </a>

    </div><!-- end right -->

</div><!-- end vp-grid -->

<!-- ─── DAILY ATTENDANCE TABLE ────────────────────────────────────────────── -->
<?php if (!empty($attendance_details)): ?>
<div class="db-panel" style="margin-top:20px;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-calendar-check"></i></span>
            <h2>Daily Attendance Record</h2>
        </div>
        <div style="font-family:'DM Mono',monospace;font-size:11px;color:var(--slate-400);">
            <?php echo count($attendance_details); ?> records · <?php echo number_format($total_worked_hours, 1); ?> hrs total
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Status</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th class="text-end">Hours Worked</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendance_details as $d):
                    $bm = ['Present'=>'att-badge--present','Late'=>'att-badge--late','Absent'=>'att-badge--absent'];
                    $im = ['Present'=>'fa-check-circle','Late'=>'fa-clock','Absent'=>'fa-times-circle'];
                    $bc = $bm[$d['status']] ?? 'att-badge--present';
                    $ic = $im[$d['status']] ?? 'fa-circle';
                ?>
                <tr>
                    <td style="font-family:'DM Mono',monospace;font-size:12.5px;font-weight:700;">
                        <?php echo date('M d, Y', strtotime($d['date'])); ?>
                    </td>
                    <td style="font-size:12px;color:var(--slate-400);">
                        <?php echo date('l', strtotime($d['date'])); ?>
                    </td>
                    <td>
                        <span class="att-badge <?php echo $bc; ?>">
                            <i class="fas <?php echo $ic; ?>"></i> <?php echo $d['status']; ?>
                        </span>
                    </td>
                    <td><span class="time-in"><i class="fas fa-sign-in-alt me-1"></i><?php echo date('h:i A', strtotime($d['time_in'])); ?></span></td>
                    <td><span class="time-out"><i class="fas fa-sign-out-alt me-1"></i><?php echo date('h:i A', strtotime($d['time_out'])); ?></span></td>
                    <td class="text-end">
                        <span class="hrs-badge"><?php echo number_format($d['worked_hours'], 2); ?> hrs</span>
                    </td>
                    <td>
                        <?php if (!empty($d['notes'])): ?>
                            <span style="font-size:12px;color:var(--slate-400);"
                                  title="<?php echo htmlspecialchars($d['notes']); ?>">
                                <?php echo htmlspecialchars(substr($d['notes'], 0, 40) . (strlen($d['notes']) > 40 ? '…' : '')); ?>
                            </span>
                        <?php else: ?><span style="color:var(--slate-200);">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ─── HELP STRIP ────────────────────────────────────────────────────────── -->
<div class="vp-help-strip">
    <i class="fas fa-question-circle" style="font-size:20px;flex-shrink:0;margin-top:1px;"></i>
    <div>
        <strong>Need Help?</strong>
        If you have questions about your payslip or notice any discrepancies,
        please contact HR or your administrator. Keep this payslip for your records.
    </div>
</div>

<script>
setTimeout(function(){
    document.querySelectorAll('.db-alert').forEach(function(a){
        a.style.transition='opacity .4s'; a.style.opacity='0';
        setTimeout(function(){try{a.remove();}catch(e){}},400);
    });
},5000);
</script>

<?php include '../../includes/footer.php'; ?>