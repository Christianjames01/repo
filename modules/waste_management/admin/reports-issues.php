<?php
require_once('../../../config/config.php');
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = 'Waste Issues & Reports';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $issue_id = (int)$_POST['issue_id'];
    $status   = $_POST['status'];
    $stmt = $conn->prepare("UPDATE tbl_waste_issues SET status=?, updated_at=NOW() WHERE issue_id=?");
    $stmt->bind_param("si", $status, $issue_id);
    $_SESSION['temp_success'] = $stmt->execute() ? "Issue status updated successfully!" : "Failed to update status.";
    header('Location: reports-issues.php'); exit;
}

$success_message = $error_message = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }

// Pagination & filters
$per_page      = 15;
$current_page  = max(1, (int)($_GET['page'] ?? 1));
$offset        = ($current_page - 1) * $per_page;
$status_filter = $_GET['status'] ?? '';
$urgency_filter = $_GET['urgency'] ?? '';

$where = []; $params = []; $types = '';
if ($status_filter)  { $where[] = "status=?";  $params[] = $status_filter;  $types .= 's'; }
if ($urgency_filter) { $where[] = "urgency=?"; $params[] = $urgency_filter; $types .= 's'; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$count_sql = "SELECT COUNT(*) as total FROM tbl_waste_issues $where_sql";
if ($params) { $cs=$conn->prepare($count_sql); $cs->bind_param($types,...$params); $cs->execute(); $cr=$cs->get_result()->fetch_assoc(); }
else { $cr = $conn->query($count_sql)->fetch_assoc(); }
$total_records = $cr['total'];
$total_pages   = ceil($total_records / $per_page);

// Issues
$issues_sql = "SELECT * FROM tbl_waste_issues $where_sql ORDER BY CASE WHEN status='pending' THEN 1 WHEN status='in progress' THEN 2 WHEN status='acknowledged' THEN 3 WHEN status='resolved' THEN 4 ELSE 5 END, CASE urgency WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 END, created_at DESC LIMIT ? OFFSET ?";
$ip = array_merge($params, [$per_page, $offset]); $it = $types . 'ii';
$is=$conn->prepare($issues_sql); $is->bind_param($it,...$ip); $is->execute();
$issues_result = $is->get_result();

// Stats
$stats = $conn->query("SELECT COUNT(*) as total, SUM(status='pending') as pending, SUM(status='in progress') as in_progress, SUM(status='resolved') as resolved, SUM(urgency='critical') as critical, SUM(urgency='high') as high FROM tbl_waste_issues")->fetch_assoc();

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include('../../../includes/header.php');

function urg_badge($u) {
    $map = ['low'=>'db-badge--success','medium'=>'db-badge--warning','high'=>'db-badge--danger','critical'=>'db-badge--dark'];
    $icons = ['low'=>'fa-circle','medium'=>'fa-exclamation-circle','high'=>'fa-exclamation-triangle','critical'=>'fa-skull-crossbones'];
    $u = strtolower(trim($u));
    return '<span class="db-badge '.($map[$u]??'db-badge--muted').'"><i class="fas '.($icons[$u]??'fa-circle').' me-1"></i>'.ucfirst($u).'</span>';
}
function stat_badge($s) {
    $map = ['resolved'=>'db-badge--success','in progress'=>'db-badge--primary','pending'=>'db-badge--warning','acknowledged'=>'db-badge--info','closed'=>'db-badge--muted'];
    $s = strtolower(trim($s));
    return '<span class="db-badge '.($map[$s]??'db-badge--muted').'">'.ucfirst($s).'</span>';
}
function build_qs($exclude=[]) {
    $p = $_GET; foreach ($exclude as $k) unset($p[$k]);
    return $p ? '&'.http_build_query($p) : '';
}
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#f59e0b,#b45309);">
                <i class="fas fa-exclamation-circle" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Waste Management
                </div>
                <h1 class="db-hero__title">Waste Issues & Reports</h1>
                <p class="db-hero__sub">Review, track, and resolve reported waste issues from the community</p>
            </div>
        </div>
        <div class="db-hero__right">
            <div class="db-hero__datetime">
                <div class="db-hero__date"><i class="fas fa-calendar-day"></i><span><?php echo date('F j, Y'); ?></span></div>
                <div class="db-hero__time" id="db-live-time"><?php echo date('g:i:s A'); ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($success_message): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($success_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ═══ STAT CARDS ═══ -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-clipboard-list"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['total']); ?></div>
            <div class="db-stat-card__label">Total Reports</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['pending']); ?></div>
            <div class="db-stat-card__label">Pending</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-spinner"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['in_progress']); ?></div>
            <div class="db-stat-card__label">In Progress</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['resolved']); ?></div>
            <div class="db-stat-card__label">Resolved</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-skull-crossbones"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['critical']); ?></div>
            <div class="db-stat-card__label">Critical</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </div>
