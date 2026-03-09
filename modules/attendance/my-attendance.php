<?php
/**
 * Staff My Attendance History View
 * modules/attendance/my-attendance-history.php
 * RESTYLED TO MATCH ADMIN ATTENDANCE UI
 */

date_default_timezone_set('Asia/Manila');

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /barangaylink/modules/auth/login.php');
    exit();
}

$page_title = 'My Attendance';
$current_user_id = getCurrentUserId();

$month         = isset($_GET['month'])  ? sanitizeInput($_GET['month'])  : date('m');
$year          = isset($_GET['year'])   ? sanitizeInput($_GET['year'])   : date('Y');
$status_filter = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

$where = ["user_id = ?"];
$params = [$current_user_id];
$types  = 'i';

if ($month && $year) {
    $where[]  = "MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?";
    $params[] = $month; $params[] = $year; $types .= 'ii';
}
if ($status_filter) {
    $where[] = "status = ?"; $params[] = $status_filter; $types .= 's';
}

$where_sql = implode(' AND ', $where);
$records   = fetchAll($conn,
    "SELECT * FROM tbl_attendance WHERE $where_sql ORDER BY attendance_date DESC, time_in DESC",
    $params, $types
);

$total_present = $total_late = $total_absent = $total_on_leave = $total_hours = 0;
foreach ($records as $r) {
    switch ($r['status']) {
        case 'Present':  $total_present++;  break;
        case 'Late':     $total_late++;     break;
        case 'Absent':   $total_absent++;   break;
        case 'On Leave': $total_on_leave++; break;
    }
    if ($r['time_in'] && $r['time_out']) {
        $diff = (strtotime($r['time_out']) - strtotime($r['time_in'])) / 3600;
        if ($diff < 0) $diff += 24;
        $total_hours += $diff;
    }
}

$user_info = fetchOne($conn,
    "SELECT u.*, CONCAT(r.first_name,' ',r.last_name) as full_name
     FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id
     WHERE u.user_id=?",
    [$current_user_id], 'i'
);

function formatTimeDisplay($t) {
    if (empty($t) || $t === '00:00:00') return 'N/A';
    try { return (new DateTime($t))->format('g:i A'); } catch (Exception $e) { return 'N/A'; }
}

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

