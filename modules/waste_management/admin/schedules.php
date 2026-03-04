<?php
require_once('../../../config/config.php');
requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Waste Collection Schedules";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'add':
            $sql = "INSERT INTO tbl_waste_schedules (area_zone,purok,waste_type,collection_day,collection_time,collector_name,truck_number,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)";
            $ok = execute($conn,$sql,[sanitize($_POST['area_zone']),sanitize($_POST['purok']),sanitize($_POST['waste_type']),sanitize($_POST['collection_day']),sanitize($_POST['collection_time']),sanitize($_POST['collector_name']),sanitize($_POST['truck_number']),sanitize($_POST['notes']),$_SESSION['user_id']],'ssssssssi');
            $_SESSION['temp_success'] = $ok ? "Schedule added successfully!" : "Failed to add schedule.";
            header("Location: schedules.php"); exit;
        case 'edit':
            $id = (int)$_POST['schedule_id'];
            $sql = "UPDATE tbl_waste_schedules SET area_zone=?,purok=?,waste_type=?,collection_day=?,collection_time=?,collector_name=?,truck_number=?,status=?,notes=? WHERE schedule_id=?";
            $ok = execute($conn,$sql,[sanitize($_POST['area_zone']),sanitize($_POST['purok']),sanitize($_POST['waste_type']),sanitize($_POST['collection_day']),sanitize($_POST['collection_time']),sanitize($_POST['collector_name']),sanitize($_POST['truck_number']),sanitize($_POST['status']),sanitize($_POST['notes']),$id],'sssssssssi');
            $_SESSION['temp_success'] = $ok ? "Schedule updated successfully!" : "Failed to update schedule.";
            header("Location: schedules.php"); exit;
        case 'delete':
            $id = (int)$_POST['schedule_id'];
            if ($id > 0) {
                $ok = execute($conn,"DELETE FROM tbl_waste_schedules WHERE schedule_id=?",[$id],'i');
                $_SESSION['temp_success'] = $ok ? "Schedule deleted successfully!" : "Failed to delete schedule.";
            } else { $_SESSION['temp_error'] = "Invalid schedule ID."; }
            header("Location: schedules.php"); exit;
    }
}

