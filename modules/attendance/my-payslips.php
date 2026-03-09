<?php
/**
 * Staff My Payslips View
 * modules/attendance/my-payslips.php
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

$page_title = 'My Payslips';
$current_user_id = getCurrentUserId();

$year  = isset($_GET['year'])  ? intval($_GET['year'])  : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 0;

$sql = "SELECT 
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
               CONCAT(r.first_name, ' ', r.last_name) as generated_by_name
        FROM tbl_payslips p
        LEFT JOIN tbl_users u ON p.generated_by = u.user_id
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE p.user_id = ?";
$params = [$current_user_id];
$types  = 'i';

if ($year > 0)  { $sql .= " AND YEAR(p.pay_period_start) = ?";  $params[] = $year;  $types .= 'i'; }
if ($month > 0) { $sql .= " AND MONTH(p.pay_period_start) = ?"; $params[] = $month; $types .= 'i'; }
$sql .= " ORDER BY p.pay_period_start DESC";

$payslips = fetchAll($conn, $sql, $params, $types);



$years_result = fetchAll($conn,
    "SELECT DISTINCT YEAR(pay_period_start) as year FROM tbl_payslips WHERE user_id = ? ORDER BY year DESC",
    [$current_user_id], 'i'
);

$ytd = fetchOne($conn,
    "SELECT COUNT(*) as total_payslips, SUM(gross_pay) as total_gross, SUM(net_pay) as total_net,
            SUM(overtime_pay) as total_overtime, SUM(late_deductions) as total_late_deductions,
            SUM(overtime_hours) as total_overtime_hours
     FROM tbl_payslips WHERE user_id = ? AND YEAR(pay_period_start) = ?",
    [$current_user_id, $year], 'ii'
);

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

/* ── filter card ─────────────────────────────────────────────────── */
.ps-filter-card {
    background:#fff; border:1px solid var(--slate-200); border-radius:14px;
    box-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    padding:18px 22px; margin-bottom:20px;
}
.ps-filter-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
.ps-filter-group { display:flex; flex-direction:column; gap:5px; flex:1; min-width:140px; }
.ps-filter-group label {
    font-size:11.5px; font-weight:700; color:var(--slate-600);
    text-transform:uppercase; letter-spacing:.5px;
    font-family:'Sora',sans-serif;
}
.ps-input {
    width:100%; padding:9px 13px; border:1.5px solid var(--slate-200); border-radius:8px;
    font-family:'Sora',sans-serif; font-size:13px; color:var(--slate-900);
    background:var(--slate-50); outline:none; transition:all .18s; appearance:none;
}
.ps-input:focus { border-color:var(--navy-mid); box-shadow:0 0 0 3px rgba(28,52,97,.1); background:#fff; }

/* ── money values ────────────────────────────────────────────────── */
.ps-amount { font-family:'DM Mono',monospace; font-weight:700; }
.ps-amount--gross   { color:var(--green); }
.ps-amount--net     { color:var(--sky); font-size:15px; }
.ps-amount--basic   { color:var(--navy-mid); }
.ps-amount--ot      { color:var(--green); }
.ps-amount--deduct  { color:var(--rose); }
.ps-amount--muted   { color:var(--slate-400); }
.ps-sub { font-size:10.5px; color:var(--slate-400); font-family:'DM Mono',monospace; margin-top:1px; }

/* ── status badges ───────────────────────────────────────────────── */
.ps-status {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px;
    font-family:'DM Mono',monospace; font-size:10.5px; font-weight:700;
    white-space:nowrap;
}
.ps-status--draft    { background:var(--slate-100); color:var(--slate-600); }
.ps-status--approved { background:#d1fae5; color:#065f46; }
.ps-status--paid     { background:#dbeafe; color:#1e40af; }

/* ── period display ──────────────────────────────────────────────── */
.ps-period { font-family:'DM Mono',monospace; font-size:11px; color:var(--slate-400); margin-top:1px; }
.ps-period-main { font-family:'Sora',sans-serif; font-size:13px; font-weight:700; color:var(--slate-900); }

/* ── tfoot ───────────────────────────────────────────────────────── */
.db-table tfoot tr td {
    background:var(--slate-50); font-weight:700;
    font-family:'DM Mono',monospace; font-size:12px; border-top:2px solid var(--slate-200);
}

/* ── info box ────────────────────────────────────────────────────── */
.ps-info-box {
    background:linear-gradient(135deg,#eff6ff,#dbeafe);
    border:1px solid #bfdbfe; border-radius:14px;
    padding:18px 22px; margin-top:20px;
    font-family:'Sora',sans-serif; font-size:12.5px; color:#1e40af;
}
.ps-info-box__title {
    font-weight:700; font-size:13px; margin-bottom:10px;
    display:flex; align-items:center; gap:7px;
}
.ps-info-box ul { margin:0; padding-left:18px; line-height:2; }
.ps-info-box li strong { color:var(--navy-mid); }

@media(max-width:768px){ .ps-filter-row{flex-direction:column;} }
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
                    <span class="db-hero__role-dot"></span>Staff
                </div>
                <h1 class="db-hero__title">My Payslips</h1>
                <p class="db-hero__sub">Salary history and payment details for <?php echo $year; ?></p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <a href="my-schedule.php"   class="db-btn db-btn--ghost"><i class="fas fa-calendar-week"></i> My Schedule</a>
            <a href="my-attendance.php" class="db-btn db-btn--ghost"><i class="fas fa-clipboard-list"></i> Attendance</a>
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

<!-- ─── STAT CARDS ────────────────────────────────────────────────────────── -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-file-invoice"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $ytd['total_payslips'] ?? 0; ?></div>
            <div class="db-stat-card__label">Payslips <?php echo $year; ?></div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-money-bill-wave"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;font-size:17px;">₱<?php echo number_format($ytd['total_gross']??0,0); ?></div>
            <div class="db-stat-card__label">Total Gross</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#dbeafe;color:#0ea5e9;"><i class="fas fa-wallet"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#0ea5e9;font-size:17px;">₱<?php echo number_format($ytd['total_net']??0,0); ?></div>
            <div class="db-stat-card__label">Net Pay Received</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#0ea5e9,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo number_format($ytd['total_overtime_hours']??0,1); ?></div>
            <div class="db-stat-card__label">Overtime Hours</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-minus-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#e11d48;font-size:17px;">₱<?php echo number_format($ytd['total_late_deductions']??0,0); ?></div>
            <div class="db-stat-card__label">Late Deductions</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-chart-line"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;font-size:17px;">₱<?php echo number_format($ytd['total_overtime']??0,0); ?></div>
            <div class="db-stat-card__label">Overtime Pay</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
</div>

<!-- ─── FILTER ───────────────────────────────────────────────────────────── -->
<div class="ps-filter-card">
    <form method="GET">
        <div class="ps-filter-row">
            <div class="ps-filter-group">
                <label><i class="fas fa-calendar-alt me-1"></i>Year</label>
                <select name="year" class="ps-input" onchange="this.form.submit()">
                    <?php if (!empty($years_result)):
                        foreach ($years_result as $y): ?>
                        <option value="<?php echo $y['year']; ?>" <?php echo $year==$y['year']?'selected':''; ?>><?php echo $y['year']; ?></option>
                    <?php endforeach; else: ?>
                        <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="ps-filter-group">
                <label><i class="fas fa-calendar me-1"></i>Month</label>
                <select name="month" class="ps-input" onchange="this.form.submit()">
                    <option value="0" <?php echo $month==0?'selected':''; ?>>All Months</option>
                    <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $month==$m?'selected':''; ?>>
                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="ps-filter-group" style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <a href="my-payslips.php" class="db-btn db-btn--ghost" style="white-space:nowrap;">
                    <i class="fas fa-times"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- ─── PAYSLIPS TABLE ────────────────────────────────────────────────────── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-list"></i></span>
            <h2>
                Payslip History
                <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,var(--navy-deep),var(--navy-mid));color:#fff;border-radius:20px;padding:2px 12px;font-size:11px;font-weight:700;font-family:'DM Mono',monospace;margin-left:8px;">
                    <?php echo $month > 0 ? date('F', mktime(0,0,0,$month,1)) . ' ' . $year : $year; ?>
                </span>
            </h2>
        </div>
    </div>
    <div class="db-table-wrap">
        <?php if (!empty($payslips)): ?>
        <table class="db-table">
            <thead>
                <tr>
                    <th>Pay Period</th>
                    <th>From → To</th>
                    <th class="text-end">Basic Salary</th>
                    <th class="text-end">Overtime</th>
                    <th class="text-end">Deductions</th>
                    <th class="text-end">Gross Pay</th>
                    <th class="text-end">Net Pay</th>
                    <th>Status</th>
                    <th width="120" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payslips as $p): ?>
                <tr>
                    <td>
                        <div class="ps-period-main"><?php echo date('M Y', strtotime($p['pay_period_start'])); ?></div>
                    </td>
                    <td>
                        <div class="ps-period">
                            <?php echo date('M d', strtotime($p['pay_period_start'])); ?> →
                            <?php echo date('M d, Y', strtotime($p['pay_period_end'])); ?>
                        </div>
                    </td>
                    <td class="text-end">
                        <span class="ps-amount ps-amount--basic">₱<?php echo number_format($p['basic_salary'],2); ?></span>
                    </td>
                    <td class="text-end">
                        <?php if ($p['overtime_pay'] > 0): ?>
                            <span class="ps-amount ps-amount--ot">+₱<?php echo number_format($p['overtime_pay'],2); ?></span>
                            <div class="ps-sub"><?php echo number_format($p['overtime_hours'],1); ?> hrs</div>
                        <?php else: ?>
                            <span class="ps-amount ps-amount--muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($p['total_deductions'] > 0): ?>
                            <span class="ps-amount ps-amount--deduct">−₱<?php echo number_format($p['total_deductions'],2); ?></span>
                            <?php if ($p['late_deductions'] > 0): ?>
                                <div class="ps-sub">Late: <?php echo $p['late_minutes']; ?> min</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="ps-amount ps-amount--muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <span class="ps-amount ps-amount--gross">₱<?php echo number_format($p['gross_pay'],2); ?></span>
                    </td>
                    <td class="text-end">
                        <span class="ps-amount ps-amount--net">₱<?php echo number_format($p['net_pay'],2); ?></span>
                    </td>
                    <td>
                        <?php
                        $sc = ['Draft'=>'ps-status--draft','Approved'=>'ps-status--approved','Paid'=>'ps-status--paid'];
                        $si = ['Draft'=>'fa-file-alt','Approved'=>'fa-check-circle','Paid'=>'fa-check-double'];
                        $cls = $sc[$p['status']] ?? 'ps-status--draft';
                        $ico = $si[$p['status']] ?? 'fa-file-alt';
                        echo "<span class='ps-status {$cls}'><i class='fas {$ico}'></i> {$p['status']}</span>";
                        ?>
                    </td>
                    <?php
                        // Build a unique identifier - try payslip_id first, fall back to row hash
                        $pid = !empty($p['payslip_id']) ? (int)$p['payslip_id'] : 0;
                        // If payslip_id is 0, encode the pay period and user to identify the record
                        $pid_param = $pid > 0
                            ? 'id=' . $pid
                            : 'uid=' . $p['user_id'] . '&start=' . urlencode($p['pay_period_start']) . '&end=' . urlencode($p['pay_period_end']);
                    ?>
                    <td class="text-center">
                        <a href="view-my-payslip.php?<?php echo $pid_param; ?>"
                           class="btn-mark" style="margin-right:4px;">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <a href="print-payslip.php?<?php echo $pid_param; ?>"
                           target="_blank"
                           style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:7px;border:1.5px solid var(--slate-200);background:#fff;color:var(--slate-600);font-family:'Sora',sans-serif;font-size:11px;font-weight:600;text-decoration:none;transition:all .15s;"
                           onmouseover="this.style.borderColor='#0d1b36';this.style.color='#0d1b36';"
                           onmouseout="this.style.borderColor='var(--slate-200)';this.style.color='var(--slate-600)';">
                            <i class="fas fa-print"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" style="text-align:right;font-family:'Sora',sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--slate-600);">Totals</td>
                    <td class="text-end"><span class="ps-amount ps-amount--basic">₱<?php echo number_format(array_sum(array_column($payslips,'basic_salary')),2); ?></span></td>
                    <td class="text-end"><span class="ps-amount ps-amount--ot">+₱<?php echo number_format(array_sum(array_column($payslips,'overtime_pay')),2); ?></span></td>
                    <td class="text-end"><span class="ps-amount ps-amount--deduct">−₱<?php echo number_format(array_sum(array_column($payslips,'total_deductions')),2); ?></span></td>
                    <td class="text-end"><span class="ps-amount ps-amount--gross">₱<?php echo number_format(array_sum(array_column($payslips,'gross_pay')),2); ?></span></td>
                    <td class="text-end"><span class="ps-amount ps-amount--net">₱<?php echo number_format(array_sum(array_column($payslips,'net_pay')),2); ?></span></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
        <div class="att-empty">
            <i class="fas fa-file-invoice-dollar"></i>
            <p>No payslips found for
                <?php echo $month > 0 ? date('F', mktime(0,0,0,$month,1)) . ' ' . $year : $year; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── INFO BOX ─────────────────────────────────────────────────────────── -->
<div class="ps-info-box">
    <div class="ps-info-box__title"><i class="fas fa-info-circle"></i> About Your Payslips</div>
    <ul>
        <li><strong>Basic Salary</strong> — Your monthly base pay</li>
        <li><strong>Overtime</strong> — Additional pay for hours beyond schedule (1.25× hourly rate)</li>
        <li><strong>Deductions</strong> — Late deductions and other deductions (taxes, SSS, etc.)</li>
        <li><strong>Gross Pay</strong> — Total earnings before deductions</li>
        <li><strong>Net Pay</strong> — Your actual take-home pay</li>
        <li><strong>Status:</strong> <em>Draft</em> = under review · <em>Approved</em> = verified · <em>Paid</em> = payment released</li>
    </ul>
    <p style="margin:8px 0 0;font-size:11.5px;opacity:.8;">
        <i class="fas fa-question-circle me-1"></i>
        Questions about your payslip? Contact HR or your administrator.
    </p>
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