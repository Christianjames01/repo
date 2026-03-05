<?php
require_once '../../config/config.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Disease Surveillance';

$search    = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$status    = isset($_GET['status'])    ? $_GET['status']          : '';
$severity  = isset($_GET['severity'])  ? $_GET['severity']        : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from']       : '';
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']         : '';

$where_clauses = ["1=1"]; $params = []; $types = "";

if ($search) {
    $where_clauses[] = "(disease_name LIKE ? OR location LIKE ? OR reported_by LIKE ?)";
    $sp = "%$search%"; $params[] = $sp; $params[] = $sp; $params[] = $sp; $types .= "sss";
}
if ($status)    { $where_clauses[] = "status = ?";       $params[] = $status;    $types .= "s"; }
if ($severity)  { $where_clauses[] = "severity = ?";     $params[] = $severity;  $types .= "s"; }
if ($date_from) { $where_clauses[] = "report_date >= ?"; $params[] = $date_from; $types .= "s"; }
if ($date_to)   { $where_clauses[] = "report_date <= ?"; $params[] = $date_to;   $types .= "s"; }

$where_sql = implode(" AND ", $where_clauses);

$sql = "SELECT * FROM tbl_disease_surveillance WHERE $where_sql ORDER BY report_date DESC, created_at DESC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();
$stmt->close();

$stats = [
    'total'         => $conn->query("SELECT COUNT(*) as c FROM tbl_disease_surveillance")->fetch_assoc()['c'],
    'active'        => $conn->query("SELECT COUNT(*) as c FROM tbl_disease_surveillance WHERE status='Active'")->fetch_assoc()['c'],
    'resolved'      => $conn->query("SELECT COUNT(*) as c FROM tbl_disease_surveillance WHERE status='Resolved'")->fetch_assoc()['c'],
    'high_severity' => $conn->query("SELECT COUNT(*) as c FROM tbl_disease_surveillance WHERE severity='High' AND status='Active'")->fetch_assoc()['c'],
];

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
            <div class="rm-hero__icon" style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#f56565,#c53030);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(245,101,101,.4);">
                <i class="fas fa-virus"></i>
            </div>
            <div>
                <div class="rm-hero__title">Disease Surveillance</div>
                <div class="rm-hero__sub">Monitor and track disease outbreaks in the barangay</div>
            </div>
        </div>
        <button class="db-btn db-btn--glass db-btn--sm" onclick="openAddModal()">
            <i class="fas fa-plus"></i> New Report
        </button>
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
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-virus"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo number_format($stats['total']); ?></div><div class="db-stat-card__label">Total Reports</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon" style="background:rgba(245,101,101,.12);"><i class="fas fa-exclamation-circle" style="color:#f56565;"></i></div>
        <div><div class="db-stat-card__num" style="color:#f56565;"><?php echo number_format($stats['active']); ?></div><div class="db-stat-card__label">Active Cases</div></div>
        <div class="db-stat-card__bar" style="background:linear-gradient(90deg,#f56565,#fc8181);"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($stats['resolved']); ?></div><div class="db-stat-card__label">Resolved Cases</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--purple"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-purple)"><?php echo number_format($stats['high_severity']); ?></div><div class="db-stat-card__label">High Severity Active</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--purple"></div>
    </div>
</div>