$success_message = $error_message = '';
if (isset($_SESSION['temp_success'])) { $success_message=$_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message=$_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$schedules = fetchAll($conn,"SELECT * FROM tbl_waste_schedules ORDER BY FIELD(collection_day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), collection_time ASC",[],'' );

// Group by day for a nicer view option
$by_day = [];
foreach ($schedules as $s) {
    $by_day[$s['collection_day']][] = $s;
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
require_once '../../../includes/header.php';

$type_badges  = ['biodegradable'=>'db-badge--success','non-biodegradable'=>'db-badge--danger','recyclable'=>'db-badge--info','hazardous'=>'db-badge--warning','mixed'=>'db-badge--muted'];
$status_badges= ['active'=>'db-badge--success','inactive'=>'db-badge--muted','suspended'=>'db-badge--danger'];
$type_icons   = ['biodegradable'=>'fa-leaf','non-biodegradable'=>'fa-times-circle','recyclable'=>'fa-recycle','hazardous'=>'fa-radiation','mixed'=>'fa-trash-alt'];
$day_colors   = ['Monday'=>'var(--db-indigo)','Tuesday'=>'var(--db-sky)','Wednesday'=>'var(--db-teal)','Thursday'=>'var(--db-success)','Friday'=>'var(--db-amber-dark)','Saturday'=>'var(--db-rose)','Sunday'=>'var(--db-muted)'];
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#6366f1,#4338ca);">
                <i class="fas fa-calendar-alt" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Waste Management
                </div>
                <h1 class="db-hero__title">Collection Schedules</h1>
                <p class="db-hero__sub">Manage barangay waste collection routes and timetables</p>
            </div>
        </div>
        <div class="db-hero__right">
            <button class="db-btn db-btn--primary" onclick="openModal('addScheduleModal')">
                <i class="fas fa-plus"></i> Add Schedule
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

<!-- ═══ QUICK STATS ═══ -->
<?php if (!empty($schedules)): ?>
<div class="db-stats-row">
    <?php
    $total_active    = count(array_filter($schedules, fn($s)=>$s['status']==='active'));
    $total_inactive  = count(array_filter($schedules, fn($s)=>$s['status']==='inactive'));
    $total_suspended = count(array_filter($schedules, fn($s)=>$s['status']==='suspended'));
    $unique_days     = count($by_day);
    ?>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-calendar-check"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo count($schedules); ?></div>
            <div class="db-stat-card__label">Total Schedules</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-check-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $total_active; ?></div>
            <div class="db-stat-card__label">Active</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-calendar-alt"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $unique_days; ?></div>
            <div class="db-stat-card__label">Days Covered</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <?php if ($total_suspended > 0): ?>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-pause-circle"></i></div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $total_suspended; ?></div>
            <div class="db-stat-card__label">Suspended</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ═══ SCHEDULES TABLE ═══ -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-calendar-alt"></i></span>
            <h2>All Schedules</h2>
        </div>
        <div style="display:flex;gap:6px;">
            <button class="db-btn db-btn--ghost db-btn--sm" onclick="toggleView()" id="viewToggleBtn"><i class="fas fa-th-large"></i> Card View</button>
            <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addScheduleModal')"><i class="fas fa-plus"></i> Add</button>
        </div>
    </div>

    <?php if (!empty($schedules)): ?>

    <!-- TABLE VIEW -->
    <div id="tableView">
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>ID</th><th>Area / Zone</th><th>Purok</th><th>Waste Type</th><th>Day</th><th>Time</th><th>Collector</th><th>Truck</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $s):
                $tb = $type_badges[$s['waste_type']] ?? 'db-badge--muted';
                $sb = $status_badges[$s['status']]   ?? 'db-badge--muted';
                $ti = $type_icons[$s['waste_type']]  ?? 'fa-trash-alt';
                $dc = $day_colors[$s['collection_day']] ?? 'var(--db-muted)';
            ?>
            <tr>
                <td><span class="db-id">#<?php echo (int)$s['schedule_id']; ?></span></td>
                <td><strong><?php echo htmlspecialchars($s['area_zone']); ?></strong></td>
                <td><?php echo htmlspecialchars($s['purok'] ?? 'All'); ?></td>
                <td><span class="db-badge <?php echo $tb; ?>"><i class="fas <?php echo $ti; ?> me-1"></i><?php echo ucfirst($s['waste_type']); ?></span></td>
                <td>
                    <span style="font-weight:700;color:<?php echo $dc; ?>;">
                        <i class="fas fa-circle me-1" style="font-size:7px;vertical-align:middle;"></i>
                        <?php echo htmlspecialchars($s['collection_day']); ?>
                    </span>
                </td>
                <td>
                    <span style="font-family:'DM Mono',monospace;font-size:12px;">
                        <?php echo date('g:i A', strtotime($s['collection_time'])); ?>
                    </span>
                </td>
                <td><?php echo htmlspecialchars($s['collector_name'] ?? '—'); ?></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($s['truck_number'] ?? '—'); ?></span></td>
                <td><span class="db-badge <?php echo $sb; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                <td>
                    <div class="db-btn-group">
                        <button class="db-icon-btn db-icon-btn--info" onclick='viewSchedule(<?php echo htmlspecialchars(json_encode($s),ENT_QUOTES); ?>)' title="View"><i class="fas fa-eye"></i></button>
                        <button class="db-icon-btn db-icon-btn--primary" onclick='editSchedule(<?php echo htmlspecialchars(json_encode($s),ENT_QUOTES); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="db-icon-btn db-icon-btn--danger" onclick="openDeleteModal(<?php echo (int)$s['schedule_id']; ?>,'<?php echo htmlspecialchars($s['area_zone'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['collection_day'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['collection_time'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['waste_type'],ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>

    <!-- CARD VIEW (hidden by default) -->
    <div id="cardView" style="display:none;padding:18px 22px;">
        <?php foreach ($by_day as $day => $day_schedules):
            $dc = $day_colors[$day] ?? 'var(--db-muted)';
        ?>
        <div style="margin-bottom:20px;">
            <div style="font-size:13px;font-weight:700;color:<?php echo $dc; ?>;display:flex;align-items:center;gap:8px;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid <?php echo $dc; ?>20;">
                <i class="fas fa-circle" style="font-size:8px;"></i>
                <?php echo $day; ?>
                <span style="font-family:'DM Mono',monospace;font-size:10px;color:var(--db-muted);font-weight:400;">(<?php echo count($day_schedules); ?> schedule<?php echo count($day_schedules)>1?'s':''; ?>)</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;">
            <?php foreach ($day_schedules as $s):
                $tb = $type_badges[$s['waste_type']] ?? 'db-badge--muted';
                $sb = $status_badges[$s['status']]   ?? 'db-badge--muted';
                $ti = $type_icons[$s['waste_type']]  ?? 'fa-trash-alt';
            ?>
            <div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:14px 16px;border-left:4px solid <?php echo $dc; ?>;transition:box-shadow .2s,transform .2s;" onmouseover="this.style.boxShadow='var(--db-shadow)';this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='';this.style.transform='';">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">
                    <div>
                        <div style="font-weight:700;font-size:14px;"><?php echo htmlspecialchars($s['area_zone']); ?></div>
                        <?php if ($s['purok']): ?>
                        <div style="font-size:11px;color:var(--db-muted);">Purok: <?php echo htmlspecialchars($s['purok']); ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="db-badge <?php echo $sb; ?>" style="font-size:9px;"><?php echo ucfirst($s['status']); ?></span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                    <span class="db-badge <?php echo $tb; ?>"><i class="fas <?php echo $ti; ?> me-1"></i><?php echo ucfirst($s['waste_type']); ?></span>
                    <span style="font-family:'DM Mono',monospace;font-size:10.5px;color:var(--db-text);background:var(--db-surf);border:1px solid var(--db-border);padding:2px 8px;border-radius:20px;">
                        <i class="fas fa-clock me-1" style="color:var(--db-muted);font-size:9px;"></i><?php echo date('g:i A', strtotime($s['collection_time'])); ?>
                    </span>
                </div>
                <?php if ($s['collector_name']): ?>
                <div style="font-size:11.5px;color:var(--db-muted);margin-bottom:4px;"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($s['collector_name']); ?><?php if ($s['truck_number']): ?> · <i class="fas fa-truck ms-1 me-1"></i><?php echo htmlspecialchars($s['truck_number']); ?><?php endif; ?></div>
                <?php endif; ?>
                <div style="display:flex;gap:4px;margin-top:10px;">
                    <button class="db-icon-btn db-icon-btn--info" onclick='viewSchedule(<?php echo htmlspecialchars(json_encode($s),ENT_QUOTES); ?>)' title="View"><i class="fas fa-eye"></i></button>
                    <button class="db-icon-btn db-icon-btn--primary" onclick='editSchedule(<?php echo htmlspecialchars(json_encode($s),ENT_QUOTES); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                    <button class="db-icon-btn db-icon-btn--danger" onclick="openDeleteModal(<?php echo (int)$s['schedule_id']; ?>,'<?php echo htmlspecialchars($s['area_zone'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['collection_day'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['collection_time'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['waste_type'],ENT_QUOTES); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="db-empty">
        <i class="fas fa-calendar-alt"></i>
        <p>No collection schedules found. Add your first schedule to get started.</p>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addScheduleModal')"><i class="fas fa-plus"></i> Add Schedule</button>
    </div>
    <?php endif; ?>
</div>


<!-- ═══ ADD SCHEDULE MODAL ═══ -->
<div id="addScheduleModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-plus-circle"></i> Add Collection Schedule</h3>
            <button class="db-modal__close" onclick="closeModal('addScheduleModal')">×</button>
        </div>
        <form method="POST" action="schedules.php" class="db-modal__body">
            <input type="hidden" name="action" value="add">
            <div class="db-field-row">
                <div class="db-field"><label>Area / Zone <span class="req">*</span></label><input type="text" class="db-input" name="area_zone" required></div>
                <div class="db-field"><label>Purok</label><input type="text" class="db-input" name="purok" placeholder="Leave blank for all"></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Waste Type <span class="req">*</span></label>
                    <select class="db-input" name="waste_type" required>
                        <option value="">Select Type</option>
                        <?php foreach(['biodegradable','non-biodegradable','recyclable','hazardous','mixed'] as $wt): ?>
                        <option value="<?php echo $wt; ?>"><?php echo ucfirst($wt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="db-field"><label>Collection Day <span class="req">*</span></label>
                    <select class="db-input" name="collection_day" required>
                        <option value="">Select Day</option>
                        <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                        <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Collection Time <span class="req">*</span></label><input type="time" class="db-input" name="collection_time" required></div>
                <div class="db-field"><label>Collector Name</label><input type="text" class="db-input" name="collector_name"></div>
            </div>
            <div class="db-field"><label>Truck Number</label><input type="text" class="db-input" name="truck_number"></div>
            <div class="db-field"><label>Notes</label><textarea class="db-input" name="notes" rows="3"></textarea></div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Add Schedule</button>
        </form>
    </div>
</div>

<!-- ═══ EDIT SCHEDULE MODAL ═══ -->
<div id="editScheduleModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Schedule</h3>
            <button class="db-modal__close" onclick="closeModal('editScheduleModal')">×</button>
        </div>
        <form method="POST" action="schedules.php" class="db-modal__body">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="schedule_id" id="edit_schedule_id">
            <div class="db-field-row">
                <div class="db-field"><label>Area / Zone <span class="req">*</span></label><input type="text" class="db-input" id="edit_area_zone" name="area_zone" required></div>
                <div class="db-field"><label>Purok</label><input type="text" class="db-input" id="edit_purok" name="purok"></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Waste Type <span class="req">*</span></label>
                    <select class="db-input" id="edit_waste_type" name="waste_type" required>
                        <?php foreach(['biodegradable','non-biodegradable','recyclable','hazardous','mixed'] as $wt): ?>
                        <option value="<?php echo $wt; ?>"><?php echo ucfirst($wt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="db-field"><label>Collection Day <span class="req">*</span></label>
                    <select class="db-input" id="edit_collection_day" name="collection_day" required>
                        <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                        <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Collection Time <span class="req">*</span></label><input type="time" class="db-input" id="edit_collection_time" name="collection_time" required></div>
                <div class="db-field"><label>Collector Name</label><input type="text" class="db-input" id="edit_collector_name" name="collector_name"></div>
            </div>
            <div class="db-field-row">
                <div class="db-field"><label>Truck Number</label><input type="text" class="db-input" id="edit_truck_number" name="truck_number"></div>
                <div class="db-field"><label>Status <span class="req">*</span></label>
                    <select class="db-input" id="edit_status" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            <div class="db-field"><label>Notes</label><textarea class="db-input" id="edit_notes" name="notes" rows="3"></textarea></div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Schedule</button>
        </form>
    </div>
</div>

<!-- ═══ VIEW SCHEDULE MODAL ═══ -->
<div id="viewScheduleModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-calendar-check"></i> Schedule Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewScheduleModal')">×</button>
        </div>
        <div class="db-modal__body" id="viewScheduleContent"></div>
    </div>
</div>

<!-- ═══ DELETE CONFIRM MODAL ═══ -->
<div id="deleteScheduleModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteScheduleModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete this schedule?</p>
            <div class="db-delete-target" id="delete_schedule_label"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST" action="schedules.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="schedule_id" id="delete_schedule_id">
                <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteScheduleModal')">Cancel</button>
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

let isCardView = false;
function toggleView() {
    isCardView = !isCardView;
    document.getElementById('tableView').style.display = isCardView ? 'none' : 'block';
    document.getElementById('cardView').style.display  = isCardView ? 'block' : 'none';
    document.getElementById('viewToggleBtn').innerHTML = isCardView
        ? '<i class="fas fa-table"></i> Table View'
        : '<i class="fas fa-th-large"></i> Card View';
}

function formatTime(t) {
    if (!t) return 'N/A';
    const [h,m] = t.split(':'); const hr=parseInt(h); return `${hr%12||12}:${m} ${hr>=12?'PM':'AM'}`;
}

const typeBadges   = {biodegradable:'db-badge--success','non-biodegradable':'db-badge--danger',recyclable:'db-badge--info',hazardous:'db-badge--warning',mixed:'db-badge--muted'};
const statusBadges = {active:'db-badge--success',inactive:'db-badge--muted',suspended:'db-badge--danger'};

function viewSchedule(s) {
    const tb = typeBadges[s.waste_type]   || 'db-badge--muted';
    const sb = statusBadges[s.status]     || 'db-badge--muted';
    document.getElementById('viewScheduleContent').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Schedule ID</div><span class="db-id">#${s.schedule_id}</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Status</div><span class="db-badge ${sb}">${s.status.toUpperCase()}</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Area / Zone</div><strong>${s.area_zone}</strong></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Purok</div>${s.purok||'All'}</div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Waste Type</div><span class="db-badge ${tb}">${s.waste_type.charAt(0).toUpperCase()+s.waste_type.slice(1)}</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Day & Time</div><strong>${s.collection_day}</strong> at <span style="font-family:'DM Mono',monospace;">${formatTime(s.collection_time)}</span></div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Collector</div>${s.collector_name||'Not Assigned'}</div>
            <div><div style="font-size:10.5px;color:var(--db-muted);font-weight:600;text-transform:uppercase;margin-bottom:3px;">Truck</div>${s.truck_number||'Not Assigned'}</div>
        </div>
        ${s.notes ? `<div style="background:var(--db-surf2);border-left:3px solid var(--db-indigo);padding:10px 14px;border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;font-size:13px;color:var(--db-muted);margin-bottom:12px;">${s.notes}</div>` : ''}
        <button class="db-btn db-btn--ghost db-btn--sm" onclick="closeModal('viewScheduleModal')">Close</button>
    `;
    openModal('viewScheduleModal');
}

function editSchedule(s) {
    document.getElementById('edit_schedule_id').value     = s.schedule_id;
    document.getElementById('edit_area_zone').value       = s.area_zone;
    document.getElementById('edit_purok').value           = s.purok || '';
    document.getElementById('edit_waste_type').value      = s.waste_type;
    document.getElementById('edit_collection_day').value  = s.collection_day;
    document.getElementById('edit_collection_time').value = s.collection_time;
    document.getElementById('edit_collector_name').value  = s.collector_name || '';
    document.getElementById('edit_truck_number').value    = s.truck_number || '';
    document.getElementById('edit_status').value          = s.status;
    document.getElementById('edit_notes').value           = s.notes || '';
    openModal('editScheduleModal');
}

function openDeleteModal(id, area, day, time, waste) {
    document.getElementById('delete_schedule_id').value = id;
    document.getElementById('delete_schedule_label').textContent = `${area} — ${day} at ${formatTime(time)} (${waste})`;
    openModal('deleteScheduleModal');
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php require_once '../../../includes/footer.php'; ?>