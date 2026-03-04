<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Permit Renewals";

$filter = $_GET['filter'] ?? 'expiring';
$search = $_GET['search'] ?? '';
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 15;
$offset = ($page - 1) * $records_per_page;

$where_clauses = ["bp.status = 'Approved'"];
$params = []; $types = '';

if ($filter === 'expiring')     $where_clauses[] = "bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
elseif ($filter === 'expired')  $where_clauses[] = "bp.expiry_date < CURDATE()";

if (!empty($search)) {
    $where_clauses[] = "(bp.business_name LIKE ? OR bp.owner_name LIKE ? OR bp.permit_number LIKE ?)";
    $sp = "%$search%"; $params[] = $sp; $params[] = $sp; $params[] = $sp; $types .= 'sss';
}

$where_sql = implode(' AND ', $where_clauses);

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_business_permits bp WHERE $where_sql");
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages   = ceil($total_records / $records_per_page);

$query = "SELECT bp.*, r.first_name, r.last_name, bt.type_name,
           DATEDIFF(bp.expiry_date, CURDATE()) as days_until_expiry
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    WHERE $where_sql ORDER BY bp.expiry_date ASC LIMIT ? OFFSET ?";
$params[] = $records_per_page; $params[] = $offset; $types .= 'ii';
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$permits = $stmt->get_result();

$stat_exp  = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits WHERE status='Approved' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetch_assoc()['c'];
$stat_exd  = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits WHERE status='Approved' AND expiry_date < CURDATE()")->fetch_assoc()['c'];
$stat_all  = $conn->query("SELECT COUNT(*) as c FROM tbl_business_permits WHERE status='Approved'")->fetch_assoc()['c'];

function rqs($extra=[]) {
    return http_build_query(array_merge(['filter'=>$_GET['filter']??'expiring','search'=>$_GET['search']??''], $extra));
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
                <i class="fas fa-sync-alt" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Business Permits
                </div>
                <h1 class="db-hero__title">Permit Renewals</h1>
                <p class="db-hero__sub">Manage expiring and expired business permits</p>
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
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?filter=expiring'">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stat_exp; ?></div>
            <div class="db-stat-card__label">Expiring Soon</div>
            <div style="font-size:11px;color:var(--db-muted);">Within 60 days</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?filter=expired'">
        <div class="db-stat-card__icon" style="background:color-mix(in srgb,var(--db-rose) 12%,white);color:var(--db-rose);"><i class="fas fa-times-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stat_exd; ?></div>
            <div class="db-stat-card__label">Expired Permits</div>
            <div style="font-size:11px;color:var(--db-muted);">Requires renewal</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--db-rose) 18%,white));"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='?filter=all'">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stat_all; ?></div>
            <div class="db-stat-card__label">All Active Permits</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
</div>

<!-- ═══ FILTERS ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-filter"></i></span>
            <h2>Filter</h2>
        </div>
        <!-- Tab buttons -->
        <div style="display:flex;gap:6px;">
            <a href="?filter=expiring<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="db-btn db-btn--sm <?php echo $filter==='expiring'?'db-btn--primary':'db-btn--ghost'; ?>">Expiring Soon</a>
            <a href="?filter=expired<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="db-btn db-btn--sm <?php echo $filter==='expired'?'db-btn--primary':'db-btn--ghost'; ?>">Expired</a>
            <a href="?filter=all<?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="db-btn db-btn--sm <?php echo $filter==='all'?'db-btn--primary':'db-btn--ghost'; ?>">All Active</a>
        </div>
    </div>
    <form method="GET" style="padding:14px 24px;display:flex;gap:12px;align-items:flex-end;">
        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
        <div class="db-field" style="flex:1;">
            <label class="db-field__label">Search</label>
            <input type="text" name="search" class="db-input" placeholder="Business name, owner, permit #" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Search</button>
        <a href="renewals.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i></a>
    </form>
</div>

