<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Health Records Management';

$search          = isset($_GET['search'])   ? trim($_GET['search'])   : '';
$filter_verified = isset($_GET['verified']) ? $_GET['verified']       : '';

$page             = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$records_per_page = 15;
$offset           = ($page - 1) * $records_per_page;

$where_clauses = ["1=1"]; $params = []; $types = "";
if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ?)";
    $sp = "%$search%"; $params[]=$sp; $params[]=$sp; $params[]=$sp; $types.="sss";
}
if ($filter_verified !== '') {
    $where_clauses[] = "r.is_verified = ?"; $params[]=(int)$filter_verified; $types.="i";
}
$where_sql = implode(" AND ", $where_clauses);

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_residents r LEFT JOIN tbl_health_records hr ON r.resident_id=hr.resident_id WHERE $where_sql");
if ($params) $stmt->bind_param($types,...$params);
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$total_pages   = ceil($total_records / $records_per_page);
$stmt->close();

$sql = "SELECT r.resident_id,r.first_name,r.last_name,r.email,r.contact_number,r.date_of_birth,r.gender,r.is_verified,
               hr.record_id,hr.blood_type,hr.height,hr.weight,hr.last_checkup_date,hr.philhealth_number,hr.pwd_id,hr.senior_citizen_id,hr.updated_at AS health_record_updated
        FROM tbl_residents r
        LEFT JOIN tbl_health_records hr ON r.resident_id=hr.resident_id
        WHERE $where_sql ORDER BY r.last_name,r.first_name LIMIT ? OFFSET ?";
$p2 = $params; $p2[]=$records_per_page; $p2[]=$offset; $t2=$types."ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($t2,...$p2);
$stmt->execute();
$residents = $stmt->get_result();
$stmt->close();

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
            <div class="rm-hero__icon db-panel__icon--sky" style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0ea5e9,#0284c7);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);">
                <i class="fas fa-notes-medical"></i>
            </div>
            <div>
                <div class="rm-hero__title">Health Records</div>
                <div class="rm-hero__sub">Manage resident health information and medical records</div>
            </div>
        </div>
        <div class="rm-hero__badges">
            <span class="db-badge db-badge--sky" style="font-size:11px;padding:5px 12px;">
                <i class="fas fa-users"></i> <?php echo number_format($total_records); ?> residents
            </span>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<!-- Filter Bar -->
