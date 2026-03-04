<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Registry";
$current_user_id = getCurrentUserId();

$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$sort_by     = isset($_GET['sort']) ? $_GET['sort'] : 'business_name';
$sort_order  = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page    = 15;
$offset      = ($page - 1) * $per_page;

$where_conditions = ["bp.status = 'Approved'"];
$params = []; $types = "";

if (!empty($search)) {
    $where_conditions[] = "(bp.business_name LIKE ? OR bp.owner_name LIKE ? OR bp.permit_number LIKE ?)";
    $sp = "%{$search}%"; $params = array_merge($params, [$sp, $sp, $sp]); $types .= "sss";
}
if (!empty($status_filter)) {
    if ($status_filter === 'active')         $where_conditions[] = "bp.expiry_date >= CURDATE()";
    elseif ($status_filter === 'expired')    $where_conditions[] = "bp.expiry_date < CURDATE()";
    elseif ($status_filter === 'expiring_soon') $where_conditions[] = "bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
}
if ($type_filter > 0) { $where_conditions[] = "bp.business_type_id = ?"; $params[] = $type_filter; $types .= "i"; }

$where_sql  = implode(" AND ", $where_conditions);
$valid_sorts = ['business_name', 'permit_number', 'issue_date', 'expiry_date', 'type_name'];
if (!in_array($sort_by, $valid_sorts)) $sort_by = 'business_name';

$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT bp.permit_id) as total FROM tbl_business_permits bp LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id WHERE $where_sql");
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages   = ceil($total_records / $per_page);

$sql = "SELECT bp.*, bt.type_name, CONCAT(r.first_name, ' ', r.last_name) as resident_name,
        COALESCE(r.contact_number, 'N/A') as contact_number, r.contact_number as resident_contact,
        CASE WHEN bp.expiry_date < CURDATE() THEN 'expired'
             WHEN bp.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring'
             ELSE 'active' END as permit_status,
        DATEDIFF(bp.expiry_date, CURDATE()) as days_until_expiry
    FROM tbl_business_permits bp
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    WHERE $where_sql ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";

$params[] = $per_page; $params[] = $offset; $types .= "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$businesses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$types_result   = $conn->query("SELECT * FROM tbl_business_types ORDER BY type_name");
$business_types = $types_result->fetch_all(MYSQLI_ASSOC);

$stats = $conn->query("SELECT COUNT(*) as total_businesses,
    SUM(CASE WHEN expiry_date >= CURDATE() THEN 1 ELSE 0 END) as active_businesses,
    SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) as expired_businesses,
    SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon
    FROM tbl_business_permits WHERE status = 'Approved'")->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $permit_id = (int)$_POST['permit_id'];
    if ($_POST['action'] === 'revoke') {
        $reason = trim($_POST['revoke_reason']);
        $stmt = $conn->prepare("UPDATE tbl_business_permits SET status = 'Revoked' WHERE permit_id = ?");
        $stmt->bind_param("i", $permit_id); $stmt->execute();
        $_SESSION['temp_success'] = "Business permit revoked successfully.";
    } elseif ($_POST['action'] === 'send_reminder') {
        $_SESSION['temp_success'] = "Reminder sent successfully.";
    }
    redirect($_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
}

$success_msg = ''; $error_msg = '';
if (isset($_SESSION['temp_success'])) { $success_msg = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_msg   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

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
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#0d9488,#0f766e);">
                <i class="fas fa-store" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Business Permits
                </div>
                <h1 class="db-hero__title">Business Registry</h1>
                <p class="db-hero__sub">All registered and approved businesses in the barangay</p>
            </div>
        </div>
        <div class="db-hero__right">
            <button class="db-btn db-btn--primary" onclick="exportRegistry()">
                <i class="fas fa-file-excel"></i> Export Registry
            </button>
        </div>
    </div>
</div>

<?php if ($success_msg): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($success_msg); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($error_msg); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ═══ STAT CARDS ═══ -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-store"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['total_businesses']); ?></div>
            <div class="db-stat-card__label">Total Businesses</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="document.querySelector('[name=status]').value='active';document.querySelector('form').submit();">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['active_businesses']); ?></div>
            <div class="db-stat-card__label">Active Permits</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="document.querySelector('[name=status]').value='expiring_soon';document.querySelector('form').submit();">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['expiring_soon']); ?></div>
            <div class="db-stat-card__label">Expiring Soon</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <div class="db-stat-card db-stat-card--clickable" onclick="document.querySelector('[name=status]').value='expired';document.querySelector('form').submit();">
        <div class="db-stat-card__icon" style="background:color-mix(in srgb,var(--db-rose) 12%,white);color:var(--db-rose);"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['expired_businesses']); ?></div>
            <div class="db-stat-card__label">Expired</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--db-rose) 18%,white));"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
</div>