<!-- ═══ PERMITS TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list"></i></span>
            <h2>
                <?php echo ['expiring'=>'Expiring Soon','expired'=>'Expired Permits','all'=>'All Active Permits'][$filter] ?? 'Permits'; ?>
                <span class="db-badge db-badge--primary" style="margin-left:6px;"><?php echo $total_records; ?></span>
            </h2>
        </div>
    </div>

    <?php if ($permits->num_rows > 0): ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Permit #</th>
                    <th>Business Name</th>
                    <th>Owner</th>
                    <th>Type</th>
                    <th>Issue Date</th>
                    <th>Expiry Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($permit = $permits->fetch_assoc()):
                $days = $permit['days_until_expiry'];
                $is_expired = $days < 0;
                if ($is_expired) { $row_badge = 'db-badge--danger'; $row_label = 'Expired'; }
                elseif ($days <= 30) { $row_badge = 'db-badge--warning'; $row_label = 'Expiring Soon'; }
                else { $row_badge = 'db-badge--success'; $row_label = 'Active'; }
            ?>
            <tr>
                <td><span class="db-id"><?php echo htmlspecialchars($permit['permit_number']); ?></span></td>
                <td>
                    <strong><?php echo htmlspecialchars($permit['business_name']); ?></strong>
                    <?php if (!empty($permit['trade_name'])): ?>
                        <br><small style="color:var(--db-muted);font-size:11px;">DBA: <?php echo htmlspecialchars($permit['trade_name']); ?></small>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(($permit['first_name'] ?? '') . ' ' . ($permit['last_name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($permit['type_name'] ?? 'N/A'); ?></td>
                <td><span style="font-family:'DM Mono',monospace;font-size:12px;"><?php echo date('M d, Y', strtotime($permit['issue_date'])); ?></span></td>
                <td>
                    <span style="font-family:'DM Mono',monospace;font-size:12px;"><?php echo date('M d, Y', strtotime($permit['expiry_date'])); ?></span>
                    <br>
                    <?php if ($is_expired): ?>
                        <small style="color:var(--db-rose);font-size:11px;"><i class="fas fa-times-circle"></i> Expired <?php echo abs($days); ?> days ago</small>
                    <?php else: ?>
                        <small style="color:var(--db-amber);font-size:11px;"><i class="fas fa-clock"></i> <?php echo $days; ?> days remaining</small>
                    <?php endif; ?>
                </td>
                <td><span class="db-badge <?php echo $row_badge; ?>"><?php echo $row_label; ?></span></td>
                <td>
                    <div class="db-btn-group">
                        <a href="view-permit.php?id=<?php echo $permit['permit_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View"><i class="fas fa-eye"></i></a>
                        <button class="db-icon-btn db-icon-btn--primary" onclick="processRenewal(<?php echo $permit['permit_id']; ?>)" title="Process Renewal"><i class="fas fa-sync-alt"></i></button>
                        <a href="print-permit.php?id=<?php echo $permit['permit_id']; ?>" class="db-icon-btn" title="Print" target="_blank"><i class="fas fa-print"></i></a>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="db-panel__footer" style="justify-content:center;">
        <div style="display:flex;align-items:center;gap:4px;">
            <a href="?<?php echo rqs(['page'=>max(1,$page-1)]); ?>" class="db-btn db-btn--ghost db-btn--sm <?php echo $page<=1?'disabled':''; ?>"><i class="fas fa-chevron-left"></i></a>
            <?php for ($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
                <a href="?<?php echo rqs(['page'=>$i]); ?>" class="db-btn db-btn--sm <?php echo $i===$page?'db-btn--primary':'db-btn--ghost'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a href="?<?php echo rqs(['page'=>min($total_pages,$page+1)]); ?>" class="db-btn db-btn--ghost db-btn--sm <?php echo $page>=$total_pages?'disabled':''; ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-inbox"></i>
        <p>No permits found for this filter.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Renewal Modal -->
<div class="db-modal" id="renewalModal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3 class="db-modal__title"><i class="fas fa-sync-alt" style="color:var(--db-teal);"></i> Process Permit Renewal</h3>
            <button class="db-modal__close" onclick="closeModal('renewalModal')">×</button>
        </div>
        <form id="renewalForm" method="POST" action="process-renewal.php">
            <div class="db-modal__body">
                <input type="hidden" name="permit_id" id="renewal_permit_id">
                <div class="db-field-row">
                    <div class="db-field">
                        <label class="db-field__label">Renewal Fee <span class="req">*</span></label>
                        <input type="number" name="renewal_fee" class="db-input" value="1000.00" step="0.01" required>
                    </div>
                    <div class="db-field">
                        <label class="db-field__label">Validity Period <span class="req">*</span></label>
                        <select name="validity_period" class="db-input" required>
                            <option value="1">1 Year</option>
                            <option value="2">2 Years</option>
                            <option value="3">3 Years</option>
                        </select>
                    </div>
                </div>
                <div class="db-field" style="margin-top:14px;">
                    <label class="db-field__label">Notes</label>
                    <textarea name="notes" class="db-input" rows="3" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('renewalModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-check"></i> Process Renewal</button>
            </div>
        </form>
    </div>
</div>

<style>
.db-field { display:flex;flex-direction:column;gap:5px; }
.db-field__label { font-size:11.5px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.4px; }
.db-field-row { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
.req { color:var(--db-rose); }
.disabled { opacity:.4;pointer-events:none; }
</style>

<script>
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
document.querySelectorAll('.db-modal').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)closeModal(m.id);});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

function processRenewal(id) {
    document.getElementById('renewal_permit_id').value = id;
    openModal('renewalModal');
}
</script>

<?php include_once '../../../includes/footer.php'; ?>