/* ── att badges (matching index.php) ────────────────────────────── */
.att-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px;
    font-family:'DM Mono',monospace; font-size:10.5px;
    font-weight:600; letter-spacing:.3px; white-space:nowrap;
}
.att-badge--present  { background:#d1fae5; color:#065f46; }
.att-badge--late     { background:#fef3c7; color:#92400e; }
.att-badge--absent   { background:#fee2e2; color:#7f1d1d; }
.att-badge--leave    { background:#dbeafe; color:#1e40af; }
.att-badge--halfday  { background:#ede9fe; color:#4c1d95; }
.att-badge--unmarked { background:var(--slate-100); color:var(--slate-600); border:1px solid var(--slate-200); }

.time-in  { color:var(--green);    font-family:'DM Mono',monospace; font-size:12.5px; font-weight:600; }
.time-out { color:var(--rose);     font-family:'DM Mono',monospace; font-size:12.5px; font-weight:600; }
.hrs-badge {
    display:inline-block; padding:3px 10px; border-radius:20px;
    background:#dbeafe; color:#1e40af;
    font-family:'DM Mono',monospace; font-size:10.5px; font-weight:700;
}

/* ── filter card ─────────────────────────────────────────────────── */
.ah-filter-card {
    background:#fff; border:1px solid var(--slate-200); border-radius:14px;
    box-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    padding:18px 22px; margin-bottom:20px;
}
.ah-filter-row { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; }
.ah-filter-group { display:flex; flex-direction:column; gap:5px; flex:1; min-width:140px; }
.ah-filter-group label {
    font-size:11.5px; font-weight:700; color:var(--slate-600);
    text-transform:uppercase; letter-spacing:.5px; font-family:'Sora',sans-serif;
}
.ah-input {
    width:100%; padding:9px 13px; border:1.5px solid var(--slate-200); border-radius:8px;
    font-family:'Sora',sans-serif; font-size:13px; color:var(--slate-900);
    background:var(--slate-50); outline:none; transition:all .18s; appearance:none;
}
.ah-input:focus { border-color:var(--navy-mid); box-shadow:0 0 0 3px rgba(28,52,97,.1); background:#fff; }

/* ── today row highlight ─────────────────────────────────────────── */
.db-table tbody tr.today-row { background:linear-gradient(135deg,#eff6ff,#f0f9ff); }

/* ── date cell ───────────────────────────────────────────────────── */
.ah-date-cell { font-family:'DM Mono',monospace; font-size:12.5px; font-weight:700; color:var(--slate-900); }
.ah-day-cell  { font-size:12px; color:var(--slate-400); }

/* ── footer summary ──────────────────────────────────────────────── */
.ah-footer-bar {
    display:flex; justify-content:space-between; align-items:center;
    padding:14px 22px; border-top:1px solid var(--slate-200);
    background:var(--slate-50); border-radius:0 0 14px 14px;
    font-family:'Sora',sans-serif; font-size:12px; flex-wrap:wrap; gap:8px;
}
.ah-footer-pills { display:flex; gap:8px; flex-wrap:wrap; }
.ah-sum-pill {
    display:inline-flex; align-items:center; gap:5px;
    padding:4px 12px; border-radius:20px;
    font-family:'DM Mono',monospace; font-size:11px; font-weight:700;
}
.ah-sum-pill--present { background:#d1fae5; color:#065f46; }
.ah-sum-pill--late    { background:#fef3c7; color:#92400e; }
.ah-sum-pill--absent  { background:#fee2e2; color:#7f1d1d; }
.ah-sum-pill--hours   { background:#dbeafe; color:#1e40af; }

@media(max-width:768px){ .ah-filter-row{flex-direction:column;} }
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar"><i class="fas fa-clipboard-list" style="font-size:22px;color:#fff;"></i></div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($user_info['role'] ?? 'Staff'); ?>
                </div>
                <h1 class="db-hero__title">My Attendance History</h1>
                <p class="db-hero__sub"><?php echo htmlspecialchars(trim($user_info['full_name'] ?? '') ?: $user_info['username']); ?></p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="my-schedule.php" class="db-btn db-btn--ghost">
                <i class="fas fa-calendar-week"></i> My Schedule
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

<!-- ─── STAT CARDS ────────────────────────────────────────────────────────── -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#d1fae5;color:#10b981;"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#10b981;"><?php echo $total_present; ?></div>
            <div class="db-stat-card__label">Present Days</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,#10b981,transparent)"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#f59e0b;"><?php echo $total_late; ?></div>
            <div class="db-stat-card__label">Late Arrivals</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-times-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#e11d48;"><?php echo $total_absent; ?></div>
            <div class="db-stat-card__label">Absent Days</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:#dbeafe;color:#6366f1;"><i class="fas fa-calendar-check"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:#6366f1;"><?php echo $total_on_leave; ?></div>
            <div class="db-stat-card__label">On Leave</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-business-time"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($total_hours,1); ?></div>
            <div class="db-stat-card__label">Hours Worked</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:var(--slate-100);color:var(--slate-400);"><i class="fas fa-list"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="color:var(--slate-400);"><?php echo count($records); ?></div>
            <div class="db-stat-card__label">Total Records</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,var(--slate-400),transparent)"></div>
    </div>
</div>

<!-- ─── FILTER ───────────────────────────────────────────────────────────── -->
<div class="ah-filter-card">
    <form method="GET">
        <div class="ah-filter-row">
            <div class="ah-filter-group">
                <label><i class="fas fa-calendar me-1"></i>Month</label>
                <select name="month" class="ah-input" onchange="this.form.submit()">
                    <option value="">All Months</option>
                    <?php for ($m=1; $m<=12; $m++): ?>
                        <option value="<?php echo str_pad($m,2,'0',STR_PAD_LEFT); ?>"
                            <?php echo ($month == str_pad($m,2,'0',STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="ah-filter-group">
                <label><i class="fas fa-calendar-alt me-1"></i>Year</label>
                <select name="year" class="ah-input" onchange="this.form.submit()">
                    <?php for ($y=date('Y'); $y>=date('Y')-5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($year==$y)?'selected':''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="ah-filter-group">
                <label><i class="fas fa-filter me-1"></i>Status</label>
                <select name="status" class="ah-input" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="Present"  <?php echo $status_filter==='Present'  ?'selected':''; ?>>Present</option>
                    <option value="Late"     <?php echo $status_filter==='Late'     ?'selected':''; ?>>Late</option>
                    <option value="Absent"   <?php echo $status_filter==='Absent'   ?'selected':''; ?>>Absent</option>
                    <option value="On Leave" <?php echo $status_filter==='On Leave' ?'selected':''; ?>>On Leave</option>
                    <option value="Half Day" <?php echo $status_filter==='Half Day' ?'selected':''; ?>>Half Day</option>
                </select>
            </div>
            <div class="ah-filter-group" style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <a href="my-attendance.php" class="db-btn db-btn--ghost" style="white-space:nowrap;">
                    <i class="fas fa-times"></i> Reset
                </a>
            </div>
        </div>
    </form>
</div>

<!-- ─── RECORDS TABLE ────────────────────────────────────────────────────── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-clipboard-list"></i></span>
            <h2>
                Attendance Records
                <span style="display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,var(--navy-deep),var(--navy-mid));color:#fff;border-radius:20px;padding:2px 12px;font-size:11px;font-weight:700;font-family:'DM Mono',monospace;margin-left:8px;">
                    <?php echo $month ? date('F', mktime(0,0,0,(int)$month,1)) . ' ' . $year : $year; ?>
                </span>
            </h2>
        </div>
    </div>
    <div class="db-table-wrap">
        <?php if (!empty($records)): ?>
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Hours Worked</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r):
                    $hours = null;
                    if ($r['time_in'] && $r['time_out']) {
                        $d = (strtotime($r['time_out']) - strtotime($r['time_in'])) / 3600;
                        if ($d < 0) $d += 24;
                        $hours = $d;
                    }
                    $day_name = date('l', strtotime($r['attendance_date']));
                    $is_today = ($r['attendance_date'] == date('Y-m-d'));

                    $bm = ['Present'=>'att-badge--present','Late'=>'att-badge--late','Absent'=>'att-badge--absent','On Leave'=>'att-badge--leave','Half Day'=>'att-badge--halfday'];
                    $im = ['Present'=>'fa-check-circle','Late'=>'fa-clock','Absent'=>'fa-times-circle','On Leave'=>'fa-calendar-times','Half Day'=>'fa-adjust'];
                    $bc = $bm[$r['status']] ?? 'att-badge--unmarked';
                    $ic = $im[$r['status']] ?? 'fa-circle';
                ?>
                <tr <?php echo $is_today ? 'class="today-row"' : ''; ?>>
                    <td>
                        <div class="ah-date-cell"><?php echo date('M j, Y', strtotime($r['attendance_date'])); ?></div>
                        <?php if ($is_today): ?>
                            <span class="att-badge att-badge--present" style="font-size:9px;padding:1px 7px;margin-top:3px;">Today</span>
                        <?php endif; ?>
                    </td>
                    <td class="ah-day-cell"><?php echo $day_name; ?></td>
                    <td>
                        <?php if ($r['time_in'] && $r['time_in'] !== '00:00:00'): ?>
                            <span class="time-in"><i class="fas fa-sign-in-alt me-1"></i><?php echo formatTimeDisplay($r['time_in']); ?></span>
                        <?php else: ?><span style="color:var(--slate-400);">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['time_out'] && $r['time_out'] !== '00:00:00'): ?>
                            <span class="time-out"><i class="fas fa-sign-out-alt me-1"></i><?php echo formatTimeDisplay($r['time_out']); ?></span>
                        <?php else: ?><span style="color:var(--slate-400);font-size:12px;">Pending</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hours !== null): ?>
                            <span class="hrs-badge"><?php echo number_format($hours,1); ?> hrs</span>
                        <?php else: ?><span style="color:var(--slate-400);">—</span><?php endif; ?>
                    </td>
                    <td><span class="att-badge <?php echo $bc; ?>"><i class="fas <?php echo $ic; ?>"></i> <?php echo $r['status']; ?></span></td>
                    <td>
                        <?php if (!empty($r['notes'])): ?>
                            <span style="font-size:12px;color:var(--slate-400);"
                                  title="<?php echo htmlspecialchars($r['notes']); ?>">
                                <?php echo htmlspecialchars(substr($r['notes'],0,30).(strlen($r['notes'])>30?'…':'')); ?>
                            </span>
                        <?php else: ?><span style="color:var(--slate-200);">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="att-empty">
            <i class="fas fa-inbox"></i>
            <p>No attendance records match your filter criteria.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($records)): ?>
    <div class="ah-footer-bar">
        <span style="color:var(--slate-400);">
            Showing <strong><?php echo count($records); ?></strong> record(s) for
            <?php echo $month ? date('F', mktime(0,0,0,(int)$month,1)) . ' ' . $year : $year; ?>
        </span>
        <div class="ah-footer-pills">
            <span class="ah-sum-pill ah-sum-pill--present"><i class="fas fa-check-circle"></i> <?php echo $total_present; ?> Present</span>
            <span class="ah-sum-pill ah-sum-pill--late"><i class="fas fa-clock"></i> <?php echo $total_late; ?> Late</span>
            <span class="ah-sum-pill ah-sum-pill--absent"><i class="fas fa-times-circle"></i> <?php echo $total_absent; ?> Absent</span>
            <span class="ah-sum-pill ah-sum-pill--hours"><i class="fas fa-business-time"></i> <?php echo number_format($total_hours,1); ?> hrs</span>
        </div>
    </div>
    <?php endif; ?>
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