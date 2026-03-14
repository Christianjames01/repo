<?php
/**
 * Payslip List — Restyled to match Dashboard UI
 * modules/attendance/admin/payslip-list.php
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) redirect('/barangaylink1/modules/auth/login.php', 'Please login to continue', 'error');

$user_role       = getCurrentUserRole();
$current_user_id = getCurrentUserId();
$is_admin        = in_array($user_role, ['Admin', 'Super Admin']);
$page_title      = 'Payslip Management';

$selected_month  = isset($_GET['month'])   ? $_GET['month']            : date('Y-m');
$selected_user   = isset($_GET['user_id']) ? intval($_GET['user_id'])  : 0;
$selected_status = isset($_GET['status'])  ? $_GET['status']           : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payslip']) && $is_admin) {
    $payslip_id = intval($_POST['payslip_id']);
    if (executeQuery($conn, "DELETE FROM tbl_payslips WHERE payslip_id = ?", [$payslip_id], 'i')) {
        logActivity($conn, $current_user_id, 'Deleted payslip', 'tbl_payslips', $payslip_id);
        $_SESSION['success_message'] = 'Payslip deleted successfully';
    } else {
        $_SESSION['error_message'] = 'Failed to delete payslip';
    }
    header("Location: payslip-list.php"); exit();
}

if ($is_admin) {
    $where = ["1=1"]; $params = []; $types = '';
    if ($selected_user > 0) { $where[] = "p.user_id = ?"; $params[] = $selected_user; $types .= 'i'; }
    if ($selected_month)    { $where[] = "DATE_FORMAT(p.pay_period_start,'%Y-%m') = ?"; $params[] = $selected_month; $types .= 's'; }
    $wc = implode(' AND ', $where);
    $payslips = fetchAll($conn,
        "SELECT p.*, CONCAT(r.first_name,' ',r.last_name) as staff_name, u.role, r.profile_photo,
                CONCAT(cr.first_name,' ',cr.last_name) as created_by_name
         FROM tbl_payslips p
         LEFT JOIN tbl_users u ON p.user_id=u.user_id
         LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
         LEFT JOIN tbl_users cu ON p.generated_by=cu.user_id
         LEFT JOIN tbl_residents cr ON cu.resident_id=cr.resident_id
         WHERE $wc ORDER BY p.generated_at DESC", $params, $types);
    $staff = fetchAll($conn,
        "SELECT u.user_id, CONCAT(r.first_name,' ',r.last_name) as full_name, u.role
         FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
         WHERE u.is_active=1 AND u.role IN ('Admin','Staff','Tanod','Driver')
         ORDER BY r.last_name, r.first_name");
} else {
    $where = ["p.user_id = ?"]; $params = [$current_user_id]; $types = 'i';
    if ($selected_month) { $where[] = "DATE_FORMAT(p.pay_period_start,'%Y-%m') = ?"; $params[] = $selected_month; $types .= 's'; }
    $wc = implode(' AND ', $where);
    $payslips = fetchAll($conn,
        "SELECT p.*, CONCAT(r.first_name,' ',r.last_name) as staff_name, u.role, r.profile_photo
         FROM tbl_payslips p
         LEFT JOIN tbl_users u ON p.user_id=u.user_id
         LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
         WHERE $wc ORDER BY p.generated_at DESC", $params, $types);
}

$total_payslips   = count($payslips);
$total_gross      = array_sum(array_column($payslips, 'gross_pay'));
$total_deductions = array_sum(array_column($payslips, 'total_deductions'));
$total_net        = array_sum(array_column($payslips, 'net_pay'));

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<style>
    /* ══════════════════════════════════════
   PAYSLIP LIST DARK MODE OVERRIDES
══════════════════════════════════════ */

/* Page header */
body.dark-mode .pl-header__title {
    color: #e2e8f0;
}
body.dark-mode .pl-header__sub {
    color: #94a3b8;
}

/* Filter bar */
body.dark-mode .pl-filter {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .pl-filter__field label {
    color: #94a3b8;
}
body.dark-mode .pl-filter__field select,
body.dark-mode .pl-filter__field input[type="month"] {
    background: #334155;
    color: #e2e8f0;
    border-color: #475569;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2394a3b8'/%3E%3C/svg%3E");
}
body.dark-mode .pl-filter__field select:focus,
body.dark-mode .pl-filter__field input[type="month"]:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15);
}

