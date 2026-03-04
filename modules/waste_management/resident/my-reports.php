<?php
require_once('../../../config/config.php');
requireLogin();

$page_title = "My Waste Reports";
$user_id = $_SESSION['user_id'];

$records_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

$status_filter  = isset($_GET['status'])  ? sanitize($_GET['status'])  : '';
$urgency_filter = isset($_GET['urgency']) ? sanitize($_GET['urgency']) : '';
$search         = isset($_GET['search'])  ? sanitize($_GET['search'])  : '';

$where_conditions = ["reporter_id = ?"];
$params = [$user_id];
$types  = 'i';

if (!empty($status_filter))  { $where_conditions[] = "status = ?";  $params[] = $status_filter;  $types .= 's'; }
if (!empty($urgency_filter)) { $where_conditions[] = "urgency = ?"; $params[] = $urgency_filter; $types .= 's'; }
if (!empty($search)) {
    $where_conditions[] = "(issue_type LIKE ? OR location LIKE ? OR description LIKE ?)";
    $sp = "%{$search}%"; $params[] = $sp; $params[] = $sp; $params[] = $sp; $types .= 'sss';
}
$where_clause = implode(' AND ', $where_conditions);

$count_result  = fetchOne($conn, "SELECT COUNT(*) as total FROM tbl_waste_issues WHERE {$where_clause}", $params, $types);
$total_records = $count_result['total'] ?? 0;
$total_pages   = ceil($total_records / $records_per_page);

