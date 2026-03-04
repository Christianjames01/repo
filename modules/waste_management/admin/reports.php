<?php
require_once('../../../config/config.php');
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Waste Collection Reports";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add':
            $sql = "INSERT INTO tbl_waste_collection_reports (collection_date,area_zone,waste_type,quantity_kg,collector_name,status,created_at) VALUES(?,?,?,?,?,?,NOW())";
            $ok = execute($conn, $sql, [sanitize($_POST['collection_date']),sanitize($_POST['area_zone']),sanitize($_POST['waste_type']),(float)$_POST['quantity_kg'],sanitize($_POST['collector_name']),sanitize($_POST['status'])], 'sssdss');
            $_SESSION['temp_success'] = $ok ? 'Report added successfully!' : 'Error adding report.';
            header('Location: reports.php'); exit;
        case 'edit':
            $id = (int)$_POST['id'];
            $sql = "UPDATE tbl_waste_collection_reports SET collection_date=?,area_zone=?,waste_type=?,quantity_kg=?,collector_name=?,status=? WHERE id=?";
            $ok = execute($conn, $sql, [sanitize($_POST['collection_date']),sanitize($_POST['area_zone']),sanitize($_POST['waste_type']),(float)$_POST['quantity_kg'],sanitize($_POST['collector_name']),sanitize($_POST['status']),$id], 'sssdssi');
            $_SESSION['temp_success'] = $ok ? 'Report updated successfully!' : 'Error updating report.';
            header('Location: reports.php'); exit;
        case 'delete':
            $id = (int)$_POST['id'];
            if ($id > 0) {
                $ok = execute($conn, "DELETE FROM tbl_waste_collection_reports WHERE id=?", [$id], 'i');
                $_SESSION['temp_success'] = $ok ? 'Report deleted successfully!' : 'Error deleting report.';
            } else { $_SESSION['temp_error'] = 'Invalid report ID.'; }
            header('Location: reports.php'); exit;
    }
}