<!-- ═══ FILTERS ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-filter"></i></span>
            <h2>Filter & Sort</h2>
        </div>
    </div>
    <form method="GET" style="padding:16px 24px;display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:12px;align-items:end;">
        <div class="db-field">
            <label class="db-field__label">Search</label>
            <input type="text" name="search" class="db-input" placeholder="Business name, owner, permit #" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="db-field">
            <label class="db-field__label">Status</label>
            <select name="status" class="db-input">
                <option value="">All Status</option>
                <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
                <option value="expiring_soon" <?php echo $status_filter==='expiring_soon'?'selected':''; ?>>Expiring Soon</option>
                <option value="expired" <?php echo $status_filter==='expired'?'selected':''; ?>>Expired</option>
            </select>
        </div>
        <div class="db-field">
            <label class="db-field__label">Business Type</label>
            <select name="type" class="db-input">
                <option value="">All Types</option>
                <?php foreach ($business_types as $t): ?>
                    <option value="<?php echo $t['type_id']; ?>" <?php echo $type_filter==$t['type_id']?'selected':''; ?>><?php echo htmlspecialchars($t['type_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="db-field">
            <label class="db-field__label">Sort By</label>
            <select name="sort" class="db-input">
                <option value="business_name" <?php echo $sort_by==='business_name'?'selected':''; ?>>Business Name</option>
                <option value="permit_number" <?php echo $sort_by==='permit_number'?'selected':''; ?>>Permit #</option>
                <option value="expiry_date" <?php echo $sort_by==='expiry_date'?'selected':''; ?>>Expiry Date</option>
                <option value="issue_date" <?php echo $sort_by==='issue_date'?'selected':''; ?>>Issue Date</option>
            </select>
        </div>
        <div class="db-field">
            <label class="db-field__label">Order</label>
            <select name="order" class="db-input">
                <option value="asc" <?php echo $sort_order==='ASC'?'selected':''; ?>>Ascending</option>
                <option value="desc" <?php echo $sort_order==='DESC'?'selected':''; ?>>Descending</option>
            </select>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button>
            <a href="registry.php" class="db-btn db-btn--ghost"><i class="fas fa-redo"></i></a>
        </div>
    </form>
</div>

<!-- ═══ BUSINESS CARDS ═══ -->
<?php if (empty($businesses)): ?>
<div class="db-panel">
    <div class="db-empty">
        <i class="fas fa-store"></i>
        <p>No businesses match your search criteria.</p>
    </div>
</div>
<?php else: ?>

<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-store"></i></span>
            <h2>Businesses <span class="db-badge db-badge--primary" style="margin-left:6px;"><?php echo $total_records; ?></span></h2>
        </div>
        <span style="font-size:12px;color:var(--db-muted);">
            Showing <?php echo $offset+1; ?>–<?php echo min($offset+$per_page, $total_records); ?> of <?php echo number_format($total_records); ?>
        </span>
    </div>

    <div style="display:flex;flex-direction:column;gap:0;">
    <?php foreach ($businesses as $b): ?>
        <?php
        $ps = $b['permit_status'];
        $ps_badge = ['active' => 'db-badge--success', 'expiring' => 'db-badge--warning', 'expired' => 'db-badge--danger'];
        $ps_icon  = ['active' => 'fa-check-circle', 'expiring' => 'fa-clock', 'expired' => 'fa-times-circle'];
        $ps_label = ['active' => 'Active', 'expiring' => 'Expiring in ' . $b['days_until_expiry'] . ' days', 'expired' => 'Expired'];
        $bc = $ps_badge[$ps] ?? 'db-badge--muted';
        ?>
        <div class="reg-biz-row">
            <div class="reg-biz-row__left">
                <div class="reg-biz-row__avatar">
                    <i class="fas fa-store"></i>
                </div>
                <div class="reg-biz-row__info">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <strong style="font-size:15px;"><?php echo htmlspecialchars($b['business_name']); ?></strong>
                        <span class="db-badge <?php echo $bc; ?>">
                            <i class="fas <?php echo $ps_icon[$ps] ?? 'fa-circle'; ?> me-1"></i><?php echo $ps_label[$ps] ?? ucfirst($ps); ?>
                        </span>
                    </div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:6px;">
                        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($b['owner_name']); ?></span>
                        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-certificate me-1"></i><span class="db-id"><?php echo htmlspecialchars($b['permit_number']); ?></span></span>
                        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($b['type_name'] ?? 'N/A'); ?></span>
                        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars(substr($b['business_address'], 0, 45)); ?></span>
                    </div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:4px;">
                        <span style="font-size:11.5px;color:var(--db-muted);"><i class="fas fa-calendar-plus me-1"></i>Issued: <span style="font-family:'DM Mono',monospace;"><?php echo date('M d, Y', strtotime($b['issue_date'])); ?></span></span>
                        <span style="font-size:11.5px;color:var(--db-muted);"><i class="fas fa-calendar-times me-1"></i>Expires: <span style="font-family:'DM Mono',monospace;"><?php echo date('M d, Y', strtotime($b['expiry_date'])); ?></span></span>
                        <span style="font-size:11.5px;color:var(--db-muted);"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($b['contact_number']); ?></span>
                    </div>
                </div>
            </div>
            <div class="reg-biz-row__actions">
                <a href="view-business.php?id=<?php echo $b['permit_id']; ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-eye"></i> View</a>
                <?php if ($ps === 'expiring' || $ps === 'expired'): ?>
                    <button class="db-btn db-btn--ghost db-btn--sm" onclick="sendReminder(<?php echo $b['permit_id']; ?>)"><i class="fas fa-bell"></i> Remind</button>
                <?php endif; ?>
                <button class="db-btn db-btn--ghost db-btn--sm" onclick="printCertificate(<?php echo $b['permit_id']; ?>)"><i class="fas fa-print"></i></button>
                <button class="db-icon-btn db-icon-btn--danger" onclick="revokePermit(<?php echo $b['permit_id']; ?>,'<?php echo htmlspecialchars(addslashes($b['business_name'])); ?>')" title="Revoke"><i class="fas fa-ban"></i></button>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="db-panel__footer" style="justify-content:center;">
        <div style="display:flex;align-items:center;gap:4px;">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page-1)])); ?>" class="db-btn db-btn--ghost db-btn--sm <?php echo $page<=1?'disabled':''; ?>"><i class="fas fa-chevron-left"></i></a>
            <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="db-btn db-btn--sm <?php echo $i===$page?'db-btn--primary':'db-btn--ghost'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page+1)])); ?>" class="db-btn db-btn--ghost db-btn--sm <?php echo $page>=$total_pages?'disabled':''; ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Revoke Modal -->