<div class="db-panel" style="margin-bottom:18px;animation-delay:.05s;">
    <div class="db-panel__body" style="padding:14px 18px;">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div style="flex:1;min-width:220px;">
                <label class="db-form-label" style="margin-bottom:5px;">Search</label>
                <div style="position:relative;">
                    <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--db-muted);font-size:12px;"></i>
                    <input type="text" name="search" class="db-form-control" style="padding-left:32px;"
                           placeholder="Search by name or email…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div style="min-width:170px;">
                <label class="db-form-label" style="margin-bottom:5px;">Status</label>
                <select name="verified" class="db-form-select">
                    <option value="">All Residents</option>
                    <option value="1" <?php echo $filter_verified==='1'?'selected':''; ?>>Verified Only</option>
                    <option value="0" <?php echo $filter_verified==='0'?'selected':''; ?>>Unverified Only</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;padding-bottom:1px;">
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($search || $filter_verified!==''): ?>
                <a href="records.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="db-panel" style="animation-delay:.1s;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-list"></i></div>
            <h2>Residents</h2>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Resident Name</th>
                    <th>Age / Gender</th>
                    <th>Contact</th>
                    <th>Blood Type</th>
                    <th>PhilHealth</th>
                    <th>Last Checkup</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($residents->num_rows > 0): ?>
                <?php while ($r = $residents->fetch_assoc()):
                    $age = $r['date_of_birth'] ? floor((time()-strtotime($r['date_of_birth']))/31556926) : 'N/A';
                    $has_hr = !empty($r['record_id']);
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></div>
                        <?php if (!$has_hr): ?>
                        <span class="db-badge db-badge--amber" style="margin-top:3px;"><i class="fas fa-exclamation-circle"></i> No Health Record</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="db-text-sm"><?php echo $age; ?> / <?php echo $r['gender']?:'N/A'; ?></span></td>
                    <td>
                        <div style="font-size:12.5px;"><?php echo $r['contact_number']?:'N/A'; ?></div>
                        <div style="font-size:11px;color:var(--db-muted);"><?php echo $r['email']?:''; ?></div>
                    </td>
                    <td>
                        <?php if ($r['blood_type']): ?>
                        <span class="db-badge db-badge--rose" style="font-family:'DM Mono',monospace;font-size:11px;"><?php echo htmlspecialchars($r['blood_type']); ?></span>
                        <?php else: ?>
                        <span style="color:var(--db-muted);font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['philhealth_number']): ?>
                        <span class="db-badge db-badge--success"><i class="fas fa-check"></i> Yes</span>
                        <?php else: ?>
                        <span class="db-badge db-badge--muted">No</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['last_checkup_date']): ?>
                        <span class="db-text-sm"><?php echo date('M d, Y',strtotime($r['last_checkup_date'])); ?></span>
                        <?php else: ?>
                        <span class="db-badge db-badge--muted">Never</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['is_verified']): ?>
                        <span class="db-badge db-badge--success"><i class="fas fa-check-circle"></i> Verified</span>
                        <?php else: ?>
                        <span class="db-badge db-badge--muted">Unverified</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <button class="db-btn db-btn--primary db-btn--sm" onclick="viewHealthRecord(<?php echo $r['resident_id']; ?>)" title="View / Edit Health Record">
                                <i class="fas fa-notes-medical"></i>
                            </button>
                            <button class="db-btn db-btn--ghost db-btn--sm" onclick="viewVaccinations(<?php echo $r['resident_id']; ?>)" title="Vaccinations">
                                <i class="fas fa-syringe"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8">
                    <div style="text-align:center;padding:40px;color:var(--db-muted);">
                        <i class="fas fa-inbox" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                        No residents found
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-top:1px solid var(--db-border);">
        <span style="font-size:12px;color:var(--db-muted);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        <div style="display:flex;gap:8px;">
            <?php
            $qs = http_build_query(array_filter(['search'=>$search,'verified'=>$filter_verified]));
            if ($page > 1): ?>
            <a href="?page=<?php echo $page-1; ?><?php echo $qs?"&$qs":''; ?>" class="db-btn db-btn--ghost db-btn--sm">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <?php endif; if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page+1; ?><?php echo $qs?"&$qs":''; ?>" class="db-btn db-btn--primary db-btn--sm">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</div><!-- /padding wrapper -->

<!-- Health Record Modal (Bootstrap) -->
<div class="modal fade" id="healthRecordModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-notes-medical"></i> Health Record
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="healthRecordContent" style="padding:20px;">
                <div style="text-align:center;padding:32px;color:var(--db-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i>
                    <p style="margin-top:10px;">Loading…</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewHealthRecord(id) {
    document.getElementById('healthRecordContent').innerHTML =
        '<div style="text-align:center;padding:32px;color:var(--db-muted);"><i class="fas fa-spinner fa-spin" style="font-size:1.5rem;"></i><p style="margin-top:10px;">Loading…</p></div>';
    new bootstrap.Modal(document.getElementById('healthRecordModal')).show();
    fetch(`actions/get-health-record.php?resident_id=${id}`)
        .then(r => r.text())
        .then(html => { document.getElementById('healthRecordContent').innerHTML = html; })
        .catch(() => { document.getElementById('healthRecordContent').innerHTML = '<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> Error loading health record.</div>'; });
}

function viewVaccinations(id) {
    window.location.href = `vaccinations.php?resident_id=${id}`;
}
</script>

<?php include '../../includes/footer.php'; ?>