<?php
/**
 * View Payslip — UI matches index.php
 * modules/attendance/admin/view-payslip.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) {
    redirect('/barangaylink1/modules/auth/login.php', 'Please login to continue', 'error');
}

$user_role       = getCurrentUserRole();
$current_user_id = getCurrentUserId();
$is_admin        = in_array($user_role, ['Admin', 'Super Admin']);

$payslip_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$payslip_id) {
    redirect('/barangaylink1/modules/attendance/admin/payslip-list.php', 'Invalid payslip', 'error');
}

$payslip = fetchOne($conn,
    "SELECT p.*,
            CONCAT(r.first_name,' ',r.last_name) as staff_name,
            u.username, u.role,
            r.profile_photo, r.contact_number, r.address,
            CONCAT(cr.first_name,' ',cr.last_name) as created_by_name
     FROM tbl_payslips p
     LEFT JOIN tbl_users u  ON p.user_id   = u.user_id
     LEFT JOIN tbl_residents r  ON u.resident_id  = r.resident_id
     LEFT JOIN tbl_users cu ON p.generated_by = cu.user_id
     LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
     WHERE p.payslip_id = ?",
    [$payslip_id], 'i'
);

if (!$payslip) {
    redirect('/barangaylink1/modules/attendance/admin/payslip-list.php', 'Payslip not found', 'error');
}

if (!$is_admin && $payslip['user_id'] != $current_user_id) {
    redirect('/barangaylink1/modules/dashboard/index.php', 'Access denied', 'error');
}

$attendance_records = fetchAll($conn,
    "SELECT attendance_date, status, time_in, time_out, notes
     FROM tbl_attendance
     WHERE user_id = ?
       AND attendance_date BETWEEN ? AND ?
       AND time_in  IS NOT NULL
       AND time_out IS NOT NULL
       AND status IN ('Present','Late')
     ORDER BY attendance_date",
    [$payslip['user_id'], $payslip['pay_period_start'], $payslip['pay_period_end']], 'iss'
);

$page_title  = 'Payslip — ' . ($payslip['staff_name'] ?? $payslip['username']);
$period_label = date('F Y', strtotime($payslip['pay_period_start']));
$extra_css   = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════════════════ -->
<style>
/* ── Shared tokens ───────────────────────────────────────────────────────── */
.att-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10.5px;
    font-weight: 600; letter-spacing: .3px; white-space: nowrap;
}
.att-badge--present { background:#d1fae5; color:#065f46; }
.att-badge--late    { background:#fef3c7; color:#92400e; }
.att-badge--absent  { background:#fee2e2; color:#7f1d1d; }

.role-pill {
    display: inline-block; padding: 2px 9px; border-radius: 20px;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: .4px;
}
.role-pill--admin  { background:#fee2e2; color:#991b1b; }
.role-pill--staff  { background:#fef3c7; color:#92400e; }
.role-pill--tanod  { background:#dbeafe; color:#1e40af; }
.role-pill--driver { background:#d1fae5; color:#065f46; }

/* ── Payslip document card ───────────────────────────────────────────────── */
#payslip-document {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(13,27,54,.10);
    max-width: 860px;
    margin: 0 auto 32px;
}

/* ── Document navy header ────────────────────────────────────────────────── */
.ps-header {
    background: linear-gradient(135deg, #0d1b36 0%, #1c3461 100%);
    padding: 20px 28px 18px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.ps-header__org  { display: flex; align-items: center; gap: 14px; }
.ps-header__icon {
    width: 46px; height: 46px; border-radius: 12px;
    background: rgba(255,255,255,.12); display: flex;
    align-items: center; justify-content: center; font-size: 20px; color: #fff; flex-shrink: 0;
}
.ps-header__title  { font-size: 16px; font-weight: 800; color: #fff; line-height: 1.2; }
.ps-header__sub    { font-size: 11px; color: rgba(255,255,255,.55); margin-top: 2px; }
.ps-header__meta   { text-align: right; }
.ps-header__num    { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 700; color: rgba(255,255,255,.9); }
.ps-header__period { font-size: 11px; color: rgba(255,255,255,.5); margin-top: 3px; }

/* ── Employee info row ───────────────────────────────────────────────────── */
.ps-emp {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 28px; border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}
.ps-emp__avatar {
    width: 52px; height: 52px; border-radius: 14px; flex-shrink: 0;
    background: linear-gradient(135deg, #0d1b36, #1c3461);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 800; color: #fff; overflow: hidden;
}
.ps-emp__avatar img { width: 100%; height: 100%; object-fit: cover; }
.ps-emp__name { font-size: 16px; font-weight: 800; color: #0f172a; line-height: 1.2; }
.ps-emp__meta { font-size: 11px; color: #64748b; margin-top: 3px; font-family: 'DM Mono', monospace; }
.ps-emp__right { margin-left: auto; text-align: right; }
.ps-emp__period-lbl { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }
.ps-emp__period-val { font-size: 13px; font-weight: 700; color: #0f172a; font-family: 'DM Mono', monospace; }
.ps-emp__gen { font-size: 11px; color: #94a3b8; margin-top: 3px; }

/* ── Body padding ────────────────────────────────────────────────────────── */
.ps-body { padding: 22px 28px; }

/* ── Section label ───────────────────────────────────────────────────────── */
.ps-section-lbl {
    font-size: 9px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .8px; color: #94a3b8;
    margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
}
.ps-section-lbl::after { content:''; flex:1; height:1px; background:#e2e8f0; }

/* ── Attendance stat boxes ───────────────────────────────────────────────── */
.ps-att-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px;
}
.ps-att-box {
    border-radius: 12px; padding: 12px; text-align: center;
    border: 1px solid transparent;
}
.ps-att-box--present { background:#d1fae5; border-color:#a7f3d0; }
.ps-att-box--late    { background:#fef3c7; border-color:#fde68a; }
.ps-att-box--absent  { background:#fee2e2; border-color:#fca5a5; }
.ps-att-box--ot      { background:#dbeafe; border-color:#bfdbfe; }
.ps-att-box__num {
    font-size: 26px; font-weight: 800; line-height: 1;
    font-family: 'DM Mono', monospace;
}
.ps-att-box__lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }
.ps-att-box--present .ps-att-box__num { color: #059669; }
.ps-att-box--present .ps-att-box__lbl { color: #065f46; }
.ps-att-box--late    .ps-att-box__num { color: #d97706; }
.ps-att-box--late    .ps-att-box__lbl { color: #92400e; }
.ps-att-box--absent  .ps-att-box__num { color: #dc2626; }
.ps-att-box--absent  .ps-att-box__lbl { color: #7f1d1d; }
.ps-att-box--ot      .ps-att-box__num { color: #2563eb; }
.ps-att-box--ot      .ps-att-box__lbl { color: #1e40af; }

/* ── OT info banner ──────────────────────────────────────────────────────── */
.ps-ot-banner {
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px;
    padding: 10px 14px; margin-bottom: 18px;
    font-size: 12px; color: #1e40af; display: flex; align-items: center; gap: 8px;
}

/* ── Earnings / Deductions two-column ────────────────────────────────────── */
.ps-pay-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;
}
@media (max-width: 600px) { .ps-pay-grid { grid-template-columns: 1fr; } }

.ps-pay-table { width: 100%; border-collapse: collapse; }
.ps-pay-table td {
    padding: 7px 4px; font-size: 12px; color: #0f172a;
    border-bottom: 1px solid #f1f5f9;
}
.ps-pay-table td:last-child { text-align: right; font-weight: 700; font-family: 'DM Mono', monospace; }
.ps-pay-table tr.total td {
    padding-top: 10px; border-top: 2px solid #e2e8f0; border-bottom: none;
    font-size: 13px; font-weight: 800;
}
.ps-pay-table .sub { font-size: 10px; color: #94a3b8; display: block; font-weight: 400; font-family: 'Sora', sans-serif; }
.ps-pay-table tr.highlight td { background: #f0fdf4; }
.ps-pay-table tr.highlight-warn td { background: #fffbeb; }

/* ── Net Pay box ─────────────────────────────────────────────────────────── */
.ps-net {
    background: linear-gradient(135deg, #f0fdf4, #d1fae5);
    border: 1.5px solid #6ee7b7; border-radius: 14px;
    padding: 18px 24px; text-align: center; margin-bottom: 20px;
}
.ps-net__lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #065f46; margin-bottom: 6px; }
.ps-net__val { font-size: 36px; font-weight: 800; color: #059669; font-family: 'DM Mono', monospace; line-height: 1; letter-spacing: -1px; }
.ps-net__detail { font-size: 11px; color: #64748b; margin-top: 6px; font-family: 'DM Mono', monospace; }

/* ── Attendance detail table ─────────────────────────────────────────────── */
.ps-att-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.ps-att-table th {
    padding: 8px 10px; background: #f8fafc; font-size: 9px;
    font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
    color: #64748b; border-bottom: 2px solid #e2e8f0; text-align: left;
}
.ps-att-table td {
    padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #0f172a;
}
.ps-att-table tr:last-child td { border-bottom: none; }
.ps-att-table tr:hover td { background: #f8fafc; }
.ps-att-table tfoot td {
    padding: 9px 10px; background: #f8fafc; font-weight: 800;
    border-top: 2px solid #e2e8f0; font-size: 12px;
}
.time-in  { color: #10b981; font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 600; }
.time-out { color: #ef4444; font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 600; }
.ot-pill  {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    background: #dbeafe; color: #1e40af;
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 700;
}

/* ── Divider ─────────────────────────────────────────────────────────────── */
.ps-divider { height: 1px; background: #e2e8f0; margin: 18px 0; }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.ps-footer {
    display: flex; align-items: flex-end; justify-content: space-between;
    padding: 14px 28px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0;
}
.ps-footer__gen { font-size: 11px; color: #94a3b8; }
.ps-footer__gen strong { color: #475569; }
.ps-footer__sig { text-align: center; }
.ps-footer__sig-line {
    width: 140px; border-top: 2px solid #0d1b36;
    padding-top: 5px; font-size: 10px; font-weight: 700;
    color: #0d1b36; text-transform: uppercase; letter-spacing: .5px;
    margin: 0 auto;
}

/* ── Print ───────────────────────────────────────────────────────────────── */
@media print {
    body * { visibility: hidden; }
    #payslip-document, #payslip-document * { visibility: visible; }
    #payslip-document {
        position: absolute; left: 0; top: 0; width: 100%;
        margin: 0; border-radius: 0; box-shadow: none; border: none;
    }
    .no-print, .db-hero { display: none !important; }
    @page { size: A4; margin: 10mm; }
    .ps-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .ps-att-box, .ps-net { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .ps-pay-table tr.highlight td,
    .ps-pay-table tr.highlight-warn td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}

@media screen {
    #payslip-document { max-width: 860px; }
}
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero no-print">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar"><i class="fas fa-file-invoice-dollar" style="font-size:20px;color:#fff;"></i></div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($user_role); ?>
                </div>
                <h1 class="db-hero__title">Payslip Details</h1>
                <p class="db-hero__sub">
                    <?php echo htmlspecialchars($payslip['staff_name'] ?? $payslip['username']); ?>
                    &nbsp;·&nbsp; <?php echo $period_label; ?>
                </p>
            </div>
        </div>
        <div class="db-hero__right">
            <div style="display:flex;gap:8px;">
                <button onclick="window.print()"
                        class="db-btn"
                        style="background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="payslip-list.php" class="db-btn db-btn--ghost"
                   style="border-color:rgba(255,255,255,.25);color:#fff;background:rgba(255,255,255,.1);">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>
</div>

<?php echo displayMessage(); ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PAYSLIP DOCUMENT
     ═════════════════════════════════════════════════════════════════════════ -->
<div id="payslip-document">

    <!-- ── Document header ──────────────────────────────────────────────── -->
    <div class="ps-header">
        <div class="ps-header__org">
            <div class="ps-header__icon"><i class="fas fa-building"></i></div>
            <div>
                <div class="ps-header__title">Barangay Management System</div>
                <div class="ps-header__sub">Official Payslip Document</div>
            </div>
        </div>
        <div class="ps-header__meta">
            <div class="ps-header__num">
                #<?php echo str_pad($payslip['payslip_id'], 6, '0', STR_PAD_LEFT); ?>
            </div>
            <div class="ps-header__period"><?php echo $period_label; ?></div>
        </div>
    </div>

    <!-- ── Employee info ────────────────────────────────────────────────── -->
    <div class="ps-emp">
        <div class="ps-emp__avatar">
            <?php if (!empty($payslip['profile_photo']) && file_exists('../../../uploads/profiles/' . $payslip['profile_photo'])): ?>
                <img src="../../../uploads/profiles/<?php echo htmlspecialchars($payslip['profile_photo']); ?>" alt="">
            <?php else: ?>
                <?php echo strtoupper(substr($payslip['staff_name'] ?? $payslip['username'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="ps-emp__name"><?php echo htmlspecialchars($payslip['staff_name'] ?? $payslip['username']); ?></div>
            <div class="ps-emp__meta">
                <?php
                $rolePillMap = ['Admin'=>'role-pill--admin','Staff'=>'role-pill--staff','Tanod'=>'role-pill--tanod','Barangay Tanod'=>'role-pill--tanod','Driver'=>'role-pill--driver'];
                $rp = $rolePillMap[$payslip['role']] ?? 'role-pill--staff';
                ?>
                <span class="role-pill <?php echo $rp; ?>" style="margin-right:6px;"><?php echo htmlspecialchars($payslip['role']); ?></span>
                <?php if ($payslip['contact_number']): ?>
                    <i class="fas fa-phone" style="font-size:9px;margin-right:3px;"></i><?php echo htmlspecialchars($payslip['contact_number']); ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="ps-emp__right">
            <div class="ps-emp__period-lbl">Pay Period</div>
            <div class="ps-emp__period-val">
                <?php echo date('M d', strtotime($payslip['pay_period_start'])); ?> –
                <?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?>
            </div>
            <div class="ps-emp__gen">
                Generated <?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?>
            </div>
        </div>
    </div>

    <!-- ── Body ─────────────────────────────────────────────────────────── -->
    <div class="ps-body">

        <!-- Attendance summary -->
        <div class="ps-section-lbl"><i class="fas fa-calendar-check"></i> Attendance Summary</div>
        <div class="ps-att-grid">
            <div class="ps-att-box ps-att-box--present">
                <div class="ps-att-box__num"><?php echo $payslip['days_present']; ?></div>
                <div class="ps-att-box__lbl">Present</div>
            </div>
            <div class="ps-att-box ps-att-box--late">
                <div class="ps-att-box__num"><?php echo $payslip['days_late']; ?></div>
                <div class="ps-att-box__lbl">Late</div>
            </div>
            <div class="ps-att-box ps-att-box--absent">
                <div class="ps-att-box__num"><?php echo $payslip['days_absent']; ?></div>
                <div class="ps-att-box__lbl">Absent</div>
            </div>
            <div class="ps-att-box ps-att-box--ot">
                <div class="ps-att-box__num"><?php echo number_format($payslip['overtime_hours'], 1); ?></div>
                <div class="ps-att-box__lbl">OT Hours</div>
            </div>
        </div>

        <?php if ($payslip['overtime_hours'] > 0): ?>
        <div class="ps-ot-banner">
            <i class="fas fa-business-time"></i>
            <span>
                <strong>Overtime:</strong>
                <?php echo number_format($payslip['overtime_hours'], 2); ?> hrs
                × ₱<?php echo number_format($payslip['hourly_rate'], 2); ?>/hr × 1.25
                = <strong>₱<?php echo number_format($payslip['overtime_pay'], 2); ?></strong>
            </span>
        </div>
        <?php endif; ?>

        <!-- Earnings & Deductions -->
        <div class="ps-section-lbl"><i class="fas fa-peso-sign"></i> Pay Breakdown</div>
        <div class="ps-pay-grid">

            <!-- Earnings -->
            <div>
                <div style="font-size:11px;font-weight:700;color:#059669;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-plus-circle"></i> Earnings
                </div>
                <table class="ps-pay-table">
                    <tr>
                        <td>Basic Salary</td>
                        <td>₱<?php echo number_format($payslip['basic_salary'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Allowances</td>
                        <td>₱<?php echo number_format($payslip['allowances'], 2); ?></td>
                    </tr>
                    <?php if ($payslip['overtime_hours'] > 0): ?>
                    <tr class="highlight">
                        <td>
                            Overtime Pay
                            <span class="sub"><?php echo number_format($payslip['overtime_hours'], 2); ?> hrs × ₱<?php echo number_format($payslip['hourly_rate'], 2); ?> × 1.25</span>
                        </td>
                        <td style="color:#059669;">₱<?php echo number_format($payslip['overtime_pay'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total">
                        <td>Gross Pay</td>
                        <td style="color:#059669;">₱<?php echo number_format($payslip['gross_pay'], 2); ?></td>
                    </tr>
                </table>
            </div>

            <!-- Deductions -->
            <div>
                <div style="font-size:11px;font-weight:700;color:#dc2626;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-minus-circle"></i> Deductions
                </div>
                <table class="ps-pay-table">
                    <?php if ($payslip['late_minutes'] > 0): ?>
                    <tr class="highlight-warn">
                        <td>
                            Late Deductions
                            <span class="sub"><?php echo $payslip['late_minutes']; ?> mins × ₱<?php echo number_format($payslip['late_deductions'] / max($payslip['late_minutes'], 1), 2); ?>/min</span>
                        </td>
                        <td style="color:#d97706;">-₱<?php echo number_format($payslip['late_deductions'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td>
                            Other Deductions
                            <span class="sub">SSS, PhilHealth, etc.</span>
                        </td>
                        <td>₱<?php echo number_format($payslip['other_deductions'], 2); ?></td>
                    </tr>
                    <tr class="total">
                        <td>Total Deductions</td>
                        <td style="color:#dc2626;">₱<?php echo number_format($payslip['total_deductions'], 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Net Pay -->
        <div class="ps-net">
            <div class="ps-net__lbl">Net Pay</div>
            <div class="ps-net__val">₱<?php echo number_format($payslip['net_pay'], 2); ?></div>
            <div class="ps-net__detail">
                Gross ₱<?php echo number_format($payslip['gross_pay'], 2); ?>
                &nbsp;–&nbsp;
                Deductions ₱<?php echo number_format($payslip['total_deductions'], 2); ?>
            </div>
        </div>

        <!-- Attendance detail table (admin only) -->
        <?php if ($is_admin && count($attendance_records) > 0): ?>
        <div class="ps-section-lbl"><i class="fas fa-list-alt"></i> Working Days (<?php echo count($attendance_records); ?> days)</div>
        <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
            <table class="ps-att-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th style="text-align:center;">Worked</th>
                        <th style="text-align:center;">OT</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total_ot_displayed = 0;
                $total_worked_hours = 0;
                foreach ($attendance_records as $record):
                    $worked_hours = $record_ot = 0;
                    if ($record['time_in'] && $record['time_out']) {
                        $worked_seconds = strtotime($record['time_out']) - strtotime($record['time_in']);
                        $worked_hours   = $worked_seconds / 3600;
                        if ($worked_hours > 6) $worked_hours -= 1;
                        $total_worked_hours += $worked_hours;

                        $day_of_week = date('l', strtotime($record['attendance_date']));
                        $schedule = fetchOne($conn,
                            "SELECT time_in, time_out FROM tbl_special_duty_schedules WHERE user_id=? AND schedule_date=?",
                            [$payslip['user_id'], $record['attendance_date']], 'is'
                        );
                        if (!$schedule) {
                            $schedule = fetchOne($conn,
                                "SELECT ss.custom_time_in as time_in, ss.custom_time_out as time_out
                                 FROM tbl_special_schedules ss
                                 INNER JOIN tbl_special_schedule_assignments ssa ON ss.schedule_id=ssa.schedule_id
                                 WHERE ssa.user_id=? AND ss.schedule_date=? AND ss.is_working_day=1",
                                [$payslip['user_id'], $record['attendance_date']], 'is'
                            );
                        }
                        if (!$schedule) {
                            $schedule = fetchOne($conn,
                                "SELECT time_in, time_out FROM tbl_duty_schedules WHERE user_id=? AND day_of_week=? AND is_active=1",
                                [$payslip['user_id'], $day_of_week], 'is'
                            );
                        }
                        if ($schedule && $schedule['time_in'] && $schedule['time_out']) {
                            $sched_hours = (strtotime($record['attendance_date'].' '.$schedule['time_out']) - strtotime($record['attendance_date'].' '.$schedule['time_in'])) / 3600;
                            if ($sched_hours > 6) $sched_hours -= 1;
                            if ($worked_hours > $sched_hours) {
                                $record_ot = $worked_hours - $sched_hours;
                                $total_ot_displayed += $record_ot;
                            }
                        }
                    }
                    $statusBadgeMap = ['Present'=>'att-badge--present','Late'=>'att-badge--late','Absent'=>'att-badge--absent'];
                    $statusIconMap  = ['Present'=>'fa-check-circle','Late'=>'fa-clock','Absent'=>'fa-times-circle'];
                    $bc = $statusBadgeMap[$record['status']] ?? 'att-badge--present';
                    $ic = $statusIconMap[$record['status']]  ?? 'fa-circle';
                ?>
                <tr>
                    <td>
                        <strong style="font-family:'DM Mono',monospace;"><?php echo date('M d', strtotime($record['attendance_date'])); ?></strong>
                        <span style="font-size:10px;color:#94a3b8;margin-left:4px;"><?php echo date('D', strtotime($record['attendance_date'])); ?></span>
                    </td>
                    <td><span class="att-badge <?php echo $bc; ?>"><i class="fas <?php echo $ic; ?>"></i> <?php echo $record['status']; ?></span></td>
                    <td><span class="time-in"><i class="fas fa-sign-in-alt" style="font-size:9px;margin-right:2px;"></i><?php echo date('h:i A', strtotime($record['time_in'])); ?></span></td>
                    <td><span class="time-out"><i class="fas fa-sign-out-alt" style="font-size:9px;margin-right:2px;"></i><?php echo date('h:i A', strtotime($record['time_out'])); ?></span></td>
                    <td style="text-align:center;">
                        <span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:#475569;">
                            <?php echo number_format($worked_hours, 1); ?>h
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($record_ot > 0): ?>
                            <span class="ot-pill"><?php echo number_format($record_ot, 1); ?>h</span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align:right;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                            Totals
                        </td>
                        <td style="text-align:center;">
                            <span style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#3b82f6;">
                                <?php echo number_format($total_worked_hours, 1); ?>h
                            </span>
                        </td>
                        <td style="text-align:center;">
                            <span class="ot-pill" style="font-size:11px;">
                                <?php echo number_format($total_ot_displayed, 1); ?>h
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="font-size:10.5px;color:#94a3b8;margin-top:8px;display:flex;align-items:center;gap:5px;">
            <i class="fas fa-info-circle" style="color:#6366f1;"></i>
            Showing only days with complete time in/out records. Absent days and incomplete records are excluded.
        </div>
        <?php endif; ?>

    </div><!-- /ps-body -->

    <!-- ── Document footer ──────────────────────────────────────────────── -->
    <div class="ps-footer">
        <div class="ps-footer__gen">
            Generated by <strong><?php echo htmlspecialchars($payslip['created_by_name']); ?></strong><br>
            <span style="font-family:'DM Mono',monospace;"><?php echo date('M d, Y h:i A', strtotime($payslip['generated_at'])); ?></span><br>
            <span style="font-size:10px;">This is a computer-generated payslip. For discrepancies, contact HR.</span>
        </div>
        <div class="ps-footer__sig">
            <div class="ps-footer__sig-line">Authorized Signature</div>
        </div>
    </div>

</div><!-- /#payslip-document -->

<?php include '../../../includes/footer.php'; ?>