</div>

<!-- ═══ FILTERS ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-filter"></i></span>
            <h2>Filter Issues</h2>
        </div>
    </div>
    <div style="padding:16px 22px;">
        <form method="GET" action="reports-issues.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="db-field" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Status</label>
                <select name="status" class="db-input">
                    <option value="">All Statuses</option>
                    <?php foreach (['pending','in progress','resolved','closed','acknowledged'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="db-field" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Urgency</label>
                <select name="urgency" class="db-input">
                    <option value="">All Urgency Levels</option>
                    <?php foreach (['low','medium','high','critical'] as $u): ?>
                    <option value="<?php echo $u; ?>" <?php echo $urgency_filter===$u?'selected':''; ?>><?php echo ucfirst($u); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;padding-bottom:0;">
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button>
                <a href="reports-issues.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ═══ ISSUES TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list"></i></span>
            <h2>Reported Issues <span style="font-family:'DM Mono',monospace;font-size:12px;color:var(--db-muted);font-weight:400;">(<?php echo number_format($total_records); ?> total)</span></h2>
        </div>
    </div>

    <?php if ($issues_result && $issues_result->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>ID</th><th>Issue Type</th><th>Location</th><th>Reporter</th><th>Urgency</th><th>Status</th><th>Date</th><th>Photo</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php while ($issue = $issues_result->fetch_assoc()): ?>
            <tr>
                <td><span class="db-id">#<?php echo $issue['issue_id']; ?></span></td>
                <td>
                    <strong><?php echo htmlspecialchars($issue['issue_type']); ?></strong>
                </td>
                <td>
                    <span class="db-text-sm">
                        <i class="fas fa-map-marker-alt me-1" style="color:var(--db-rose)"></i>
                        <?php echo htmlspecialchars(substr($issue['location'],0,32)).(strlen($issue['location'])>32?'…':''); ?>
                    </span>
                </td>
                <td>
                    <?php echo htmlspecialchars($issue['reporter_name'] ?? 'N/A'); ?><br>
                    <span class="db-text-sm"><?php echo htmlspecialchars($issue['reporter_contact'] ?? ''); ?></span>
                </td>
                <td><?php echo urg_badge($issue['urgency']); ?></td>
                <td><?php echo stat_badge($issue['status']); ?></td>
                <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($issue['created_at'])); ?></span></td>
                <td>
                    <?php if (!empty($issue['photo_path'])): ?>
                    <button class="db-icon-btn db-icon-btn--info" onclick="viewPhoto('<?php echo htmlspecialchars($issue['photo_path']); ?>',<?php echo $issue['issue_id']; ?>)" title="View Photo">
                        <i class="fas fa-image"></i>
                    </button>
                    <?php else: ?>
                    <span class="db-text-muted" style="font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="db-btn-group">
                        <button class="db-icon-btn db-icon-btn--info" onclick='viewIssue(<?php echo json_encode($issue); ?>)' title="View"><i class="fas fa-eye"></i></button>
                        <button class="db-icon-btn db-icon-btn--primary" onclick="openStatusModal(<?php echo $issue['issue_id']; ?>,'<?php echo htmlspecialchars($issue['status']); ?>')" title="Update Status"><i class="fas fa-edit"></i></button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="db-panel__footer" style="display:flex;justify-content:center;gap:4px;flex-wrap:wrap;">
        <?php if ($current_page > 1): ?>
        <a href="?page=<?php echo $current_page-1; ?><?php echo build_qs(['page']); ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i=max(1,$current_page-2); $i<=min($total_pages,$current_page+2); $i++): ?>
        <a href="?page=<?php echo $i; ?><?php echo build_qs(['page']); ?>"
           class="db-btn db-btn--sm <?php echo $i===$current_page ? 'db-btn--primary' : 'db-btn--ghost'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($current_page < $total_pages): ?>
        <a href="?page=<?php echo $current_page+1; ?><?php echo build_qs(['page']); ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-inbox"></i>
        <p>No issues found<?php echo ($status_filter||$urgency_filter) ? ' matching your filters.' : ' yet.'; ?></p>
        <?php if ($status_filter||$urgency_filter): ?>
        <a href="reports-issues.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-redo"></i> Clear Filters</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>


<!-- ═══ VIEW ISSUE MODAL ═══ -->
<div id="viewIssueModal" class="db-modal">
    <div class="db-modal__box" style="max-width:680px;">
        <div class="db-modal__header">
            <h3><i class="fas fa-file-alt"></i> Issue Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewIssueModal')">×</button>
        </div>
        <div class="db-modal__body" id="issueDetailsContent"></div>
    </div>
</div>

<!-- ═══ PHOTO MODAL ═══ -->
<div id="photoModal" class="db-modal db-modal--img">
    <div class="db-imgview">
        <button class="db-imgview__close" onclick="closeModal('photoModal')">×</button>
        <img id="photoModalImage" src="" alt="Issue Photo">
        <div id="photoCaption" class="db-imgview__cap"></div>
    </div>
</div>

<!-- ═══ UPDATE STATUS MODAL ═══ -->
<div id="updateStatusModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Update Issue Status</h3>
            <button class="db-modal__close" onclick="closeModal('updateStatusModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="issue_id" id="status_issue_id">
            <div class="db-field">
                <label>New Status <span class="req">*</span></label>
                <select name="status" id="status_select" class="db-input" required>
                    <option value="pending">Pending</option>
                    <option value="acknowledged">Acknowledged</option>
                    <option value="in progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div style="background:var(--db-sky-light);border-radius:var(--db-radius-sm);padding:10px 14px;font-size:12.5px;color:#0369a1;margin-bottom:16px;">
                <i class="fas fa-info-circle me-1"></i> The status will be updated immediately.
            </div>
            <div style="display:flex;gap:.75rem;">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('updateStatusModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<script>
setInterval(function() {
    const now = new Date(); let h=now.getHours(),m=now.getMinutes(),s=now.getSeconds();
    const ap=h>=12?'PM':'AM'; h=h%12||12;
    const el=document.getElementById('db-live-time');
    if(el) el.textContent=`${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')} ${ap}`;
},1000);

function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

const urgColors = {critical:'var(--db-rose)',high:'var(--db-danger)',medium:'var(--db-amber)',low:'var(--db-success)'};
const statColors = {resolved:'var(--db-success)','in progress':'var(--db-sky)',pending:'var(--db-amber)',acknowledged:'var(--db-info)',closed:'var(--db-muted)'};

function viewIssue(issue) {
    const photoHtml = issue.photo_path
        ? `<div style="margin-top:16px;"><div style="font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;margin-bottom:6px;">Photo Evidence</div><img src="../../../${issue.photo_path}" alt="Photo" style="width:100%;border-radius:10px;cursor:pointer;max-height:260px;object-fit:cover;" onclick="viewPhoto('${issue.photo_path}',${issue.issue_id})"></div>`
        : `<div style="background:var(--db-surf2);border-radius:var(--db-radius-sm);padding:10px 14px;font-size:12.5px;color:var(--db-muted);margin-top:12px;"><i class="fas fa-info-circle me-1"></i>No photo evidence provided.</div>`;
    document.getElementById('issueDetailsContent').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Issue Type</div><div style="font-weight:700;font-size:15px;">${issue.issue_type}</div></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Status</div><div style="font-weight:700;font-size:14px;color:${statColors[issue.status]??'var(--db-muted)'};">${issue.status.toUpperCase()}</div></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Reporter</div><div>${issue.reporter_name??'N/A'}</div><div style="font-size:11.5px;color:var(--db-muted);">${issue.reporter_contact??''}</div></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Urgency</div><div style="font-weight:700;color:${urgColors[issue.urgency]??'var(--db-muted)'};">${issue.urgency.toUpperCase()}</div></div>
        </div>
        <div style="margin-bottom:10px;"><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;"><i class="fas fa-map-marker-alt me-1" style="color:var(--db-rose)"></i>Location</div><div>${issue.location}</div></div>
        <div style="margin-bottom:10px;"><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Description</div><div style="line-height:1.75;font-size:13px;">${issue.description.replace(/\n/g,'<br>')}</div></div>
        ${photoHtml}
        <div style="margin-top:16px;display:flex;gap:8px;">
            <button class="db-btn db-btn--primary db-btn--sm" onclick="closeModal('viewIssueModal');setTimeout(()=>openStatusModal(${issue.issue_id},'${issue.status}'),250)"><i class="fas fa-edit"></i> Update Status</button>
            <button class="db-btn db-btn--ghost db-btn--sm" onclick="closeModal('viewIssueModal')">Close</button>
        </div>
    `;
    openModal('viewIssueModal');
}

function viewPhoto(path, id) {
    document.getElementById('photoModalImage').src = '../../../' + path;
    document.getElementById('photoCaption').textContent = 'Issue Report #' + id;
    openModal('photoModal');
}

function openStatusModal(id, currentStatus) {
    document.getElementById('status_issue_id').value = id;
    document.getElementById('status_select').value   = currentStatus || 'pending';
    openModal('updateStatusModal');
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php include('../../../includes/footer.php'); ?>