<!-- Filter Bar -->
<div class="db-panel" style="margin-bottom:18px;">
    <div class="db-panel__body" style="padding:14px 18px;">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label class="db-form-label" style="margin-bottom:5px;">Search</label>
                <div style="position:relative;">
                    <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--db-muted);font-size:12px;"></i>
                    <input type="text" name="search" class="db-form-control" style="padding-left:32px;"
                           placeholder="Search disease, location…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div style="min-width:140px;">
                <label class="db-form-label" style="margin-bottom:5px;">Status</label>
                <select name="status" class="db-form-select">
                    <option value="">All Status</option>
                    <?php foreach(['Active','Monitoring','Resolved'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status===$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:140px;">
                <label class="db-form-label" style="margin-bottom:5px;">Severity</label>
                <select name="severity" class="db-form-select">
                    <option value="">All Severity</option>
                    <?php foreach(['Low','Medium','High'] as $sv): ?>
                    <option value="<?php echo $sv; ?>" <?php echo $severity===$sv?'selected':''; ?>><?php echo $sv; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:140px;">
                <label class="db-form-label" style="margin-bottom:5px;">From</label>
                <input type="date" name="date_from" class="db-form-control" value="<?php echo $date_from; ?>">
            </div>
            <div style="min-width:140px;">
                <label class="db-form-label" style="margin-bottom:5px;">To</label>
                <input type="date" name="date_to" class="db-form-control" value="<?php echo $date_to; ?>">
            </div>
            <div style="display:flex;gap:8px;padding-bottom:1px;">
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($search||$status||$severity||$date_from||$date_to): ?>
                <a href="disease-surveillance.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon" style="background:rgba(245,101,101,.12);"><i class="fas fa-list" style="color:#f56565;"></i></div>
            <h2>Surveillance Records</h2>
            <span class="db-badge db-badge--muted"><?php echo number_format($stats['total']); ?> total</span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Report Date</th>
                    <th>Disease</th>
                    <th>Location</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Affected</th>
                    <th>Age Group</th>
                    <th>Reported By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($records->num_rows > 0): ?>
                <?php while ($rec = $records->fetch_assoc()):
                    $sev_badge = match($rec['severity']) {
                        'High'   => 'db-badge--rose',
                        'Medium' => 'db-badge--amber',
                        default  => 'db-badge--sky',
                    };
                    $sta_badge = match($rec['status']) {
                        'Active'     => 'db-badge--rose',
                        'Monitoring' => 'db-badge--amber',
                        default      => 'db-badge--success',
                    };
                ?>
                <tr>
                    <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($rec['report_date'])); ?></span></td>
                    <td style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($rec['disease_name']); ?></td>
                    <td>
                        <span class="db-text-sm"><i class="fas fa-map-marker-alt" style="color:var(--db-muted);font-size:11px;margin-right:3px;"></i><?php echo htmlspecialchars($rec['location']); ?></span>
                    </td>
                    <td><span class="db-badge <?php echo $sev_badge; ?>"><?php echo $rec['severity']; ?></span></td>
                    <td><span class="db-badge <?php echo $sta_badge; ?>"><?php echo $rec['status']; ?></span></td>
                    <td>
                        <span style="font-weight:700;color:#f56565;font-size:13px;"><?php echo $rec['affected_count']; ?></span>
                        <span style="font-size:11px;color:var(--db-muted);"> person(s)</span>
                    </td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($rec['age_group'] ?: '—'); ?></span></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($rec['reported_by']); ?></span></td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <button class="db-btn db-btn--ghost db-btn--sm" onclick='viewReport(<?php echo json_encode($rec); ?>)' title="View"><i class="fas fa-eye"></i></button>
                            <button class="db-btn db-btn--primary db-btn--sm" onclick='updateReport(<?php echo json_encode($rec); ?>)' title="Update"><i class="fas fa-edit"></i></button>
                            <?php if ($rec['status'] !== 'Resolved'): ?>
                            <button class="db-btn db-btn--success db-btn--sm" onclick='confirmResolve(<?php echo json_encode($rec); ?>)' title="Resolve"><i class="fas fa-check"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="9">
                    <div style="text-align:center;padding:40px;color:var(--db-muted);">
                        <i class="fas fa-virus" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                        No disease surveillance records found
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /padding wrapper -->

<!-- Add Report Modal -->
<div class="modal fade" id="addReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header" style="background:linear-gradient(135deg,#f56565,#c53030);">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;">
                    <i class="fas fa-virus"></i> New Disease Surveillance Report
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="actions/add-disease-report.php" method="POST">
                <div class="modal-body" style="padding:20px;">
                    <div class="db-form-row db-form-row--2">
                        <div class="db-form-group">
                            <label class="db-form-label">Disease Name <span style="color:var(--db-rose);">*</span></label>
                            <input type="text" name="disease_name" class="db-form-control" required placeholder="e.g., Dengue, COVID-19">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Report Date <span style="color:var(--db-rose);">*</span></label>
                            <input type="date" name="report_date" class="db-form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Location <span style="color:var(--db-rose);">*</span></label>
                            <input type="text" name="location" class="db-form-control" required placeholder="e.g., Purok 1, Zone 2">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Affected Count <span style="color:var(--db-rose);">*</span></label>
                            <input type="number" name="affected_count" class="db-form-control" required min="1" value="1">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Age Group</label>
                            <select name="age_group" class="db-form-select">
                                <option value="">Select Age Group</option>
                                <?php foreach(['0-5 years','6-12 years','13-18 years','19-35 years','36-60 years','60+ years','Mixed'] as $ag): ?>
                                <option value="<?php echo $ag; ?>"><?php echo $ag; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Severity <span style="color:var(--db-rose);">*</span></label>
                            <select name="severity" class="db-form-select" required>
                                <option value="">Select Severity</option>
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Status <span style="color:var(--db-rose);">*</span></label>
                            <select name="status" class="db-form-select" required>
                                <option value="Active">Active</option>
                                <option value="Monitoring">Monitoring</option>
                                <option value="Resolved">Resolved</option>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Reported By <span style="color:var(--db-rose);">*</span></label>
                            <input type="text" name="reported_by" class="db-form-control" required placeholder="Name of reporter">
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Symptoms</label>
                            <textarea name="symptoms" class="db-form-textarea" rows="2" placeholder="Common symptoms observed"></textarea>
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Actions Taken</label>
                            <textarea name="actions_taken" class="db-form-textarea" rows="3" placeholder="Describe actions taken or planned"></textarea>
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Remarks</label>
                            <textarea name="remarks" class="db-form-textarea" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--sm" style="background:linear-gradient(135deg,#f56565,#c53030);color:#fff;border:none;padding:8px 18px;border-radius:8px;font-weight:600;cursor:pointer;"><i class="fas fa-save"></i> Save Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() { new bootstrap.Modal(document.getElementById('addReportModal')).show(); }

const infoRow = (label, value) =>
    `<div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--db-border);font-size:13px;">
        <span style="min-width:160px;color:var(--db-muted);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;padding-top:2px;">${label}</span>
        <span style="flex:1;color:var(--db-text);">${value}</span>
    </div>`;

function viewReport(r) {
    const sevBadge = r.severity==='High'?'db-badge--rose':(r.severity==='Medium'?'db-badge--amber':'db-badge--sky');
    const staBadge = r.status==='Active'?'db-badge--rose':(r.status==='Monitoring'?'db-badge--amber':'db-badge--success');
    const fmt = d => new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});

    const body = `
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Disease Information</div>
            ${infoRow('Disease Name','<strong>'+r.disease_name+'</strong>')}
            ${infoRow('Report Date', fmt(r.report_date))}
            ${infoRow('Location','<i class="fas fa-map-marker-alt" style="margin-right:4px;"></i>'+r.location)}
            ${infoRow('Severity','<span class="db-badge '+sevBadge+'">'+r.severity+'</span>')}
            ${infoRow('Status','<span class="db-badge '+staBadge+'">'+r.status+'</span>')}
        </div>
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Affected Population</div>
            ${infoRow('Affected Count','<span style="font-weight:700;color:#f56565;">'+r.affected_count+' person(s)</span>')}
            ${r.age_group ? infoRow('Age Group', r.age_group) : ''}
            ${r.symptoms  ? infoRow('Symptoms',  r.symptoms)  : ''}
        </div>
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Response</div>
            ${infoRow('Reported By', r.reported_by)}
            ${r.actions_taken ? infoRow('Actions Taken', r.actions_taken.replace(/\n/g,'<br>')) : ''}
            ${r.remarks       ? infoRow('Remarks',       r.remarks.replace(/\n/g,'<br>'))       : ''}
        </div>`;

    const el = document.createElement('div');
    el.className='modal fade'; el.setAttribute('tabindex','-1');
    el.innerHTML=`<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header" style="background:linear-gradient(135deg,#f56565,#c53030);">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-virus"></i> Disease Report Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:20px;max-height:65vh;overflow-y:auto;">${body}</div>
        <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
            <button class="db-btn db-btn--primary" onclick="this.closest('.modal').dispatchEvent(new Event('do-edit'))"><i class="fas fa-edit"></i> Update</button>
            ${r.status!=='Resolved'?`<button class="db-btn db-btn--success" onclick="this.closest('.modal').dispatchEvent(new Event('do-resolve'))"><i class="fas fa-check"></i> Mark Resolved</button>`:''}
            <button class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
        </div>
    </div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el);
    el.addEventListener('do-edit',    () => { m.hide(); el.addEventListener('hidden.bs.modal', () => { el.remove(); updateReport(r); }); });
    el.addEventListener('do-resolve', () => { m.hide(); el.addEventListener('hidden.bs.modal', () => { el.remove(); confirmResolve(r); }); });
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
}

function updateReport(r) {
    const ages = ['0-5 years','6-12 years','13-18 years','19-35 years','36-60 years','60+ years','Mixed'];
    const opt = (v, sel) => `<option value="${v}" ${sel===v?'selected':''}>${v}</option>`;

    const el = document.createElement('div');
    el.className='modal fade'; el.setAttribute('tabindex','-1');
    el.innerHTML=`<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header" style="background:linear-gradient(135deg,#4299e1,#2b6cb0);">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-edit"></i> Update Disease Report</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form action="actions/update-disease-report.php" method="POST">
            <input type="hidden" name="surveillance_id" value="${r.surveillance_id}">
            <div class="modal-body" style="padding:20px;max-height:65vh;overflow-y:auto;">
                <div class="db-form-row db-form-row--2">
                    <div class="db-form-group"><label class="db-form-label">Disease Name *</label><input type="text" name="disease_name" class="db-form-control" required value="${r.disease_name}"></div>
                    <div class="db-form-group"><label class="db-form-label">Report Date *</label><input type="date" name="report_date" class="db-form-control" required value="${r.report_date}"></div>
                    <div class="db-form-group"><label class="db-form-label">Location *</label><input type="text" name="location" class="db-form-control" required value="${r.location}"></div>
                    <div class="db-form-group"><label class="db-form-label">Affected Count *</label><input type="number" name="affected_count" class="db-form-control" required min="1" value="${r.affected_count}"></div>
                    <div class="db-form-group"><label class="db-form-label">Age Group</label><select name="age_group" class="db-form-select"><option value="">Select</option>${ages.map(a=>opt(a,r.age_group)).join('')}</select></div>
                    <div class="db-form-group"><label class="db-form-label">Severity *</label><select name="severity" class="db-form-select" required>${['Low','Medium','High'].map(v=>opt(v,r.severity)).join('')}</select></div>
                    <div class="db-form-group"><label class="db-form-label">Status *</label><select name="status" class="db-form-select" required>${['Active','Monitoring','Resolved'].map(v=>opt(v,r.status)).join('')}</select></div>
                    <div class="db-form-group"><label class="db-form-label">Reported By *</label><input type="text" name="reported_by" class="db-form-control" required value="${r.reported_by}"></div>
                    <div class="db-form-group" style="grid-column:1/-1;"><label class="db-form-label">Symptoms</label><textarea name="symptoms" class="db-form-textarea" rows="2">${r.symptoms||''}</textarea></div>
                    <div class="db-form-group" style="grid-column:1/-1;"><label class="db-form-label">Actions Taken</label><textarea name="actions_taken" class="db-form-textarea" rows="3">${r.actions_taken||''}</textarea></div>
                    <div class="db-form-group" style="grid-column:1/-1;"><label class="db-form-label">Remarks</label><textarea name="remarks" class="db-form-textarea" rows="2">${r.remarks||''}</textarea></div>
                </div>
            </div>
            <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Update Report</button>
            </div>
        </form>
    </div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el);
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
}