$sql = "SELECT issue_id, issue_type, location, description, urgency, status, photo_path, created_at
        FROM tbl_waste_issues WHERE {$where_clause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page; $params[] = $offset; $types .= 'ii';
$reports = fetchAll($conn, $sql, $params, $types);

$stats = fetchOne($conn,
    "SELECT COUNT(*) as total,
            SUM(CASE WHEN status='pending'     THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='in progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status='resolved'    THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN status='closed'      THEN 1 ELSE 0 END) as closed
     FROM tbl_waste_issues WHERE reporter_id = ?",
    [$user_id], 'i'
);

$extra_css = '<link rel="stylesheet" href="../../../assets/css/waste-pages.css?v=' . time() . '">';
require_once '../../../includes/header.php';
?>

<!-- ── PAGE HERO ── -->
<div class="wp-hero">
    <div class="wp-hero__ring wp-hero__ring--1"></div>
    <div class="wp-hero__ring wp-hero__ring--2"></div>
    <div class="wp-hero__ring wp-hero__ring--3"></div>
    <div class="wp-hero__inner">
        <div class="wp-hero__left">
            <div class="wp-hero__icon wp-hero__icon--amber">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div>
                <h1 class="wp-hero__title">My Waste Reports</h1>
                <p class="wp-hero__sub">Track the status of all your submitted waste issue reports</p>
            </div>
        </div>
        <div class="wp-hero__actions">
            <a href="report-issue.php" class="wp-btn wp-btn--amber">
                <i class="fas fa-plus"></i> Report New Issue
            </a>
        </div>
    </div>
</div>

<?php if ($msg = displayMessage()): ?>
<div style="margin-bottom:16px"><?php echo $msg; ?></div>
<?php endif; ?>

<!-- ── STAT CARDS ── -->
<div class="wp-stats-row">
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--indigo"><i class="fas fa-clipboard-list"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="wp-stat-card__label">Total Reports</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--indigo"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($stats['pending'] ?? 0); ?></div>
            <div class="wp-stat-card__label">Pending</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--amber"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--blue"><i class="fas fa-spinner"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($stats['in_progress'] ?? 0); ?></div>
            <div class="wp-stat-card__label">In Progress</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--blue"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($stats['resolved'] ?? 0); ?></div>
            <div class="wp-stat-card__label">Resolved</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--success"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--rose"><i class="fas fa-archive"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($stats['closed'] ?? 0); ?></div>
            <div class="wp-stat-card__label">Closed</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--rose"></div>
    </div>
</div>

<!-- ── STATUS QUICK-TABS ── -->
<div class="wp-status-tabs">
    <?php
    $tabs = [
        ''            => ['label' => 'All',         'icon' => 'fa-list'],
        'pending'     => ['label' => 'Pending',      'icon' => 'fa-clock'],
        'in progress' => ['label' => 'In Progress',  'icon' => 'fa-spinner'],
        'resolved'    => ['label' => 'Resolved',     'icon' => 'fa-check-circle'],
        'closed'      => ['label' => 'Closed',       'icon' => 'fa-archive'],
    ];
    foreach ($tabs as $val => $t):
        $active = ($status_filter === $val) ? 'active' : '';
        $qs = http_build_query(array_filter(['status' => $val, 'urgency' => $urgency_filter, 'search' => $search]));
    ?>
    <a href="?<?php echo $qs; ?>" class="wp-status-tab <?php echo $active; ?>">
        <i class="fas <?php echo $t['icon']; ?>"></i> <?php echo $t['label']; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── FILTER BAR ── -->
<form method="GET" action="my-reports.php" id="filterForm">
    <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
    <div class="wp-filter">
        <div class="wp-filter__group">
            <span class="wp-filter__label">Urgency</span>
            <select name="urgency" class="wp-input" onchange="this.form.submit()">
                <option value="">All Levels</option>
                <?php foreach (['low','medium','high','critical'] as $u): ?>
                <option value="<?php echo $u; ?>" <?php echo $urgency_filter === $u ? 'selected' : ''; ?>><?php echo ucfirst($u); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="wp-filter__group" style="flex:2 1 240px">
            <span class="wp-filter__label">Search</span>
            <input type="text" name="search" class="wp-input"
                   placeholder="Search by type, location or description…"
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="wp-filter__btns">
            <button type="submit" class="wp-btn wp-btn--primary wp-btn--sm"><i class="fas fa-search"></i> Search</button>
            <?php if ($status_filter || $urgency_filter || $search): ?>
            <a href="my-reports.php" class="wp-btn wp-btn--ghost wp-btn--sm"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ── REPORTS TABLE PANEL ── -->
<div class="wp-panel">
    <div class="wp-panel__header">
        <div class="wp-panel__title">
            <span class="wp-panel__icon wp-panel__icon--amber"><i class="fas fa-list-alt"></i></span>
            <h2>Reports
                <span class="wp-badge wp-badge--muted" style="margin-left:6px;font-size:11px;"><?php echo number_format($total_records); ?> total</span>
            </h2>
        </div>
        <a href="report-issue.php" class="wp-btn wp-btn--primary wp-btn--sm"><i class="fas fa-plus"></i> New Report</a>
    </div>

    <?php if (empty($reports)): ?>
    <div class="wp-empty">
        <i class="fas fa-inbox"></i>
        <p><?php echo ($status_filter || $urgency_filter || $search)
            ? 'No reports match your current filters.'
            : 'You haven\'t submitted any waste issue reports yet.'; ?></p>
        <?php if (!$status_filter && !$urgency_filter && !$search): ?>
        <a href="report-issue.php" class="wp-btn wp-btn--primary wp-btn--sm" style="margin-top:4px"><i class="fas fa-plus"></i> Report Your First Issue</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="wp-table-wrap">
        <table class="wp-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Issue Type</th>
                    <th>Location</th>
                    <th>Urgency</th>
                    <th>Status</th>
                    <th>Date Reported</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $r):
                // urgency badge
                $urg_map = [
                    'low'      => ['wp-badge--success', 'fa-circle',           'Low'],
                    'medium'   => ['wp-badge--warning', 'fa-exclamation-circle','Medium'],
                    'high'     => ['wp-badge--danger',  'fa-exclamation-triangle','High'],
                    'critical' => ['wp-badge--dark',    'fa-skull-crossbones', 'Critical'],
                ];
                $urg  = $urg_map[strtolower(trim($r['urgency']))] ?? ['wp-badge--muted','fa-circle','Unknown'];
                // status badge
                $st_map = [
                    'pending'     => ['wp-badge--warning', 'fa-clock',          'Pending'],
                    'in progress' => ['wp-badge--info',    'fa-spinner',        'In Progress'],
                    'resolved'    => ['wp-badge--success', 'fa-check-circle',   'Resolved'],
                    'closed'      => ['wp-badge--muted',   'fa-archive',        'Closed'],
                ];
                $st = $st_map[strtolower(trim($r['status']))] ?? ['wp-badge--muted','fa-circle', ucfirst($r['status'])];
            ?>
            <tr>
                <td><span class="wp-id">#<?php echo $r['issue_id']; ?></span></td>
                <td>
                    <i class="fas fa-trash-alt" style="color:var(--db-muted);margin-right:5px"></i>
                    <strong><?php echo htmlspecialchars($r['issue_type']); ?></strong>
                </td>
                <td>
                    <i class="fas fa-map-marker-alt" style="color:var(--db-rose);margin-right:4px"></i>
                    <span class="wp-text-sm"><?php echo htmlspecialchars(strlen($r['location']) > 40 ? substr($r['location'],0,40).'…' : $r['location']); ?></span>
                </td>
                <td><span class="wp-badge <?php echo $urg[0]; ?>"><i class="fas <?php echo $urg[1]; ?>"></i> <?php echo $urg[2]; ?></span></td>
                <td><span class="wp-badge <?php echo $st[0]; ?>"><i class="fas <?php echo $st[1]; ?>"></i> <?php echo $st[2]; ?></span></td>
                <td><span class="wp-text-sm"><i class="far fa-calendar" style="margin-right:4px"></i><?php echo date('M d, Y', strtotime($r['created_at'])); ?></span></td>
                <td>
                    <a href="view-report.php?id=<?php echo $r['issue_id']; ?>" class="wp-icon-btn" title="View Details">
                        <i class="fas fa-eye"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1):
        $qs_base = array_filter(['status' => $status_filter, 'urgency' => $urgency_filter, 'search' => $search]);
    ?>
    <div class="wp-pagination">
        <?php if ($current_page > 1): ?>
        <a href="?<?php echo http_build_query($qs_base + ['page' => $current_page - 1]); ?>" class="wp-page-btn"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($i = max(1, $current_page-2); $i <= min($total_pages, $current_page+2); $i++): ?>
        <a href="?<?php echo http_build_query($qs_base + ['page' => $i]); ?>" class="wp-page-btn <?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($current_page < $total_pages): ?>
        <a href="?<?php echo http_build_query($qs_base + ['page' => $current_page + 1]); ?>" class="wp-page-btn"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ── HELP PANEL ── -->
