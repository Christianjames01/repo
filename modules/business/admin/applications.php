<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Permit Applications";

$status_filter = $_GET['status'] ?? 'all';
$search        = $_GET['search'] ?? '';
$type_filter   = $_GET['type'] ?? 'all';
$page          = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

$where_clauses = [];
$params = [];
$types  = '';

if ($status_filter !== 'all') { $where_clauses[] = "bp.status = ?"; $params[] = $status_filter; $types .= 's'; }
if ($type_filter !== 'all')   { $where_clauses[] = "bp.business_type = ?"; $params[] = $type_filter; $types .= 's'; }
if (!empty($search)) {
    $where_clauses[] = "(bp.business_name LIKE ? OR bp.owner_name LIKE ? OR bp.permit_number LIKE ?)";
    $sp = "%$search%"; $params[] = $sp; $params[] = $sp; $params[] = $sp; $types .= 'sss';
}
$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_business_permits bp $where_sql");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages   = ceil($total_records / $records_per_page);

$query = "SELECT bp.*, r.first_name, r.last_name, r.contact_number as resident_contact
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    $where_sql
    ORDER BY CASE bp.status WHEN 'pending' THEN 1 WHEN 'for_inspection' THEN 2 WHEN 'approved' THEN 3 WHEN 'rejected' THEN 4 ELSE 5 END, bp.created_at DESC
    LIMIT ? OFFSET ?";
$params[] = $records_per_page; $params[] = $offset; $types .= 'ii';
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$applications = $stmt->get_result();

$business_types = $conn->query("SELECT DISTINCT business_type FROM tbl_business_permits WHERE business_type IS NOT NULL ORDER BY business_type");

// Stats
$s_total    = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits")->fetch_assoc()['c'];
$s_pending  = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits WHERE status='pending'")->fetch_assoc()['c'];
$s_inspect  = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits WHERE status='for_inspection'")->fetch_assoc()['c'];
$s_approved = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits WHERE status='approved'")->fetch_assoc()['c'];