<div class="db-modal" id="revokeModal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3 class="db-modal__title"><i class="fas fa-ban" style="color:var(--db-rose);"></i> Revoke Business Permit</h3>
            <button class="db-modal__close" onclick="closeModal('revokeModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="permit_id" id="revokePermitId">
                <div class="db-delete-warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    You are about to revoke the permit for <strong id="revokeBusinessName"></strong>. This action cannot be undone.
                </div>
                <div class="db-field" style="margin-top:14px;">
                    <label class="db-field__label">Reason for Revocation <span class="req">*</span></label>
                    <textarea name="revoke_reason" class="db-input" rows="4" required placeholder="Enter the reason for revoking this permit..."></textarea>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('revokeModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--danger"><i class="fas fa-ban"></i> Revoke Permit</button>
            </div>
        </form>
    </div>
</div>

<style>
.db-field { display:flex;flex-direction:column;gap:5px; }
.db-field__label { font-size:11.5px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.4px; }
.req { color:var(--db-rose); }
.disabled { opacity:.4;pointer-events:none; }

.reg-biz-row {
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    padding:18px 24px;border-bottom:1px solid var(--db-border);
    transition:background .15s;
}
.reg-biz-row:hover { background:var(--db-surf2); }
.reg-biz-row:last-child { border-bottom:none; }
.reg-biz-row__left { display:flex;align-items:flex-start;gap:14px;flex:1;min-width:0; }
.reg-biz-row__avatar {
    width:44px;height:44px;border-radius:12px;background:var(--db-surf2);
    display:flex;align-items:center;justify-content:center;font-size:18px;
    color:var(--db-muted);flex-shrink:0;border:1px solid var(--db-border);
}
.reg-biz-row__info { flex:1;min-width:0; }
.reg-biz-row__actions { display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end; }

.db-delete-warn {
    background:color-mix(in srgb,var(--db-rose) 8%,white);
    border:1px solid color-mix(in srgb,var(--db-rose) 20%,white);
    border-radius:var(--db-radius-sm);padding:12px 14px;
    font-size:13px;display:flex;gap:10px;align-items:flex-start;
    color:var(--db-text);
}
.db-delete-warn i { color:var(--db-rose);margin-top:2px;flex-shrink:0; }
</style>

<script>
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
document.querySelectorAll('.db-modal').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)closeModal(m.id);});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

function revokePermit(id, name) {
    document.getElementById('revokePermitId').value = id;
    document.getElementById('revokeBusinessName').textContent = name;
    openModal('revokeModal');
}
function sendReminder(id) {
    if (!confirm('Send expiry reminder to business owner?')) return;
    const f = document.createElement('form'); f.method='POST';
    f.innerHTML=`<input type="hidden" name="action" value="send_reminder"><input type="hidden" name="permit_id" value="${id}">`;
    document.body.appendChild(f); f.submit();
}
function printCertificate(id) { window.open('print-certificate.php?id='+id,'_blank'); }
function exportRegistry() {
    const p = new URLSearchParams(window.location.search);
    window.location.href = 'export-registry.php?' + p.toString();
}
setTimeout(()=>{
    document.querySelectorAll('.db-alert').forEach(a=>{
        a.style.opacity='0';a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
},5000);
</script>

<?php include_once '../../../includes/footer.php'; ?>