<div class="wp-panel">
    <div class="wp-panel__header">
        <div class="wp-panel__title">
            <span class="wp-panel__icon wp-panel__icon--blue"><i class="fas fa-question-circle"></i></span>
            <h2>About Your Reports</h2>
        </div>
    </div>
    <div class="wp-panel__body" style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
        <div>
            <p style="font-size:12px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Status Meanings</p>
            <?php
            $statuses = [
                ['wp-badge--warning','Pending',     'Received and awaiting review by our team'],
                ['wp-badge--info',   'In Progress', 'Actively being addressed by our team'],
                ['wp-badge--success','Resolved',    'The issue has been fully resolved'],
                ['wp-badge--muted',  'Closed',      'Report closed — resolved or deemed invalid'],
            ];
            foreach ($statuses as [$cls, $lbl, $desc]):
            ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12.5px">
                <span class="wp-badge <?php echo $cls; ?>"><?php echo $lbl; ?></span>
                <span style="color:var(--db-muted)"><?php echo $desc; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div>
            <p style="font-size:12px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Urgency Levels</p>
            <?php
            $urgs = [
                ['wp-badge--success', 'Low',      'Can wait a few days'],
                ['wp-badge--warning', 'Medium',   'Needs attention soon'],
                ['wp-badge--danger',  'High',     'Urgent attention required'],
                ['wp-badge--dark',    'Critical', 'Immediate action needed'],
            ];
            foreach ($urgs as [$cls, $lbl, $desc]):
            ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12.5px">
                <span class="wp-badge <?php echo $cls; ?>"><?php echo $lbl; ?></span>
                <span style="color:var(--db-muted)"><?php echo $desc; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="wp-panel__footer">
        <span style="font-size:12.5px;color:var(--db-muted)">
            <i class="fas fa-phone-alt" style="color:var(--db-success);margin-right:6px"></i>
            Need immediate help? Call the barangay office at <strong>(123) 456-7890</strong>
        </span>
    </div>
</div>

<?php require_once '../../../includes/footer.php'; ?>