$success_message = $error_message = '';
if (isset($_SESSION['temp_success'])) { $success_message=$_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message=$_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

// Filters
$date_from    = sanitize($_GET['date_from'] ?? '');
$date_to      = sanitize($_GET['date_to'] ?? '');
$filter_area  = sanitize($_GET['area'] ?? '');
$filter_type  = sanitize($_GET['waste_type'] ?? '');
$filter_status= sanitize($_GET['status'] ?? '');

$where=[]; $params=[]; $types='';
if ($date_from)    { $where[]="collection_date>=?"; $params[]=$date_from;    $types.='s'; }
if ($date_to)      { $where[]="collection_date<=?"; $params[]=$date_to;      $types.='s'; }
if ($filter_area)  { $where[]="area_zone=?";        $params[]=$filter_area;  $types.='s'; }
if ($filter_type)  { $where[]="waste_type=?";       $params[]=$filter_type;  $types.='s'; }
if ($filter_status){ $where[]="status=?";           $params[]=$filter_status;$types.='s'; }
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// Pagination
$page     = max(1,(int)($_GET['page']??1));
$per_page = 20;
$offset   = ($page-1)*$per_page;

$total    = fetchOne($conn,"SELECT COUNT(*) as c FROM tbl_waste_collection_reports $where_sql",$params,$types)['c']??0;
$pages    = ceil($total/$per_page);

$rp = array_merge($params,[$per_page,$offset]); $rt=$types.'ii';
$reports = fetchAll($conn,"SELECT * FROM tbl_waste_collection_reports $where_sql ORDER BY collection_date DESC,created_at DESC LIMIT ? OFFSET ?",$rp,$rt);

$areas = fetchAll($conn,"SELECT DISTINCT area_zone FROM tbl_waste_collection_reports WHERE area_zone IS NOT NULL AND area_zone!='' ORDER BY area_zone",[],'' );

$stp = array_slice($params,0,count($params));
$stats = fetchOne($conn,"SELECT COUNT(*) as total_collections, COALESCE(SUM(quantity_kg),0) as total_waste, COALESCE(AVG(quantity_kg),0) as avg_waste, COALESCE(SUM(CASE WHEN waste_type='biodegradable' THEN quantity_kg ELSE 0 END),0) as bio, COALESCE(SUM(CASE WHEN waste_type='non-biodegradable' THEN quantity_kg ELSE 0 END),0) as non_bio, COALESCE(SUM(CASE WHEN waste_type='recyclable' THEN quantity_kg ELSE 0 END),0) as rec, COALESCE(SUM(CASE WHEN waste_type='hazardous' THEN quantity_kg ELSE 0 END),0) as haz FROM tbl_waste_collection_reports $where_sql",$stp,$types);

function bqs($exclude=[]){ $p=$_GET; foreach($exclude as $k) unset($p[$k]); return $p?'&'.http_build_query($p):''; }

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
require_once '../../../includes/header.php';
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
                <i class="fas fa-clipboard-list" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Waste Management
                </div>
                <h1 class="db-hero__title">Waste Collection Reports</h1>
                <p class="db-hero__sub">Track and manage all barangay waste collection activities</p>
            </div>
        </div>
        <div class="db-hero__right">
            <button class="db-btn db-btn--primary" onclick="openModal('addReportModal')">
                <i class="fas fa-plus"></i> Add Report
            </button>
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
<?php if ($error_message): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>


<!-- ═══ STAT CARDS ═══ -->
<?php if ($stats && $stats['total_collections'] > 0): ?>
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-clipboard-check"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['total_collections']); ?></div>
            <div class="db-stat-card__label">Total Collections</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-weight"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="font-size:20px;"><?php echo number_format($stats['total_waste'],1); ?><span style="font-size:13px;font-weight:500;color:var(--db-muted);"> kg</span></div>
            <div class="db-stat-card__label">Total Waste</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-chart-line"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="font-size:20px;"><?php echo number_format($stats['avg_waste'],1); ?><span style="font-size:13px;font-weight:500;color:var(--db-muted);"> kg</span></div>
            <div class="db-stat-card__label">Avg / Collection</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-recycle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="font-size:20px;"><?php echo number_format($stats['rec'],1); ?><span style="font-size:13px;font-weight:500;color:var(--db-muted);"> kg</span></div>
            <div class="db-stat-card__label">Recyclable</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
</div>

<!-- ═══ WASTE TYPE BREAKDOWN ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-chart-pie"></i></span>
            <h2>Waste Type Breakdown</h2>
        </div>
    </div>
    <?php
    $total_w = $stats['total_waste'] ?: 1;
    $breakdown = [
        ['label'=>'Biodegradable',    'val'=>$stats['bio'],     'icon'=>'fa-leaf',         'color'=>'var(--db-success)', 'bg'=>'var(--db-success-light)'],
        ['label'=>'Non-Biodegradable','val'=>$stats['non_bio'], 'icon'=>'fa-times-circle',  'color'=>'var(--db-rose)',    'bg'=>'var(--db-rose-light)'],
        ['label'=>'Recyclable',       'val'=>$stats['rec'],     'icon'=>'fa-recycle',       'color'=>'var(--db-sky)',     'bg'=>'var(--db-sky-light)'],
        ['label'=>'Hazardous',        'val'=>$stats['haz'],     'icon'=>'fa-radiation',     'color'=>'var(--db-amber-dark)', 'bg'=>'var(--db-amber-light)'],
    ];
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1px;background:var(--db-border);">
    <?php foreach ($breakdown as $b):
        $pct = round($b['val']/$total_w*100,1);
    ?>
    <div style="background:var(--db-surf);padding:22px 20px;text-align:center;">
        <div style="width:48px;height:48px;border-radius:12px;background:<?php echo $b['bg']; ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;">
            <i class="fas <?php echo $b['icon']; ?>" style="font-size:20px;color:<?php echo $b['color']; ?>;"></i>
        </div>
        <div style="font-size:20px;font-weight:800;letter-spacing:-0.5px;"><?php echo number_format($b['val'],2); ?> kg</div>
        <div style="font-size:11px;color:var(--db-muted);font-weight:500;margin-top:2px;"><?php echo $b['label']; ?></div>
        <div style="font-family:'DM Mono',monospace;font-size:11px;color:<?php echo $b['color']; ?>;font-weight:600;margin-top:4px;"><?php echo $pct; ?>%</div>
        <!-- Progress bar -->
        <div style="height:3px;background:var(--db-border);border-radius:2px;margin-top:8px;overflow:hidden;">
            <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $b['color']; ?>;border-radius:2px;"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


<!-- ═══ FILTERS ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-filter"></i></span>
            <h2>Filters</h2>
        </div>
    </div>
    <div style="padding:16px 22px;">
        <form method="GET" action="reports.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div class="db-field" style="flex:1;min-width:130px;margin-bottom:0;"><label>Date From</label><input type="date" class="db-input" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></div>
            <div class="db-field" style="flex:1;min-width:130px;margin-bottom:0;"><label>Date To</label><input type="date" class="db-input" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></div>
            <div class="db-field" style="flex:1;min-width:130px;margin-bottom:0;"><label>Area/Zone</label>
                <select class="db-input" name="area">
                    <option value="">All Areas</option>
                    <?php foreach ($areas as $a): ?>
                    <option value="<?php echo htmlspecialchars($a['area_zone']); ?>" <?php echo $filter_area===$a['area_zone']?'selected':''; ?>><?php echo htmlspecialchars($a['area_zone']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="db-field" style="flex:1;min-width:130px;margin-bottom:0;"><label>Waste Type</label>
                <select class="db-input" name="waste_type">
                    <option value="">All Types</option>
                    <?php foreach (['biodegradable','non-biodegradable','recyclable','hazardous'] as $wt): ?>
                    <option value="<?php echo $wt; ?>" <?php echo $filter_type===$wt?'selected':''; ?>><?php echo ucfirst($wt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="db-field" style="flex:1;min-width:130px;margin-bottom:0;"><label>Status</label>
                <select class="db-input" name="status">
                    <option value="">All Status</option>
                    <?php foreach (['completed','partial','cancelled'] as $st): ?>
                    <option value="<?php echo $st; ?>" <?php echo $filter_status===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:8px;padding-bottom:0;">
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Filter</button>
                <a href="reports.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Clear</a>
                <button type="button" class="db-btn db-btn--ghost" onclick="exportCSV()"><i class="fas fa-file-excel"></i> CSV</button>
            </div>
        </form>
    </div>
</div>


<!-- ═══ REPORTS TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-clipboard-list"></i></span>
            <h2>Collection Reports <span style="font-family:'DM Mono',monospace;font-size:12px;color:var(--db-muted);font-weight:400;">(<?php echo number_format($total); ?> total)</span></h2>
        </div>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addReportModal')"><i class="fas fa-plus"></i> Add Report</button>
    </div>

    <?php if (!empty($reports)): ?>
    <div class="db-table-wrap">
        <table class="db-table" id="reportsTable">
            <thead>
                <tr><th>ID</th><th>Date</th><th>Area / Zone</th><th>Waste Type</th><th>Quantity</th><th>Collector</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php
            $type_badges  = ['biodegradable'=>'db-badge--success','non-biodegradable'=>'db-badge--danger','recyclable'=>'db-badge--info','hazardous'=>'db-badge--warning'];
            $status_badges= ['completed'=>'db-badge--success','partial'=>'db-badge--warning','cancelled'=>'db-badge--muted'];
            $type_icons   = ['biodegradable'=>'fa-leaf','non-biodegradable'=>'fa-times-circle','recyclable'=>'fa-recycle','hazardous'=>'fa-radiation'];
            foreach ($reports as $r):
                $tb = $type_badges[$r['waste_type']] ?? 'db-badge--muted';
                $sb = $status_badges[$r['status']] ?? 'db-badge--muted';
                $ti = $type_icons[$r['waste_type']] ?? 'fa-trash-alt';
            ?>
            <tr>
                <td><span class="db-id">#<?php echo (int)$r['id']; ?></span></td>
                <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($r['collection_date'])); ?></span></td>
                <td><strong><?php echo htmlspecialchars($r['area_zone']); ?></strong></td>
                <td>
                    <span class="db-badge <?php echo $tb; ?>">
                        <i class="fas <?php echo $ti; ?> me-1"></i><?php echo ucfirst($r['waste_type']); ?>
                    </span>
                </td>
                <td><span style="font-family:'DM Mono',monospace;font-size:12px;"><?php echo number_format($r['quantity_kg'],2); ?> kg</span></td>
                <td><?php echo htmlspecialchars($r['collector_name'] ?? '—'); ?></td>
                <td><span class="db-badge <?php echo $sb; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                <td>
                    <div class="db-btn-group">
                        <button class="db-icon-btn db-icon-btn--info" onclick='viewReport(<?php echo htmlspecialchars(json_encode($r),ENT_QUOTES); ?>)' title="View"><i class="fas fa-eye"></i></button>
                        <button class="db-icon-btn db-icon-btn--primary" onclick='editReport(<?php echo htmlspecialchars(json_encode($r),ENT_QUOTES); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="db-icon-btn db-icon-btn--danger" onclick="openDeleteModal(<?php echo (int)$r['id']; ?>,'<?php echo htmlspecialchars($r['area_zone'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($r['collection_date'],ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="db-panel__footer" style="display:flex;justify-content:center;gap:4px;flex-wrap:wrap;">
        <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?><?php echo bqs(['page']); ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
        <?php for ($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?>
        <a href="?page=<?php echo $i; ?><?php echo bqs(['page']); ?>" class="db-btn db-btn--sm <?php echo $i===$page?'db-btn--primary':'db-btn--ghost'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="?page=<?php echo $page+1; ?><?php echo bqs(['page']); ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-clipboard-list"></i>
        <p>No collection reports found<?php echo ($date_from||$filter_area||$filter_type||$filter_status) ? ' matching your filters.' : '. Add your first report!'; ?></p>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addReportModal')"><i class="fas fa-plus"></i> Add Report</button>
    </div>
    <?php endif; ?>
</div>


<!-- ═══ ADD REPORT MODAL ═══ -->
<div id="addReportModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-plus-circle"></i> Add Collection Report</h3>
            <button class="db-modal__close" onclick="closeModal('addReportModal')">×</button>
        </div>
        <form method="POST" action="reports.php" class="db-modal__body">
            <input type="hidden" name="action" value="add">
            <div class="db-field-row">
                <div class="db-field"><label>Collection Date <span class="req">*</span></label><input type="date" class="db-input" name="collection_date" value="<?php echo date('Y-m-d'); ?>" required></div>
                <div class="db-field"><label>Area / Zone <span class="req">*</span></label><input type="text" class="db-input" name="area_zone" placeholder="e.g., Zone A" required></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Waste Type <span class="req">*</span></label>
                    <select class="db-input" name="waste_type" required>
                        <option value="">Select Type</option>
                        <?php foreach(['biodegradable','non-biodegradable','recyclable','hazardous'] as $wt): ?>
                        <option value="<?php echo $wt; ?>"><?php echo ucfirst($wt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="db-field"><label>Quantity (kg) <span class="req">*</span></label><input type="number" class="db-input" name="quantity_kg" step="0.01" min="0" required></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Collector Name <span class="req">*</span></label><input type="text" class="db-input" name="collector_name" required></div>
                <div class="db-field"><label>Status <span class="req">*</span></label>
                    <select class="db-input" name="status" required>
                        <option value="completed">Completed</option>
                        <option value="partial">Partial</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Save Report</button>
        </form>
    </div>
</div>

<!-- ═══ EDIT REPORT MODAL ═══ -->
<div id="editReportModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Collection Report</h3>
            <button class="db-modal__close" onclick="closeModal('editReportModal')">×</button>
        </div>
        <form method="POST" action="reports.php" class="db-modal__body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="db-field-row">
                <div class="db-field"><label>Collection Date <span class="req">*</span></label><input type="date" class="db-input" id="edit_date" name="collection_date" required></div>
                <div class="db-field"><label>Area / Zone <span class="req">*</span></label><input type="text" class="db-input" id="edit_area" name="area_zone" required></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Waste Type <span class="req">*</span></label>
                    <select class="db-input" id="edit_waste_type" name="waste_type" required>
                        <?php foreach(['biodegradable','non-biodegradable','recyclable','hazardous'] as $wt): ?>
                        <option value="<?php echo $wt; ?>"><?php echo ucfirst($wt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="db-field"><label>Quantity (kg) <span class="req">*</span></label><input type="number" class="db-input" id="edit_qty" name="quantity_kg" step="0.01" min="0" required></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Collector Name <span class="req">*</span></label><input type="text" class="db-input" id="edit_collector" name="collector_name" required></div>
                <div class="db-field"><label>Status <span class="req">*</span></label>
                    <select class="db-input" id="edit_status" name="status" required>
                        <option value="completed">Completed</option>
                        <option value="partial">Partial</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Report</button>
        </form>
    </div>
</div>

<!-- ═══ VIEW REPORT MODAL ═══ -->
<div id="viewReportModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-file-alt"></i> Report Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewReportModal')">×</button>
        </div>
        <div class="db-modal__body" id="viewReportContent"></div>
    </div>
</div>

<!-- ═══ DELETE CONFIRM MODAL ═══ -->
<div id="deleteReportModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteReportModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete this collection report?</p>
            <div class="db-delete-target" id="delete_report_label"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST" action="reports.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_report_id">
                <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteReportModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

const typeBadgeClasses  = {biodegradable:'db-badge--success','non-biodegradable':'db-badge--danger',recyclable:'db-badge--info',hazardous:'db-badge--warning'};
const statusBadgeClasses= {completed:'db-badge--success',partial:'db-badge--warning',cancelled:'db-badge--muted'};

function editReport(r) {
    document.getElementById('edit_id').value        = r.id;
    document.getElementById('edit_date').value      = r.collection_date;
    document.getElementById('edit_area').value      = r.area_zone;
    document.getElementById('edit_waste_type').value= r.waste_type;
    document.getElementById('edit_qty').value       = r.quantity_kg;
    document.getElementById('edit_collector').value = r.collector_name || '';
    document.getElementById('edit_status').value    = r.status;
    openModal('editReportModal');
}

function viewReport(r) {
    const tb = typeBadgeClasses[r.waste_type]   || 'db-badge--muted';
    const sb = statusBadgeClasses[r.status]     || 'db-badge--muted';
    document.getElementById('viewReportContent').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Report ID</div><span class="db-id">#${r.id}</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Date</div>${new Date(r.collection_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Area / Zone</div><strong>${r.area_zone}</strong></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Collector</div>${r.collector_name||'—'}</div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Waste Type</div><span class="db-badge ${tb}">${r.waste_type.charAt(0).toUpperCase()+r.waste_type.slice(1)}</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Quantity</div><span style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;">${parseFloat(r.quantity_kg).toFixed(2)} kg</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Status</div><span class="db-badge ${sb}">${r.status.charAt(0).toUpperCase()+r.status.slice(1)}</span></div>
        </div>
        <div style="margin-top:16px;">
            <button class="db-btn db-btn--ghost db-btn--sm" onclick="closeModal('viewReportModal')">Close</button>
        </div>
    `;
    openModal('viewReportModal');
}

function openDeleteModal(id, area, date) {
    document.getElementById('delete_report_id').value    = id;
    document.getElementById('delete_report_label').textContent = `Report #${id} — ${area} (${date})`;
    openModal('deleteReportModal');
}

function exportCSV() {
    const table = document.getElementById('reportsTable');
    if (!table) return;
    let csv = [];
    const headers = [];
    table.querySelectorAll('thead th').forEach((th,i,arr) => { if(i<arr.length-1) headers.push(th.textContent.trim()); });
    csv.push(headers.join(','));
    table.querySelectorAll('tbody tr').forEach(row => {
        const cells = []; let i=0;
        row.querySelectorAll('td').forEach((td,idx,arr) => {
            if (idx < arr.length-1) {
                let t = td.textContent.trim().replace(/"/g,'""');
                if (t.includes(',') || t.includes('"') || t.includes('\n')) t='"'+t+'"';
                cells.push(t);
            }
        });
        csv.push(cells.join(','));
    });
    const blob = new Blob([csv.join('\n')],{type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'waste_reports_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php require_once '../../../includes/footer.php'; ?>