/* Stat cards */
body.dark-mode .pl-stat {
    background: #1e293b;
    border-color: #334155;
}
body.dark-mode .pl-stat__label {
    color: #94a3b8;
}

/* Table money values */
body.dark-mode .pl-money-pos {
    color: #34d399;
}
body.dark-mode .pl-money-neg {
    color: #fca5a5;
}
body.dark-mode .pl-money-net {
    color: #93c5fd;
}

/* Period text */
body.dark-mode .pl-period {
    color: #e2e8f0;
}
body.dark-mode .pl-period-sub {
    color: #64748b;
}

/* Table rows */
body.dark-mode .db-table tbody td strong {
    color: #e2e8f0;
}
body.dark-mode .db-text-sm {
    color: #94a3b8;
}

/* Delete modal body */
body.dark-mode #deletePayslipModal .db-modal__body div[style*="font-size:15px"] {
    color: #e2e8f0;
}
body.dark-mode #deletePayslipModal .db-modal__body div[style*="font-size:13px"] {
    color: #94a3b8;
}

/* ── Payslip List page styles (dashboard-matched) ── */
.pl-page { padding: 0 0 40px; }

.pl-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; padding-top:6px; }
.pl-header__title { font-size:22px; font-weight:800; letter-spacing:-0.4px; display:flex; align-items:center; gap:10px; }
.pl-header__title i { color:var(--db-success); }
.pl-header__sub   { font-size:13px; color:var(--db-muted); margin-top:3px; }

/* Filter bar */
.pl-filter { background:var(--db-surf); border-radius:var(--db-radius-lg); border:1px solid var(--db-border); box-shadow:var(--db-shadow); padding:18px 24px; margin-bottom:22px; }
.pl-filter__row  { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; }
.pl-filter__field { flex:1 1 180px; }
.pl-filter__field label { display:block; font-size:11px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.7px; margin-bottom:6px; font-family:'DM Mono',monospace; }
.pl-filter__field select,
.pl-filter__field input[type="month"] { width:100%; padding:8px 12px; border:1.5px solid var(--db-border); border-radius:var(--db-radius-sm); font-family:'Sora',sans-serif; font-size:13px; color:var(--db-text); background:var(--db-surf); outline:none; transition:all .18s; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:30px; }
.pl-filter__field input[type="month"] { background-image:none; padding-right:12px; }
.pl-filter__field select:focus,
.pl-filter__field input[type="month"]:focus { border-color:var(--db-navy-light); box-shadow:0 0 0 3px rgba(28,52,97,.1); }