function bqs($extra = []) {
    $params = array_merge(['status' => $_GET['status'] ?? 'all', 'type' => $_GET['type'] ?? 'all', 'search' => $_GET['search'] ?? ''], $extra);
    return http_build_query($params);
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include_once '../../../includes/header.php';
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                <i class="fas fa-file-alt" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Business Permits
                </div>
                <h1 class="db-hero__title">Permit Applications</h1>
                <p class="db-hero__sub">Review and manage all business permit applications</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="dashboard.php" class="db-btn db-btn--primary">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
        </div>
    </div>
</div>

<!-- ═══ STAT CARDS ═══ -->
<div class="db-stats-row">
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?<?php echo bqs(['status'=>'all','page'=>1]); ?>'">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-briefcase"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $s_total; ?></div>
            <div class="db-stat-card__label">Total</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?<?php echo bqs(['status'=>'pending','page'=>1]); ?>'">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $s_pending; ?></div>
            <div class="db-stat-card__label">Pending</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?<?php echo bqs(['status'=>'for_inspection','page'=>1]); ?>'">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-search"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $s_inspect; ?></div>
            <div class="db-stat-card__label">For Inspection</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?<?php echo bqs(['status'=>'approved','page'=>1]); ?>'">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $s_approved; ?></div>
            <div class="db-stat-card__label">Approved</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
</div>

<!-- ═══ FILTERS ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-filter"></i></span>
            <h2>Filter Applications</h2>
        </div>
    </div>
    <form method="GET" style="padding:16px 24px;display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:14px;align-items:end;">
        <div class="db-field">
            <label class="db-field__label">Search</label>
            <input type="text" name="search" class="db-input" placeholder="Business name, owner, permit #" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="db-field">
            <label class="db-field__label">Status</label>
            <select name="status" class="db-input">
                <option value="all" <?php echo $status_filter==='all'?'selected':''; ?>>All Status</option>
                <option value="pending" <?php echo $status_filter==='pending'?'selected':''; ?>>Pending</option>
                <option value="for_inspection" <?php echo $status_filter==='for_inspection'?'selected':''; ?>>For Inspection</option>
                <option value="approved" <?php echo $status_filter==='approved'?'selected':''; ?>>Approved</option>
                <option value="rejected" <?php echo $status_filter==='rejected'?'selected':''; ?>>Rejected</option>
                <option value="expired" <?php echo $status_filter==='expired'?'selected':''; ?>>Expired</option>
                <option value="cancelled" <?php echo $status_filter==='cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="db-field">
            <label class="db-field__label">Business Type</label>
            <select name="type" class="db-input">
                <option value="all">All Types</option>
                <?php while ($t = $business_types->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($t['business_type']); ?>" <?php echo $type_filter==$t['business_type']?'selected':''; ?>>
                        <?php echo htmlspecialchars($t['business_type']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button>
            <a href="applications.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i></a>
        </div>
    </form>
</div>

<!-- ═══ APPLICATIONS TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list"></i></span>
            <h2>Applications <span class="db-badge db-badge--primary" style="margin-left:6px;"><?php echo $total_records; ?></span></h2>
        </div>
    </div>

    <?php if ($applications->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Permit #</th>
                    <th>Business Name</th>
                    <th>Owner</th>
                    <th>Type</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($app = $applications->fetch_assoc()):
                $badge_map = [
                    'pending'        => 'db-badge--warning',
                    'for_inspection' => 'db-badge--info',
                    'approved'       => 'db-badge--success',
                    'rejected'       => 'db-badge--danger',
                    'expired'        => 'db-badge--muted',
                    'cancelled'      => 'db-badge--muted',
                ];
                $pay_map = ['unpaid' => 'db-badge--danger', 'partial' => 'db-badge--warning', 'paid' => 'db-badge--success'];
                $bc  = $badge_map[$app['status']] ?? 'db-badge--muted';
                $pc  = $pay_map[$app['payment_status']] ?? 'db-badge--muted';
            ?>
            <tr>
                <td><span class="db-id"><?php echo htmlspecialchars($app['permit_number'] ?? '—'); ?></span></td>
                <td><strong><?php echo htmlspecialchars($app['business_name']); ?></strong></td>
                <td>
                    <?php echo htmlspecialchars(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')); ?>
                    <?php if ($app['owner_contact']): ?>
                        <br><small style="color:var(--db-muted);font-size:11px;"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($app['owner_contact']); ?></small>
                    <?php endif; ?>
                </td>
                <td><span style="font-size:12px;"><?php echo htmlspecialchars($app['business_type'] ?? 'N/A'); ?></span></td>
                <td><span style="font-size:12px;font-family:'DM Mono',monospace;"><?php echo date('M d, Y', strtotime($app['application_date'])); ?></span></td>
                <td><span class="db-badge <?php echo $bc; ?>"><?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?></span></td>
                <td>
                    <span class="db-badge <?php echo $pc; ?>"><?php echo ucfirst($app['payment_status']); ?></span>
                    <?php if ($app['permit_fee'] > 0): ?>
                        <br><small style="font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);">₱<?php echo number_format($app['permit_fee'], 2); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="db-btn-group">
                        <a href="view-permit.php?id=<?php echo $app['permit_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View"><i class="fas fa-eye"></i></a>
                        <?php if (in_array($app['status'], ['pending', 'for_inspection'])): ?>
                            <a href="process-permit.php?id=<?php echo $app['permit_id']; ?>" class="db-icon-btn db-icon-btn--primary" title="Process"><i class="fas fa-check"></i></a>
                        <?php endif; ?>
                        <a href="print-permit.php?id=<?php echo $app['permit_id']; ?>" class="db-icon-btn" title="Print" target="_blank"><i class="fas fa-print"></i></a>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="db-panel__footer" style="justify-content:center;">
        <div style="display:flex;align-items:center;gap:4px;">
            <a href="?<?php echo bqs(['page' => max(1, $page-1)]); ?>" class="db-btn db-btn--ghost db-btn--sm <?php echo $page<=1?'disabled':''; ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                <a href="?<?php echo bqs(['page' => $i]); ?>" class="db-btn db-btn--sm <?php echo $i===$page?'db-btn--primary':'db-btn--ghost'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a href="?<?php echo bqs(['page' => min($total_pages, $page+1)]); ?>" class="db-btn db-btn--ghost db-btn--sm <?php echo $page>=$total_pages?'disabled':''; ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-inbox"></i>
        <p>No applications found matching your filters.</p>
        <a href="applications.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-redo"></i> Clear Filters</a>
    </div>
    <?php endif; ?>
</div>

<style>
.db-field { display:flex;flex-direction:column;gap:5px; }
.db-field__label { font-size:11.5px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.4px; }
.disabled { opacity:.4;pointer-events:none; }
</style>

<?php include_once '../../../includes/footer.php'; ?>