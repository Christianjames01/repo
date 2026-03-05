<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Vaccination Records';

$search      = isset($_GET['search'])      ? trim($_GET['search'])      : '';
$vaccine_type= isset($_GET['vaccine_type'])? $_GET['vaccine_type']       : '';
$date_from   = isset($_GET['date_from'])   ? $_GET['date_from']          : '';
$date_to     = isset($_GET['date_to'])     ? $_GET['date_to']            : '';
$resident_id = isset($_GET['resident_id']) ? (int)$_GET['resident_id']   : 0;

$page             = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$records_per_page = 20;
$offset           = ($page-1)*$records_per_page;

$where_clauses = ["1=1"]; $params=[]; $types="";
if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR v.vaccine_name LIKE ?)";
    $sp="%$search%"; $params[]=$sp; $params[]=$sp; $params[]=$sp; $types.="sss";
}
if ($vaccine_type) { $where_clauses[]="v.vaccine_type=?"; $params[]=$vaccine_type; $types.="s"; }
if ($date_from)    { $where_clauses[]="v.vaccination_date>=?"; $params[]=$date_from; $types.="s"; }
if ($date_to)      { $where_clauses[]="v.vaccination_date<=?"; $params[]=$date_to;   $types.="s"; }
if ($resident_id)  { $where_clauses[]="v.resident_id=?"; $params[]=$resident_id; $types.="i"; }
$where_sql = implode(" AND ",$where_clauses);

// Count
$stmt=$conn->prepare("SELECT COUNT(*) as total FROM tbl_vaccination_records v JOIN tbl_residents r ON v.resident_id=r.resident_id WHERE $where_sql");
if ($params) $stmt->bind_param($types,...$params);
$stmt->execute();
$total_records=$stmt->get_result()->fetch_assoc()['total'];
$total_pages=ceil($total_records/$records_per_page);
$stmt->close();