/* Stats row */
.pl-stats { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
.pl-stat { flex:1 1 160px; background:var(--db-surf); border-radius:var(--db-radius); padding:18px; display:flex; flex-direction:column; gap:8px; box-shadow:var(--db-shadow); border:1px solid var(--db-border); position:relative; overflow:hidden; }
.pl-stat__icon { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:15px; }
.pl-stat__num   { font-size:26px; font-weight:800; line-height:1; letter-spacing:-1px; }
.pl-stat__label { font-size:10.5px; color:var(--db-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.pl-stat__bar   { height:3px; border-radius:2px; opacity:.4; }

/* Staff avatar mini */
.pl-avatar { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; color:#fff; overflow:hidden; flex-shrink:0; }
.pl-avatar img { width:100%; height:100%; object-fit:cover; }

/* Money value */
.pl-money-pos { font-family:'DM Mono',monospace; font-size:13px; font-weight:700; color:var(--db-success); }
.pl-money-neg { font-family:'DM Mono',monospace; font-size:13px; font-weight:700; color:var(--db-danger); }
.pl-money-net { font-family:'DM Mono',monospace; font-size:15px; font-weight:800; color:var(--db-navy); }

/* Period display */
.pl-period { font-family:'DM Mono',monospace; font-size:11.5px; font-weight:600; color:var(--db-text); }
.pl-period-sub { font-size:10px; color:var(--db-muted); font-family:'DM Mono',monospace; }

@media(max-width:760px){
    .pl-stats { gap:10px; }
    .pl-stat { flex:1 1 130px; }
}
</style>

<div class="pl-page">

    <!-- Page Header -->
    <div class="pl-header">
        <div>
            <div class="pl-header__title">
                <i class="fas fa-file-invoice-dollar"></i>
                <?php echo $is_admin ? 'Payslip Management' : 'My Payslips'; ?>
            </div>
            <div class="pl-header__sub">View and manage salary payslips</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($is_admin): ?>
            <a href="generate-payslip.php" class="db-btn db-btn--primary db-btn--sm">
                <i class="fas fa-plus"></i> Generate New
            </a>
            <?php endif; ?>
            <a href="index.php" class="db-btn db-btn--ghost db-btn--sm">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- Alerts -->
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

    <!-- Stats -->
    <?php if ($total_payslips > 0): ?>
    <div class="pl-stats">
        <div class="pl-stat">
            <div class="pl-stat__icon" style="background:var(--db-indigo-light);color:var(--db-indigo)"><i class="fas fa-file-invoice"></i></div>
            <div class="pl-stat__num" style="color:var(--db-indigo)"><?php echo $total_payslips; ?></div>
            <div class="pl-stat__label">Total Payslips</div>
            <div class="pl-stat__bar" style="background:linear-gradient(90deg,var(--db-indigo),transparent)"></div>
        </div>
        <div class="pl-stat">
            <div class="pl-stat__icon" style="background:var(--db-success-light);color:var(--db-success)"><i class="fas fa-money-bill-wave"></i></div>
            <div class="pl-stat__num" style="color:var(--db-success);font-size:18px">₱<?php echo number_format($total_gross, 2); ?></div>
            <div class="pl-stat__label">Total Gross Pay</div>
            <div class="pl-stat__bar" style="background:linear-gradient(90deg,var(--db-success),transparent)"></div>
        </div>
        <div class="pl-stat">
            <div class="pl-stat__icon" style="background:var(--db-danger-light);color:var(--db-danger)"><i class="fas fa-minus-circle"></i></div>
            <div class="pl-stat__num" style="color:var(--db-danger);font-size:18px">₱<?php echo number_format($total_deductions, 2); ?></div>
            <div class="pl-stat__label">Total Deductions</div>
            <div class="pl-stat__bar" style="background:linear-gradient(90deg,var(--db-danger),transparent)"></div>
        </div>
        <div class="pl-stat">
            <div class="pl-stat__icon" style="background:var(--db-sky-light);color:var(--db-sky)"><i class="fas fa-hand-holding-usd"></i></div>
            <div class="pl-stat__num" style="color:var(--db-sky);font-size:18px">₱<?php echo number_format($total_net, 2); ?></div>
            <div class="pl-stat__label">Total Net Pay</div>
            <div class="pl-stat__bar" style="background:linear-gradient(90deg,var(--db-sky),transparent)"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="pl-filter">
        <form method="GET">
            <div class="pl-filter__row">
                <?php if ($is_admin): ?>
                <div class="pl-filter__field" style="flex:2 1 200px">
                    <label><i class="fas fa-user me-1"></i> Staff Member</label>
                    <select name="user_id" onchange="this.form.submit()">
                        <option value="0">— All Staff —</option>
                        <?php foreach ($staff as $s): ?>
                        <option value="<?php echo $s['user_id']; ?>" <?php echo $selected_user==$s['user_id']?'selected':''; ?>>
                            <?php echo htmlspecialchars($s['full_name']); ?> (<?php echo $s['role']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="pl-filter__field">
                    <label><i class="fas fa-calendar me-1"></i> Pay Period</label>
                    <input type="month" name="month" value="<?php echo $selected_month; ?>" onchange="this.form.submit()">
                </div>
                <div style="flex-shrink:0;padding-bottom:0">
                    <a href="payslip-list.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table Panel -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></span>
                <h2>Payslip Records</h2>
            </div>
            <span class="db-badge db-badge--muted"><?php echo $total_payslips; ?> record(s)</span>
        </div>

        <?php if ($total_payslips > 0): ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr>
                        <th>Payslip #</th>
                        <?php if ($is_admin): ?><th>Staff Member</th><?php endif; ?>
                        <th>Pay Period</th>
                        <th>Days Present</th>
                        <th>OT Hours</th>
                        <th>Gross Pay</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                        <th>Generated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $avatarColors = ['#0d1b36','#1e40af','#065f46','#9f1239','#713f12','#075985','#7c3aed'];
                foreach ($payslips as $p):
                    $initial = strtoupper(substr($p['staff_name'] ?? '?', 0, 1));
                    $avBg    = $avatarColors[ord($initial) % count($avatarColors)];
                ?>
                <tr>
                    <td><span class="db-id">#<?php echo str_pad($p['payslip_id'], 6, '0', STR_PAD_LEFT); ?></span></td>
                    <?php if ($is_admin): ?>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="pl-avatar" style="background:<?php echo $avBg; ?>">
                                <?php if (!empty($p['profile_photo']) && file_exists('../../../uploads/profiles/'.$p['profile_photo'])): ?>
                                    <img src="../../../uploads/profiles/<?php echo $p['profile_photo']; ?>" alt="">
                                <?php else: echo $initial; endif; ?>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($p['staff_name']); ?></strong><br>
                                <span class="db-badge db-badge--muted" style="font-size:9.5px"><?php echo $p['role']; ?></span>
                            </div>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div class="pl-period"><?php echo date('M d', strtotime($p['pay_period_start'])); ?> – <?php echo date('M d, Y', strtotime($p['pay_period_end'])); ?></div>
                        <div class="pl-period-sub"><?php echo date('F Y', strtotime($p['pay_period_start'])); ?></div>
                    </td>
                    <td>
                        <span class="db-badge db-badge--success"><?php echo $p['days_present']; ?> days</span>
                    </td>
                    <td>
                        <?php if ($p['overtime_hours'] > 0): ?>
                            <span class="db-badge db-badge--info"><?php echo number_format($p['overtime_hours'], 1); ?> hrs</span>
                        <?php else: echo '<span class="db-text-muted">—</span>'; endif; ?>
                    </td>
                    <td><span class="pl-money-pos">₱<?php echo number_format($p['gross_pay'], 2); ?></span></td>
                    <td><span class="pl-money-neg">₱<?php echo number_format($p['total_deductions'], 2); ?></span></td>
                    <td><span class="pl-money-net">₱<?php echo number_format($p['net_pay'], 2); ?></span></td>
                    <td>
                        <span class="db-text-sm"><?php echo date('M d, Y', strtotime($p['generated_at'])); ?></span>
                        <?php if ($is_admin && !empty($p['created_by_name'])): ?>
                        <br><span class="db-text-sm">by <?php echo htmlspecialchars($p['created_by_name']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="db-btn-group">
                            <a href="view-payslip.php?id=<?php echo $p['payslip_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View"><i class="fas fa-eye"></i></a>
                            <?php if ($is_admin): ?>
                            <button class="db-icon-btn db-icon-btn--danger" onclick="openDeleteModal(<?php echo $p['payslip_id']; ?>)" title="Delete"><i class="fas fa-trash"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="db-panel__footer">
            <span style="font-size:12px;color:var(--db-muted)">Showing <?php echo $total_payslips; ?> payslip(s)</span>
        </div>

        <?php else: ?>
        <div class="db-empty">
            <i class="fas fa-file-invoice-dollar"></i>
            <p>No payslips found for the selected filters.</p>
            <?php if ($is_admin): ?>
            <a href="generate-payslip.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> Generate First Payslip</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /pl-page -->


<!-- Delete Confirm Modal -->
<?php if ($is_admin): ?>
<div id="deletePayslipModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deletePayslipModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div style="text-align:center;margin-bottom:20px">
                <div style="width:56px;height:56px;border-radius:50%;background:var(--db-danger-light);display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:22px;color:var(--db-danger)">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div style="font-size:15px;font-weight:700">Delete this payslip?</div>
                <div style="font-size:13px;color:var(--db-muted);margin-top:6px">This action cannot be undone.</div>
            </div>
            <form method="POST" id="deletePayslipForm">
                <input type="hidden" name="delete_payslip" value="1">
                <input type="hidden" name="payslip_id" id="delete_payslip_id_field">
                <div style="display:flex;gap:10px">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deletePayslipModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function openDeleteModal(id) {
    document.getElementById('delete_payslip_id_field').value = id;
    openModal('deletePayslipModal');
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(), 400);
    });
}, 5000);
</script>

<?php include '../../../includes/footer.php'; ?>