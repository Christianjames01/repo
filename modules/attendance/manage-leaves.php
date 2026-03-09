<?php
/**
 * Staff Leave Management — Restyled to match Dashboard UI
 * modules/attendance/manage-leaves.php
 */

date_default_timezone_set('Asia/Manila');
require_once '../../config/config.php';
require_once '../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isLoggedIn()) redirect('/barangaylink/modules/auth/login.php', 'Please login to continue', 'error');

$page_title      = 'My Leave Requests';
$current_user_id = getCurrentUserId();
$user_role       = getCurrentUserRole();

// ── Cancel leave ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_leave'])) {
    $leave_id = (int)$_POST['leave_id'];
    $leave = fetchOne($conn,"SELECT * FROM tbl_leave_requests WHERE leave_id=? AND user_id=? AND status='Pending'",[$leave_id,$current_user_id],'ii');
    if ($leave) {
        if (executeQuery($conn,"UPDATE tbl_leave_requests SET status='Cancelled' WHERE leave_id=?",[$leave_id],'i')) { logActivity($conn,$current_user_id,'Cancelled leave request','tbl_leave_requests',$leave_id); $_SESSION['success_message']='Leave request cancelled successfully'; }
        else $_SESSION['error_message']='Failed to cancel leave request';
    } else $_SESSION['error_message']='Leave request not found or cannot be cancelled';
    header('Location: manage-leaves.php'); exit();
}
// ─────────────────────────────────────────────────────────────────────────────

$records_per_page = 10;
$page             = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$offset           = ($page-1)*$records_per_page;
$status_filter    = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';

$where_my = ["lr.user_id = ?"]; $params_my = [$current_user_id]; $types_my = 'i';
if ($status_filter !== 'all') { $where_my[] = "lr.status = ?"; $params_my[] = $status_filter; $types_my .= 's'; }
$wc_my = implode(' AND ', $where_my);

$my_leaves = fetchAll($conn,
    "SELECT lr.*,CONCAT(res.first_name,' ',res.last_name) as staff_name,DATEDIFF(lr.end_date,lr.start_date)+1 as duration_days,lr.admin_notes,lr.processed_by,lr.processed_at,COALESCE(NULLIF(CONCAT(TRIM(COALESCE(pr.first_name,'')), ' ',TRIM(COALESCE(pr.last_name,''))), ' '),pu.username) as processor_name
     FROM tbl_leave_requests lr
     INNER JOIN tbl_users u ON lr.user_id=u.user_id
     LEFT JOIN tbl_residents res ON u.resident_id=res.resident_id
     LEFT JOIN tbl_users pu ON lr.processed_by=pu.user_id
     LEFT JOIN tbl_residents pr ON pu.resident_id=pr.resident_id
     WHERE $wc_my ORDER BY lr.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params_my,[$records_per_page,$offset]), $types_my.'ii');

$total_my  = fetchOne($conn,"SELECT COUNT(*) as total FROM tbl_leave_requests lr WHERE $wc_my",$params_my,$types_my)['total'];
$total_pages = ceil($total_my / $records_per_page);

$leave_stats = fetchOne($conn,
    "SELECT COUNT(*) as total_requests,SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_count,SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_count,SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected_count,SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) as cancelled_count,SUM(CASE WHEN status='Approved' THEN DATEDIFF(end_date,start_date)+1 ELSE 0 END) as total_days_taken
     FROM tbl_leave_requests WHERE user_id=? AND YEAR(start_date)=YEAR(CURDATE())",
    [$current_user_id],'i');

function leaveStatusBadge($status) {
    $cls = ['Pending'=>'db-badge--warning','Approved'=>'db-badge--success','Rejected'=>'db-badge--danger','Cancelled'=>'db-badge--muted'];
    $ico = ['Pending'=>'fa-clock','Approved'=>'fa-check-circle','Rejected'=>'fa-times-circle','Cancelled'=>'fa-ban'];
    return '<span class="db-badge '.($cls[$status]??'db-badge--muted').'"><i class="fas '.($ico[$status]??'fa-circle').' me-1"></i>'.$status.'</span>';
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<style>
/* ── Manage Leaves page (dashboard-matched) ── */
.ml-page { padding:0 0 40px; }

.ml-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; padding-top:6px; }
.ml-header__title { font-size:22px; font-weight:800; letter-spacing:-0.4px; display:flex; align-items:center; gap:10px; }
.ml-header__title i { color:var(--db-rose); }
.ml-header__sub { font-size:13px; color:var(--db-muted); margin-top:3px; }