// Data
$p2=$params; $p2[]=$records_per_page; $p2[]=$offset; $t2=$types."ii";
$stmt=$conn->prepare("SELECT v.*,r.first_name,r.last_name,r.date_of_birth,r.gender,u.username as created_by_name
    FROM tbl_vaccination_records v
    JOIN tbl_residents r ON v.resident_id=r.resident_id
    LEFT JOIN tbl_users u ON v.created_by=u.user_id
    WHERE $where_sql ORDER BY v.vaccination_date DESC,v.created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param($t2,...$p2);
$stmt->execute();
$vaccinations=$stmt->get_result();
$stmt->close();

// Stats
$sp2=array_slice($params,0); $st2=$types;
$stmt=$conn->prepare("SELECT COUNT(*) as total_vaccinations,COUNT(DISTINCT v.resident_id) as unique_residents,
    SUM(CASE WHEN MONTH(v.vaccination_date)=MONTH(CURRENT_DATE()) AND YEAR(v.vaccination_date)=YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) as this_month
    FROM tbl_vaccination_records v JOIN tbl_residents r ON v.resident_id=r.resident_id WHERE $where_sql");
if ($sp2) $stmt->bind_param($st2,...$sp2);
$stmt->execute();
$stats=$stmt->get_result()->fetch_assoc();
$stmt->close();

$vaccine_types=$conn->query("SELECT DISTINCT vaccine_type FROM tbl_vaccination_records WHERE vaccine_type IS NOT NULL ORDER BY vaccine_type");

// Resident list for Add modal
$resident_list=$conn->query("SELECT resident_id,first_name,last_name FROM tbl_residents WHERE is_verified=1 ORDER BY last_name,first_name");

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
            <div class="rm-hero__icon" style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#10b981,#059669);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(16,185,129,.4);">
                <i class="fas fa-syringe"></i>
            </div>
            <div>
                <div class="rm-hero__title">Vaccination Records</div>
                <div class="rm-hero__sub">Track and manage resident vaccination records</div>
            </div>
        </div>
        <button class="db-btn db-btn--glass db-btn--sm" onclick="openAddModal()">
            <i class="fas fa-plus"></i> Record Vaccination
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
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-syringe"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo number_format($stats['total_vaccinations']); ?></div><div class="db-stat-card__label">Total Vaccinations</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($stats['unique_residents']); ?></div><div class="db-stat-card__label">Vaccinated Residents</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--purple"><i class="fas fa-calendar-alt"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-purple)"><?php echo number_format($stats['this_month']); ?></div><div class="db-stat-card__label">This Month</div></div>
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
                           placeholder="Resident or vaccine…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div style="min-width:160px;">
                <label class="db-form-label" style="margin-bottom:5px;">Vaccine Type</label>
                <select name="vaccine_type" class="db-form-select">
                    <option value="">All Types</option>
                    <?php while ($t=$vaccine_types->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($t['vaccine_type']); ?>" <?php echo $vaccine_type===$t['vaccine_type']?'selected':''; ?>>
                        <?php echo htmlspecialchars($t['vaccine_type']); ?>
                    </option>
                    <?php endwhile; ?>
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
                <?php if ($search||$vaccine_type||$date_from||$date_to||$resident_id): ?>
                <a href="vaccinations.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--success"><i class="fas fa-list"></i></div>
            <h2>Vaccination Records</h2>
            <span class="db-badge db-badge--muted"><?php echo number_format($total_records); ?> total</span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Resident</th>
                    <th>Vaccine</th>
                    <th>Type</th>
                    <th>Dose</th>
                    <th>Brand</th>
                    <th>Next Dose</th>
                    <th>Administered By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($vaccinations->num_rows > 0): ?>
                <?php while ($vax=$vaccinations->fetch_assoc()):
                    $age=$vax['date_of_birth']?floor((time()-strtotime($vax['date_of_birth']))/31556926):'N/A';
                    $is_complete=($vax['dose_number']>=$vax['total_doses']);
                    $today=strtotime(date('Y-m-d'));
                ?>
                <tr>
                    <td><span class="db-text-sm"><?php echo date('M d, Y',strtotime($vax['vaccination_date'])); ?></span></td>
                    <td>
                        <div style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($vax['first_name'].' '.$vax['last_name']); ?></div>
                        <div style="font-size:11px;color:var(--db-muted);"><?php echo $age; ?> yrs · <?php echo $vax['gender']; ?></div>
                    </td>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($vax['vaccine_name']); ?></td>
                    <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($vax['vaccine_type']?:'N/A'); ?></span></td>
                    <td>
                        <span class="db-badge <?php echo $is_complete?'db-badge--success':'db-badge--amber'; ?>">
                            <?php if ($is_complete): ?><i class="fas fa-check"></i><?php endif; ?>
                            <?php echo $vax['dose_number'].'/'.$vax['total_doses']; ?>
                        </span>
                    </td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($vax['vaccine_brand']?:'—'); ?></span></td>
                    <td>
                        <?php if (!empty($vax['next_dose_date'])):
                            $nd=strtotime($vax['next_dose_date']);
                            $diff=floor(($nd-$today)/86400);
                        ?>
                        <div style="font-size:12.5px;font-weight:600;"><?php echo date('M d, Y',$nd); ?></div>
                        <?php if ($diff<0): ?>
                            <span class="db-badge db-badge--rose"><i class="fas fa-exclamation-triangle"></i> <?php echo abs($diff); ?>d overdue</span>
                        <?php elseif ($diff<=7): ?>
                            <span class="db-badge db-badge--amber"><i class="fas fa-clock"></i> <?php echo $diff; ?>d left</span>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--db-muted);">In <?php echo $diff; ?> days</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:var(--db-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($vax['administered_by']?:'—'); ?></span></td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <button class="db-btn db-btn--ghost db-btn--sm" onclick='viewVaccination(<?php echo json_encode($vax); ?>)' title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="db-btn db-btn--primary db-btn--sm" onclick='editVaccination(<?php echo json_encode($vax); ?>)' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="9">
                    <div style="text-align:center;padding:40px;color:var(--db-muted);">
                        <i class="fas fa-syringe" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                        No vaccination records found
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages>1):
        $qs=http_build_query(array_filter(['search'=>$search,'vaccine_type'=>$vaccine_type,'date_from'=>$date_from,'date_to'=>$date_to,'resident_id'=>$resident_id?:null]));
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-top:1px solid var(--db-border);">
        <span style="font-size:12px;color:var(--db-muted);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        <div style="display:flex;gap:8px;">
            <?php if ($page>1): ?>
            <a href="?page=<?php echo $page-1; ?><?php echo $qs?"&$qs":''; ?>" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-chevron-left"></i> Previous</a>
            <?php endif; if ($page<$total_pages): ?>
            <a href="?page=<?php echo $page+1; ?><?php echo $qs?"&$qs":''; ?>" class="db-btn db-btn--primary db-btn--sm">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

</div><!-- /padding wrapper -->