function confirmResolve(r) {
    const el = document.createElement('div');
    el.className='modal fade'; el.setAttribute('tabindex','-1');
    el.innerHTML=`<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header" style="background:linear-gradient(135deg,#48bb78,#276749);">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-check-circle"></i> Confirm Resolution</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:20px;text-align:center;">
            <i class="fas fa-check-circle" style="font-size:3rem;color:#48bb78;margin-bottom:1rem;display:block;"></i>
            <h5 style="margin:0 0 8px;color:var(--db-text);">Mark Report as Resolved?</h5>
            <p style="color:var(--db-muted);font-size:13px;margin-bottom:16px;">This will indicate the outbreak has been contained.</p>
            <div style="background:var(--db-surf2);padding:12px 16px;border-radius:10px;text-align:left;font-size:13px;margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;padding:4px 0;"><span style="color:var(--db-muted);font-weight:600;">Disease</span><span>${r.disease_name}</span></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;"><span style="color:var(--db-muted);font-weight:600;">Location</span><span>${r.location}</span></div>
                <div style="display:flex;justify-content:space-between;padding:4px 0;"><span style="color:var(--db-muted);font-weight:600;">Affected</span><span style="color:#f56565;font-weight:700;">${r.affected_count} person(s)</span></div>
            </div>
        </div>
        <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
            <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
            <form action="actions/resolve-disease-report.php" method="POST" style="display:inline;">
                <input type="hidden" name="surveillance_id" value="${r.surveillance_id}">
                <button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Yes, Mark Resolved</button>
            </form>
        </div>
    </div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el);
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