/* Stats row */
.ml-stats { display:flex; flex-wrap:wrap; gap:14px; margin-bottom:22px; }
.ml-stat { flex:1 1 150px; background:var(--db-surf); border-radius:var(--db-radius); padding:18px; display:flex; flex-direction:column; gap:8px; box-shadow:var(--db-shadow); border:1px solid var(--db-border); position:relative; overflow:hidden; }
.ml-stat__icon  { width:38px; height:38px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:15px; }
.ml-stat__num   { font-size:26px; font-weight:800; line-height:1; letter-spacing:-1px; }
.ml-stat__label { font-size:10.5px; color:var(--db-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.ml-stat__sub   { font-size:10.5px; color:var(--db-muted); font-family:'DM Mono',monospace; }
.ml-stat__bar   { height:3px; border-radius:2px; opacity:.4; }

/* Filter bar */
.ml-filter { background:var(--db-surf); border-radius:var(--db-radius-lg); border:1px solid var(--db-border); box-shadow:var(--db-shadow); padding:16px 22px; margin-bottom:22px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
.ml-filter__label { font-size:12px; font-weight:700; color:var(--db-muted); text-transform:uppercase; letter-spacing:.6px; font-family:'DM Mono',monospace; }
.ml-filter__select { padding:7px 32px 7px 12px; border:1.5px solid var(--db-border); border-radius:var(--db-radius-sm); font-family:'Sora',sans-serif; font-size:13px; color:var(--db-text); background:var(--db-surf); outline:none; transition:all .18s; appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
.ml-filter__select:focus { border-color:var(--db-navy-light); box-shadow:0 0 0 3px rgba(28,52,97,.1); }

/* Leave type tag */
.ml-leave-type { display:inline-block; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:700; background:var(--db-indigo-light); color:var(--db-indigo); font-family:'DM Mono',monospace; }

/* Reason text */
.ml-reason { font-size:12px; color:var(--db-muted); max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Pagination */
.ml-pagination { display:flex; justify-content:center; gap:6px; margin-top:18px; padding:14px 22px; border-top:1px solid var(--db-border); background:var(--db-surf2); }
.ml-page-btn { width:32px; height:32px; border-radius:var(--db-radius-sm); border:1.5px solid var(--db-border); background:var(--db-surf); color:var(--db-text); font-size:12px; font-weight:600; display:flex; align-items:center; justify-content:center; text-decoration:none; transition:all .15s; }
.ml-page-btn:hover { background:var(--db-navy); color:#fff; border-color:var(--db-navy); }
.ml-page-btn--active { background:var(--db-navy); color:#fff; border-color:var(--db-navy); }
.ml-page-btn--disabled { opacity:.4; pointer-events:none; }

/* Admin notes box inside modal */
.ml-admin-note { background:var(--db-info-light); border:1px solid #bfdbfe; border-left:4px solid var(--db-info); border-radius:var(--db-radius-sm); padding:12px 14px; font-size:13px; color:#1e40af; }

/* Leave detail rows inside modal */
.ml-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.ml-detail-item { display:flex; flex-direction:column; gap:4px; }
.ml-detail-item__label { font-size:11px; color:var(--db-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; font-family:'DM Mono',monospace; }
.ml-detail-item--full { grid-column:span 2; }

@media(max-width:760px){
    .ml-stats { gap:10px; }
    .ml-stat { flex:1 1 130px; }
    .ml-detail-grid { grid-template-columns:1fr; }
    .ml-detail-item--full { grid-column:span 1; }
}
</style>

<div class="ml-page">

    <!-- Header -->
    <div class="ml-header">
        <div>
            <div class="ml-header__title"><i class="fas fa-calendar-times"></i> Leave Management</div>
            <div class="ml-header__sub">View and manage your leave requests this year</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="my-schedule.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-arrow-left"></i> Back</a>
            <a href="leave-request.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> New Request</a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success"><div class="db-alert__icon"><i class="fas fa-check-circle"></i></div><span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error_message'])): ?>
    <div class="db-alert db-alert--error"><div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div><span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="ml-stats">
        <div class="ml-stat">
            <div class="ml-stat__icon" style="background:var(--db-indigo-light);color:var(--db-indigo)"><i class="fas fa-file-alt"></i></div>
            <div class="ml-stat__num" style="color:var(--db-indigo)"><?php echo $leave_stats['total_requests']; ?></div>
            <div class="ml-stat__label">Total Requests</div>
            <div class="ml-stat__sub">This year</div>
            <div class="ml-stat__bar" style="background:linear-gradient(90deg,var(--db-indigo),transparent)"></div>
        </div>
        <div class="ml-stat">
            <div class="ml-stat__icon" style="background:var(--db-warning-light);color:var(--db-amber-dark)"><i class="fas fa-clock"></i></div>
            <div class="ml-stat__num" style="color:var(--db-amber)"><?php echo $leave_stats['pending_count']; ?></div>
            <div class="ml-stat__label">Pending</div>
            <div class="ml-stat__sub">Awaiting approval</div>
            <div class="ml-stat__bar" style="background:linear-gradient(90deg,var(--db-amber),transparent)"></div>
        </div>
        <div class="ml-stat">
            <div class="ml-stat__icon" style="background:var(--db-success-light);color:var(--db-success)"><i class="fas fa-check-circle"></i></div>
            <div class="ml-stat__num" style="color:var(--db-success)"><?php echo $leave_stats['approved_count']; ?></div>
            <div class="ml-stat__label">Approved</div>
            <div class="ml-stat__sub"><?php echo $leave_stats['total_days_taken']; ?> days taken</div>
            <div class="ml-stat__bar" style="background:linear-gradient(90deg,var(--db-success),transparent)"></div>
        </div>
        <div class="ml-stat">
            <div class="ml-stat__icon" style="background:var(--db-danger-light);color:var(--db-danger)"><i class="fas fa-times-circle"></i></div>
            <div class="ml-stat__num" style="color:var(--db-danger)"><?php echo $leave_stats['rejected_count']; ?></div>
            <div class="ml-stat__label">Rejected</div>
            <div class="ml-stat__sub">Not approved</div>
            <div class="ml-stat__bar" style="background:linear-gradient(90deg,var(--db-danger),transparent)"></div>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="ml-filter">
        <span class="ml-filter__label"><i class="fas fa-filter me-1"></i> Filter by Status</span>
        <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:12.5px;color:var(--db-muted)">Status:</span>
            <select class="ml-filter__select" id="statusFilter">
                <option value="all"       <?php echo $status_filter==='all'?'selected':''; ?>>All Status</option>
                <option value="Pending"   <?php echo $status_filter==='Pending'?'selected':''; ?>>Pending</option>
                <option value="Approved"  <?php echo $status_filter==='Approved'?'selected':''; ?>>Approved</option>
                <option value="Rejected"  <?php echo $status_filter==='Rejected'?'selected':''; ?>>Rejected</option>
                <option value="Cancelled" <?php echo $status_filter==='Cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
        </div>
    </div>

    <!-- Leave table -->
    <div class="db-panel">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-list"></i></span>
                <h2>My Leave Requests</h2>
            </div>
            <span class="db-badge db-badge--muted"><?php echo $total_my; ?> record(s)</span>
        </div>

        <?php if (!empty($my_leaves)): ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr><th>Leave Type</th><th>Start Date</th><th>End Date</th><th>Duration</th><th>Status</th><th>Reason</th><th>Submitted</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($my_leaves as $lv): ?>
                <tr>
                    <td><span class="ml-leave-type"><?php echo htmlspecialchars($lv['leave_type']); ?></span></td>
                    <td><span class="db-text-sm"><i class="fas fa-calendar-day" style="color:var(--db-success);margin-right:4px"></i><?php echo date('M d, Y',strtotime($lv['start_date'])); ?></span></td>
                    <td><span class="db-text-sm"><i class="fas fa-calendar-check" style="color:var(--db-danger);margin-right:4px"></i><?php echo date('M d, Y',strtotime($lv['end_date'])); ?></span></td>
                    <td><span class="db-badge db-badge--muted"><?php echo $lv['duration_days']; ?> day(s)</span></td>
                    <td><?php echo leaveStatusBadge($lv['status']); ?></td>
                    <td>
                        <span class="ml-reason" title="<?php echo htmlspecialchars($lv['reason']); ?>">
                            <?php $r=htmlspecialchars($lv['reason']); echo strlen($r)>50?substr($r,0,50).'…':$r; ?>
                        </span>
                    </td>
                    <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($lv['created_at'])); ?></span></td>
                    <td>
                        <div class="db-btn-group">
                            <button class="db-icon-btn db-icon-btn--info" onclick='openViewModal(<?php echo json_encode($lv); ?>)' title="View"><i class="fas fa-eye"></i></button>
                            <?php if ($lv['status'] === 'Pending'): ?>
                            <button class="db-icon-btn db-icon-btn--danger" onclick='openCancelModal(<?php echo $lv["leave_id"]; ?>, <?php echo json_encode($lv["leave_type"]); ?>, <?php echo json_encode(date("M d, Y",strtotime($lv["start_date"]))); ?>, <?php echo json_encode(date("M d, Y",strtotime($lv["end_date"]))); ?>)' title="Cancel"><i class="fas fa-times"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="ml-pagination">
            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page-1; ?>" class="ml-page-btn <?php echo $page<=1?'ml-page-btn--disabled':''; ?>"><i class="fas fa-chevron-left"></i></a>
            <?php for ($i=1;$i<=$total_pages;$i++): ?>
                <?php if ($i==1||$i==$total_pages||($i>=$page-2&&$i<=$page+2)): ?>
                <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $i; ?>" class="ml-page-btn <?php echo $i==$page?'ml-page-btn--active':''; ?>"><?php echo $i; ?></a>
                <?php elseif ($i==$page-3||$i==$page+3): ?>
                <span class="ml-page-btn ml-page-btn--disabled">…</span>
                <?php endif; ?>
            <?php endfor; ?>
            <a href="?status=<?php echo $status_filter; ?>&page=<?php echo $page+1; ?>" class="ml-page-btn <?php echo $page>=$total_pages?'ml-page-btn--disabled':''; ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="db-empty">
            <i class="fas fa-inbox"></i>
            <p>No leave requests found. Submit your first request to get started.</p>
            <a href="leave-request.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> New Leave Request</a>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /ml-page -->


<!-- ── MODAL: View Leave Details ── -->
<div id="viewLeaveModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-info-circle"></i> Leave Request Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewLeaveModal')">×</button>
        </div>
        <div class="db-modal__body" id="view_leave_content"></div>
    </div>
</div>


<!-- ── MODAL: Cancel Leave ── -->
<div id="cancelLeaveModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-times-circle"></i> Cancel Leave Request</h3>
            <button class="db-modal__close" onclick="closeModal('cancelLeaveModal')">×</button>
        </div>
        <form method="POST" id="cancelLeaveForm" class="db-modal__body">
            <input type="hidden" name="cancel_leave" value="1">
            <input type="hidden" name="leave_id" id="cancel_leave_id_field">

            <div class="db-alert db-alert--error" style="margin-bottom:16px">
                <div class="db-alert__icon"><i class="fas fa-exclamation-triangle"></i></div>
                <span>Are you sure you want to cancel this leave request? This cannot be undone.</span>
            </div>

            <div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:14px;margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.6px;font-family:'DM Mono',monospace;margin-bottom:10px">Leave Details</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <div style="font-size:11px;color:var(--db-muted)">Leave Type</div>
                        <strong id="cancel_type_display"></strong>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--db-muted)">Start Date</div>
                        <strong id="cancel_start_display"></strong>
                    </div>
                    <div style="grid-column:span 2">
                        <div style="font-size:11px;color:var(--db-muted)">End Date</div>
                        <strong id="cancel_end_display"></strong>
                    </div>
                </div>
            </div>

            <div class="db-alert db-alert--error" style="font-size:12px;margin-bottom:16px">
                <div class="db-alert__icon"><i class="fas fa-info-circle"></i></div>
                <span><strong>Important:</strong> You must submit a new request if you need leave for the same dates.</span>
            </div>

            <div style="display:flex;gap:10px">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('cancelLeaveModal')">
                    <i class="fas fa-arrow-left"></i> Keep Request
                </button>
                <button type="submit" class="db-btn db-btn--danger db-btn--full" id="cancelSubmitBtn">
                    <i class="fas fa-times-circle"></i> Cancel Request
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// ── Modal helpers ──
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

// ── Status filter ──
document.getElementById('statusFilter').addEventListener('change', function() {
    window.location.href = `?status=${this.value}&page=1`;
});

// ── View leave modal ──
function openViewModal(lv) {
    const statusCls  = {Pending:'db-badge--warning',Approved:'db-badge--success',Rejected:'db-badge--danger',Cancelled:'db-badge--muted'};
    const statusIco  = {Pending:'fa-clock',Approved:'fa-check-circle',Rejected:'fa-times-circle',Cancelled:'fa-ban'};
    const statusBadge = `<span class="db-badge ${statusCls[lv.status]||'db-badge--muted'}"><i class="fas ${statusIco[lv.status]||'fa-circle'} me-1"></i>${lv.status}</span>`;

    const adminNoteHtml = (lv.admin_notes && lv.admin_notes.trim())
        ? `<div class="ml-detail-item ml-detail-item--full"><div class="ml-detail-item__label"><i class="fas fa-sticky-note me-1"></i>Admin Notes</div><div class="ml-admin-note">${lv.admin_notes.replace(/\n/g,'<br>')}</div></div>` : '';

    const processedHtml = (lv.status !== 'Pending' && lv.processed_at)
        ? `<div class="ml-detail-item"><div class="ml-detail-item__label">Processed On</div><span class="db-text-sm">${lv.processed_at}</span></div>
           <div class="ml-detail-item"><div class="ml-detail-item__label">Processed By</div><span class="db-text-sm">${lv.processor_name||'—'}</span></div>` : '';

    document.getElementById('view_leave_content').innerHTML = `
        <div class="ml-detail-grid">
            <div class="ml-detail-item"><div class="ml-detail-item__label">Leave Type</div><span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;background:var(--db-indigo-light);color:var(--db-indigo)">${lv.leave_type}</span></div>
            <div class="ml-detail-item"><div class="ml-detail-item__label">Status</div>${statusBadge}</div>
            <div class="ml-detail-item"><div class="ml-detail-item__label">Start Date</div><strong>${lv.start_date}</strong></div>
            <div class="ml-detail-item"><div class="ml-detail-item__label">End Date</div><strong>${lv.end_date}</strong></div>
            <div class="ml-detail-item"><div class="ml-detail-item__label">Duration</div><span class="db-badge db-badge--muted">${lv.duration_days} day(s)</span></div>
            <div class="ml-detail-item"><div class="ml-detail-item__label">Submitted</div><span class="db-text-sm">${lv.created_at}</span></div>
            <div class="ml-detail-item ml-detail-item--full"><div class="ml-detail-item__label">Reason</div><div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:10px 12px;font-size:13px;line-height:1.7">${(lv.reason||'').replace(/\n/g,'<br>')}</div></div>
            ${adminNoteHtml}
            ${processedHtml}
        </div>
        <div style="margin-top:18px;display:flex;gap:10px">
            ${lv.status==='Pending'?`<button class="db-btn db-btn--danger db-btn--full" onclick='closeModal("viewLeaveModal");openCancelModal(${lv.leave_id},${JSON.stringify(lv.leave_type)},${JSON.stringify(lv.start_date)},${JSON.stringify(lv.end_date)})'><i class="fas fa-times-circle"></i> Cancel Request</button>`:''}
            <button class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('viewLeaveModal')">Close</button>
        </div>`;
    openModal('viewLeaveModal');
}

// ── Cancel modal ──
function openCancelModal(id, type, startDate, endDate) {
    document.getElementById('cancel_leave_id_field').value = id;
    document.getElementById('cancel_type_display').textContent  = type;
    document.getElementById('cancel_start_display').textContent = startDate;
    document.getElementById('cancel_end_display').textContent   = endDate;
    openModal('cancelLeaveModal');
}

document.getElementById('cancelLeaveForm').addEventListener('submit', function() {
    const btn = document.getElementById('cancelSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Cancelling…';
});

setTimeout(()=>{ document.querySelectorAll('.db-alert').forEach(a=>{ a.style.opacity='0'; a.style.transform='translateY(-8px)'; setTimeout(()=>a.remove(),400); }); }, 5000);
</script>

<?php include '../../includes/footer.php'; ?>