<!-- ── ADD MODAL ── -->
<div class="modal fade" id="addVaccinationModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;">
                    <i class="fas fa-syringe"></i> Record Vaccination
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="actions/add-vaccination.php" method="POST">
                <div class="modal-body" style="padding:20px;">
                    <div class="db-form-row db-form-row--2">
                        <div class="db-form-group">
                            <label class="db-form-label">Resident <span style="color:var(--db-rose);">*</span></label>
                            <select name="resident_id" class="db-form-select" required>
                                <option value="">Select Resident</option>
                                <?php while ($res=$resident_list->fetch_assoc()): ?>
                                <option value="<?php echo $res['resident_id']; ?>"><?php echo htmlspecialchars($res['last_name'].', '.$res['first_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Vaccine Name <span style="color:var(--db-rose);">*</span></label>
                            <input type="text" name="vaccine_name" class="db-form-control" required placeholder="e.g., COVID-19, Influenza">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Vaccine Type <span style="color:var(--db-rose);">*</span></label>
                            <select name="vaccine_type" class="db-form-select" required>
                                <option value="">Select Type</option>
                                <?php foreach(['COVID-19','Influenza','Measles','Polio','Hepatitis','Tetanus','Pneumonia','Other'] as $vt): ?>
                                <option value="<?php echo $vt; ?>"><?php echo $vt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Vaccination Date <span style="color:var(--db-rose);">*</span></label>
                            <input type="date" name="vaccination_date" class="db-form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Dose Number <span style="color:var(--db-rose);">*</span></label>
                            <input type="number" name="dose_number" class="db-form-control" min="1" value="1" required>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Total Doses <span style="color:var(--db-rose);">*</span></label>
                            <input type="number" name="total_doses" class="db-form-control" min="1" value="1" required>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Next Dose Date</label>
                            <input type="date" name="next_dose_date" class="db-form-control">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Vaccine Brand</label>
                            <input type="text" name="vaccine_brand" class="db-form-control" placeholder="e.g., Pfizer, Sinovac">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Batch Number</label>
                            <input type="text" name="batch_number" class="db-form-control">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Administered By <span style="color:var(--db-rose);">*</span></label>
                            <input type="text" name="administered_by" class="db-form-control" required placeholder="Doctor / Nurse name">
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Vaccination Site</label>
                            <input type="text" name="vaccination_site" class="db-form-control" placeholder="e.g., Barangay Health Center">
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Side Effects</label>
                            <textarea name="side_effects" class="db-form-textarea" rows="2"></textarea>
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Remarks</label>
                            <textarea name="remarks" class="db-form-textarea" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-save"></i> Record Vaccination</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() { new bootstrap.Modal(document.getElementById('addVaccinationModal')).show(); }

function viewVaccination(vax) {
    const age = vax.date_of_birth ? Math.floor((new Date()-new Date(vax.date_of_birth))/31556926000) : 'N/A';
    const isComplete = vax.dose_number >= vax.total_doses;
    const nd = vax.next_dose_date ? new Date(vax.next_dose_date) : null;
    const isOverdue = nd && nd < new Date() && !isComplete;
    const fmt = d => new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});

    const infoRow = (label,value) =>
        `<div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--db-border);font-size:13px;">
            <span style="min-width:160px;color:var(--db-muted);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;padding-top:2px;">${label}</span>
            <span style="flex:1;color:var(--db-text);">${value}</span>
        </div>`;

    const body = `
        <div style="margin-bottom:18px;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Patient</div>
            ${infoRow('Name','<strong>'+vax.first_name+' '+vax.last_name+'</strong>')}
            ${infoRow('Age',age+' years old')}
            ${infoRow('Gender',vax.gender||'N/A')}
        </div>
        <div style="margin-bottom:18px;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Vaccine</div>
            ${infoRow('Name','<strong>'+vax.vaccine_name+'</strong>')}
            ${infoRow('Type','<span class="db-badge db-badge--sky">'+(vax.vaccine_type||'N/A')+'</span>')}
            ${infoRow('Brand',vax.vaccine_brand||'N/A')}
            ${infoRow('Batch',vax.batch_number||'N/A')}
        </div>
        <div><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Details</div>
            ${infoRow('Date',fmt(vax.vaccination_date))}
            ${infoRow('Dose','<span class="db-badge '+(isComplete?'db-badge--success':'db-badge--amber')+'">Dose '+vax.dose_number+'/'+vax.total_doses+(isComplete?' ✓':'')+'</span>')}
            ${nd ? infoRow('Next Dose','<span style="color:'+(isOverdue?'var(--db-rose)':'var(--db-amber-dark)')+';">'+fmt(vax.next_dose_date)+(isOverdue?' <i class=\"fas fa-exclamation-triangle\"></i> Overdue':'')+'</span>') : ''}
            ${infoRow('Administered By',vax.administered_by||'N/A')}
            ${infoRow('Site',vax.vaccination_site||'N/A')}
            ${vax.side_effects ? infoRow('Side Effects',vax.side_effects) : ''}
            ${vax.remarks ? infoRow('Remarks',vax.remarks) : ''}
        </div>`;

    const el = document.createElement('div');
    el.className='modal fade'; el.id='dynViewModal'; el.setAttribute('tabindex','-1');
    el.innerHTML=`<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-syringe"></i> Vaccination Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:20px;max-height:65vh;overflow-y:auto;">${body}</div>
        <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
            <button class="db-btn db-btn--primary" onclick="this.closest('.modal').dispatchEvent(new Event('close-and-edit'))"><i class="fas fa-edit"></i> Edit</button>
            <button class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
        </div>
    </div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el);
    el.addEventListener('close-and-edit', () => { m.hide(); el.addEventListener('hidden.bs.modal', () => { el.remove(); editVaccination(vax); }); });
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
}

function editVaccination(vax) {
    const opt = (val, label, sel) => `<option value="${val}" ${sel===val?'selected':''}>${label}</option>`;
    const types = ['COVID-19','Influenza','Measles','Polio','Hepatitis','Tetanus','Pneumonia','Other'];

    const el = document.createElement('div');
    el.className='modal fade'; el.setAttribute('tabindex','-1');
    el.innerHTML=`<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-edit"></i> Edit Vaccination Record</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form action="actions/update-vaccination.php" method="POST">
            <input type="hidden" name="vaccination_id" value="${vax.vaccination_id}">
            <input type="hidden" name="resident_id" value="${vax.resident_id}">
            <div class="modal-body" style="padding:20px;max-height:65vh;overflow-y:auto;">
                <div class="db-form-row db-form-row--2">
                    <div class="db-form-group" style="grid-column:1/-1;">
                        <label class="db-form-label">Resident</label>
                        <input type="text" class="db-form-control" value="${vax.first_name} ${vax.last_name}" disabled style="background:var(--db-surf2);color:var(--db-muted);">
                    </div>
                    <div class="db-form-group"><label class="db-form-label">Vaccine Name *</label><input type="text" name="vaccine_name" class="db-form-control" required value="${vax.vaccine_name}"></div>
                    <div class="db-form-group"><label class="db-form-label">Vaccine Type *</label><select name="vaccine_type" class="db-form-select" required>${types.map(t=>opt(t,t,vax.vaccine_type)).join('')}</select></div>
                    <div class="db-form-group"><label class="db-form-label">Vaccination Date *</label><input type="date" name="vaccination_date" class="db-form-control" required value="${vax.vaccination_date}"></div>
                    <div class="db-form-group"><label class="db-form-label">Dose Number *</label><input type="number" name="dose_number" class="db-form-control" min="1" value="${vax.dose_number}" required></div>
                    <div class="db-form-group"><label class="db-form-label">Total Doses *</label><input type="number" name="total_doses" class="db-form-control" min="1" value="${vax.total_doses}" required></div>
                    <div class="db-form-group"><label class="db-form-label">Next Dose Date</label><input type="date" name="next_dose_date" class="db-form-control" value="${vax.next_dose_date||''}"></div>
                    <div class="db-form-group"><label class="db-form-label">Vaccine Brand</label><input type="text" name="vaccine_brand" class="db-form-control" value="${vax.vaccine_brand||''}"></div>
                    <div class="db-form-group"><label class="db-form-label">Batch Number</label><input type="text" name="batch_number" class="db-form-control" value="${vax.batch_number||''}"></div>
                    <div class="db-form-group"><label class="db-form-label">Administered By *</label><input type="text" name="administered_by" class="db-form-control" required value="${vax.administered_by||''}"></div>
                    <div class="db-form-group" style="grid-column:1/-1;"><label class="db-form-label">Vaccination Site</label><input type="text" name="vaccination_site" class="db-form-control" value="${vax.vaccination_site||''}"></div>
                    <div class="db-form-group" style="grid-column:1/-1;"><label class="db-form-label">Side Effects</label><textarea name="side_effects" class="db-form-textarea" rows="2">${vax.side_effects||''}</textarea></div>
                    <div class="db-form-group" style="grid-column:1/-1;"><label class="db-form-label">Remarks</label><textarea name="remarks" class="db-form-textarea" rows="2">${vax.remarks||''}</textarea></div>
                </div>
            </div>
            <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Update Vaccination</button>
            </div>
        </form>
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