<?php
require_once '../../config/config.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Medical Assistance Requests';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = getCurrentUserId();

    if ($_POST['action'] === 'approve') {
        $assistance_id   = (int)$_POST['assistance_id'];
        $approved_amount = (float)$_POST['approved_amount'];
        $remarks         = trim($_POST['remarks']);
        $stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status='Approved', approved_amount=?, approved_date=CURDATE(), approved_by=?, remarks=? WHERE assistance_id=?");
        $stmt->bind_param("disi", $approved_amount, $user_id, $remarks, $assistance_id);
        if ($stmt->execute()) $_SESSION['success_message'] = "Medical assistance approved successfully!";
        $stmt->close();
    } elseif ($_POST['action'] === 'reject') {
        $assistance_id = (int)$_POST['assistance_id'];
        $remarks       = trim($_POST['remarks']);
        $stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status='Rejected', approved_by=?, remarks=? WHERE assistance_id=?");
        $stmt->bind_param("isi", $user_id, $remarks, $assistance_id);
        if ($stmt->execute()) $_SESSION['success_message'] = "Request rejected.";
        $stmt->close();
    } elseif ($_POST['action'] === 'release') {
        $assistance_id = (int)$_POST['assistance_id'];
        $remarks       = trim($_POST['remarks']);
        $stmt = $conn->prepare("UPDATE tbl_medical_assistance SET status='Released', released_date=CURDATE(), processed_by=?, remarks=CONCAT(IFNULL(remarks,''),'\n[Released] ',?) WHERE assistance_id=?");
        $stmt->bind_param("isi", $user_id, $remarks, $assistance_id);
        if ($stmt->execute()) $_SESSION['success_message'] = "Assistance released successfully!";
        $stmt->close();
    }

    header("Location: medical-assistance.php");
    exit;
}

$status_filter   = isset($_GET['status'])   ? $_GET['status']   : 'Pending';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$type_filter     = isset($_GET['type'])     ? $_GET['type']     : '';

$where_clauses = ["1=1"]; $params = []; $types = "";
if ($status_filter)   { $where_clauses[] = "m.status=?";       $params[] = $status_filter;   $types .= "s"; }
if ($priority_filter) { $where_clauses[] = "m.priority=?";     $params[] = $priority_filter; $types .= "s"; }
if ($type_filter)     { $where_clauses[] = "m.request_type=?"; $params[] = $type_filter;     $types .= "s"; }
$where_sql = implode(" AND ", $where_clauses);

$sql = "SELECT m.*,r.first_name,r.last_name,r.contact_number,r.date_of_birth,
               approver.username as approved_by_name, processor.username as processed_by_name
        FROM tbl_medical_assistance m
        JOIN tbl_residents r ON m.resident_id=r.resident_id
        LEFT JOIN tbl_users approver ON m.approved_by=approver.user_id
        LEFT JOIN tbl_users processor ON m.processed_by=processor.user_id
        WHERE $where_sql
        ORDER BY CASE m.priority WHEN 'Emergency' THEN 1 WHEN 'Urgent' THEN 2 ELSE 3 END, m.request_date DESC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

$stats = [];
$stats['pending']      = $conn->query("SELECT COUNT(*) as c FROM tbl_medical_assistance WHERE status='Pending'")->fetch_assoc()['c'];
$stats['approved']     = $conn->query("SELECT COUNT(*) as c FROM tbl_medical_assistance WHERE status='Approved'")->fetch_assoc()['c'];
$stats['released']     = $conn->query("SELECT COUNT(*) as c FROM tbl_medical_assistance WHERE status='Released'")->fetch_assoc()['c'];
$stats['total_amount'] = $conn->query("SELECT IFNULL(SUM(approved_amount),0) as t FROM tbl_medical_assistance WHERE status IN('Approved','Released')")->fetch_assoc()['t'];

include '../../includes/header.php';
?>

<link rel="stylesheet" href="/barangaylink1/assets/css/_db_shared.css">

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon" style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#9f7aea,#6b46c1);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(159,122,234,.4);">
                <i class="fas fa-hand-holding-medical"></i>
            </div>
            <div>
                <div class="rm-hero__title">Medical Assistance Requests</div>
                <div class="rm-hero__sub">Manage financial medical assistance applications</div>
            </div>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--purple"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-purple)"><?php echo number_format($stats['pending']); ?></div><div class="db-stat-card__label">Pending Requests</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--purple"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($stats['approved']); ?></div><div class="db-stat-card__label">Approved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-hand-holding-heart"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo number_format($stats['released']); ?></div><div class="db-stat-card__label">Released</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-peso-sign"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark);font-size:1.1rem;">₱<?php echo number_format($stats['total_amount'], 2); ?></div><div class="db-stat-card__label">Total Assistance</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
</div>

<!-- Status Tabs + Filters -->
<div class="db-panel" style="margin-bottom:18px;">
    <div class="db-panel__body" style="padding:0;">
        <!-- Tabs -->
        <div style="display:flex;border-bottom:1px solid var(--db-border);padding:0 18px;">
            <?php
            $tabs = ['Pending' => $stats['pending'], 'Approved' => $stats['approved'], 'Released' => $stats['released'], 'Rejected' => ''];
            foreach ($tabs as $tab => $count):
                $active = $status_filter === $tab;
            ?>
            <a href="?status=<?php echo $tab; ?>" style="padding:14px 18px;text-decoration:none;font-size:13px;font-weight:600;color:<?php echo $active ? 'var(--db-primary)' : 'var(--db-muted)'; ?>;border-bottom:2px solid <?php echo $active ? 'var(--db-primary)' : 'transparent'; ?>;margin-bottom:-1px;transition:all .2s;display:flex;align-items:center;gap:6px;">
                <?php echo $tab; ?>
                <?php if ($count !== ''): ?>
                <span style="background:<?php echo $active ? 'var(--db-primary)' : 'var(--db-surf3)'; ?>;color:<?php echo $active ? '#fff' : 'var(--db-muted)'; ?>;padding:1px 7px;border-radius:10px;font-size:11px;"><?php echo $count; ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <!-- Inline filters -->
        <div style="padding:12px 18px;">
            <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div style="min-width:160px;">
                    <label class="db-form-label" style="margin-bottom:5px;">Priority</label>
                    <select name="priority" class="db-form-select">
                        <option value="">All Priorities</option>
                        <?php foreach(['Emergency','Urgent','Normal'] as $p): ?>
                        <option value="<?php echo $p; ?>" <?php echo $priority_filter===$p?'selected':''; ?>><?php echo $p; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width:160px;">
                    <label class="db-form-label" style="margin-bottom:5px;">Type</label>
                    <select name="type" class="db-form-select">
                        <option value="">All Types</option>
                        <?php foreach(['Medicine','Laboratory','Hospitalization','Surgery','Other'] as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $type_filter===$t?'selected':''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-bottom:1px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--purple"><i class="fas fa-list"></i></div>
            <h2><?php echo $status_filter; ?> Requests</h2>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Request Date</th>
                    <th>Resident</th>
                    <th>Type</th>
                    <th>Priority</th>
                    <th>Diagnosis</th>
                    <th>Est. Amount</th>
                    <th>Approved Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($requests->num_rows > 0): ?>
                <?php while ($req = $requests->fetch_assoc()):
                    $age = $req['date_of_birth'] ? floor((time()-strtotime($req['date_of_birth']))/31556926) : 'N/A';
                    $pri_badge = match($req['priority']) {
                        'Emergency' => 'db-badge--rose',
                        'Urgent'    => 'db-badge--amber',
                        default     => 'db-badge--sky',
                    };
                    $sta_badge = match($req['status']) {
                        'Approved' => 'db-badge--success',
                        'Released' => 'db-badge--sky',
                        'Rejected' => 'db-badge--rose',
                        default    => 'db-badge--amber',
                    };
                ?>
                <tr>
                    <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($req['request_date'])); ?></span></td>
                    <td>
                        <div style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($req['first_name'].' '.$req['last_name']); ?></div>
                        <div style="font-size:11px;color:var(--db-muted);"><?php echo $age; ?> yrs · <?php echo htmlspecialchars($req['contact_number']); ?></div>
                    </td>
                    <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($req['request_type']); ?></span></td>
                    <td><span class="db-badge <?php echo $pri_badge; ?>"><?php echo $req['priority']; ?></span></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($req['diagnosis'] ?: '—'); ?></span></td>
                    <td style="font-weight:600;font-size:13px;">₱<?php echo number_format($req['estimated_amount'], 2); ?></td>
                    <td>
                        <?php if ($req['approved_amount']): ?>
                        <span style="font-weight:700;color:var(--db-success);font-size:13px;">₱<?php echo number_format($req['approved_amount'], 2); ?></span>
                        <?php else: ?><span style="color:var(--db-muted);">—</span><?php endif; ?>
                    </td>
                    <td><span class="db-badge <?php echo $sta_badge; ?>"><?php echo $req['status']; ?></span></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="db-btn db-btn--ghost db-btn--sm" onclick='viewDetails(<?php echo json_encode($req); ?>)' title="View"><i class="fas fa-eye"></i></button>
                            <?php if ($req['status'] === 'Pending'): ?>
                            <button class="db-btn db-btn--success db-btn--sm" onclick='approveRequest(<?php echo json_encode($req); ?>)' title="Approve"><i class="fas fa-check"></i></button>
                            <button class="db-btn db-btn--sm" style="background:var(--db-rose);color:#fff;border:none;border-radius:7px;padding:5px 10px;cursor:pointer;" onclick='rejectRequest(<?php echo $req["assistance_id"]; ?>)' title="Reject"><i class="fas fa-times"></i></button>
                            <?php elseif ($req['status'] === 'Approved'): ?>
                            <button class="db-btn db-btn--primary db-btn--sm" onclick='releaseAssistance(<?php echo $req["assistance_id"]; ?>)' title="Release"><i class="fas fa-hand-holding-heart"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="9">
                    <div style="text-align:center;padding:40px;color:var(--db-muted);">
                        <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                        No <?php echo strtolower($status_filter); ?> requests found
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /padding wrapper -->

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header" style="background:linear-gradient(135deg,#48bb78,#276749);">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-check"></i> Approve Medical Assistance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="assistance_id" id="approve_id">
                <div class="modal-body" style="padding:20px;">
                    <div class="db-form-group">
                        <label class="db-form-label">Approved Amount (₱) <span style="color:var(--db-rose);">*</span></label>
                        <input type="number" name="approved_amount" id="approve_amount" class="db-form-control" step="0.01" required>
                        <div style="font-size:12px;color:var(--db-muted);margin-top:4px;">Requested: ₱<span id="req_amount"></span></div>
                    </div>
                    <div class="db-form-group">
                        <label class="db-form-label">Remarks</label>
                        <textarea name="remarks" class="db-form-textarea" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Approve Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header" style="background:linear-gradient(135deg,#f56565,#c53030);">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-times"></i> Reject Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="assistance_id" id="reject_id">
                <div class="modal-body" style="padding:20px;">
                    <div class="db-form-group">
                        <label class="db-form-label">Reason for Rejection <span style="color:var(--db-rose);">*</span></label>
                        <textarea name="remarks" class="db-form-textarea" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--sm" style="background:var(--db-rose);color:#fff;border:none;padding:8px 18px;border-radius:8px;font-weight:600;cursor:pointer;"><i class="fas fa-times"></i> Reject Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Release Modal -->
<div class="modal fade" id="releaseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header" style="background:linear-gradient(135deg,#4299e1,#2b6cb0);">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-hand-holding-heart"></i> Release Assistance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="assistance_id" id="release_id">
                <div class="modal-body" style="padding:20px;">
                    <div class="db-form-group">
                        <label class="db-form-label">Release Notes</label>
                        <textarea name="remarks" class="db-form-textarea" rows="3" placeholder="Optional notes about the release…"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-hand-holding-heart"></i> Confirm Release</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveRequest(req) {
    document.getElementById('approve_id').value     = req.assistance_id;
    document.getElementById('approve_amount').value = req.estimated_amount;
    document.getElementById('req_amount').textContent = parseFloat(req.estimated_amount).toFixed(2);
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}
function rejectRequest(id) {
    document.getElementById('reject_id').value = id;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
function releaseAssistance(id) {
    document.getElementById('release_id').value = id;
    new bootstrap.Modal(document.getElementById('releaseModal')).show();
}

const infoRow = (label, value) =>
    `<div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--db-border);font-size:13px;">
        <span style="min-width:160px;color:var(--db-muted);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;padding-top:2px;">${label}</span>
        <span style="flex:1;color:var(--db-text);">${value}</span>
    </div>`;

function viewDetails(req) {
    const age = req.date_of_birth ? Math.floor((new Date()-new Date(req.date_of_birth))/31556926000) : 'N/A';
    const fmt = d => new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
    const priB = req.priority==='Emergency'?'db-badge--rose':(req.priority==='Urgent'?'db-badge--amber':'db-badge--sky');
    const staB = req.status==='Approved'?'db-badge--success':(req.status==='Released'?'db-badge--sky':(req.status==='Rejected'?'db-badge--rose':'db-badge--amber'));

    const body = `
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Patient</div>
            ${infoRow('Name','<strong>'+req.first_name+' '+req.last_name+'</strong>')}
            ${infoRow('Age', age+' years old')}
            ${infoRow('Contact', req.contact_number||'N/A')}
        </div>
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Request Details</div>
            ${infoRow('Type','<span class="db-badge db-badge--sky">'+req.request_type+'</span>')}
            ${infoRow('Priority','<span class="db-badge '+priB+'">'+req.priority+'</span>')}
            ${infoRow('Status','<span class="db-badge '+staB+'">'+req.status+'</span>')}
            ${infoRow('Date', fmt(req.request_date))}
            ${req.diagnosis ? infoRow('Diagnosis', req.diagnosis) : ''}
            ${infoRow('Requested Assistance', req.requested_assistance)}
            ${req.hospital_name    ? infoRow('Hospital/Clinic', req.hospital_name)    : ''}
            ${req.additional_notes ? infoRow('Notes',           req.additional_notes) : ''}
        </div>
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Financial</div>
            ${infoRow('Estimated Amount','₱'+parseFloat(req.estimated_amount).toLocaleString('en-PH',{minimumFractionDigits:2}))}
            ${req.approved_amount ? infoRow('Approved Amount','<strong style="color:var(--db-success);">₱'+parseFloat(req.approved_amount).toLocaleString('en-PH',{minimumFractionDigits:2})+'</strong>') : ''}
            ${req.approved_date   ? infoRow('Approved Date',   fmt(req.approved_date))  : ''}
            ${req.released_date   ? infoRow('Released Date',   fmt(req.released_date))  : ''}
            ${req.approved_by_name  ? infoRow('Approved By',  req.approved_by_name)  : ''}
            ${req.processed_by_name ? infoRow('Processed By', req.processed_by_name) : ''}
        </div>
        ${req.remarks ? `<div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Remarks</div>${infoRow('',req.remarks.replace(/\n/g,'<br>'))}</div>` : ''}`;

    const el = document.createElement('div');
    el.className='modal fade'; el.setAttribute('tabindex','-1');
    el.innerHTML=`<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header" style="background:linear-gradient(135deg,#9f7aea,#6b46c1);">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-hand-holding-medical"></i> Medical Assistance Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:20px;max-height:65vh;overflow-y:auto;">${body}</div>
        <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
            ${req.status==='Pending' ? `
                <button class="db-btn db-btn--success" onclick="this.closest('.modal').dispatchEvent(new CustomEvent('do-approve'))"><i class="fas fa-check"></i> Approve</button>
                <button class="db-btn db-btn--sm" style="background:var(--db-rose);color:#fff;border:none;padding:8px 14px;border-radius:8px;font-weight:600;cursor:pointer;" onclick="this.closest('.modal').dispatchEvent(new CustomEvent('do-reject'))"><i class="fas fa-times"></i> Reject</button>
            ` : ''}
            ${req.status==='Approved' ? `<button class="db-btn db-btn--primary" onclick="this.closest('.modal').dispatchEvent(new CustomEvent('do-release'))"><i class="fas fa-hand-holding-heart"></i> Release</button>` : ''}
            <button class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
        </div>
    </div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el);
    el.addEventListener('do-approve', () => { m.hide(); el.addEventListener('hidden.bs.modal',()=>{ el.remove(); approveRequest(req); }); });
    el.addEventListener('do-reject',  () => { m.hide(); el.addEventListener('hidden.bs.modal',()=>{ el.remove(); rejectRequest(req.assistance_id); }); });
    el.addEventListener('do-release', () => { m.hide(); el.addEventListener('hidden.bs.modal',()=>{ el.remove(); releaseAssistance(req.assistance_id); }); });
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.transition='opacity .4s'; a.style.opacity='0';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>