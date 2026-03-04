<?php
/**
 * Resident Disaster Dashboard
 * Path: barangaylink/disasters/resident/index.php
 */

require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit();
}

$user_role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
if ($user_role !== 'Resident') {
    setMessage('Access Denied: This page is for residents only.', 'error');
}

$resident_id     = $_SESSION['resident_id'] ?? null;
$current_user_id = getCurrentUserId();

$user_data = null;
if ($resident_id) {
    $stmt = $conn->prepare("SELECT r.is_verified, r.first_name, r.last_name, r.id_photo FROM tbl_residents r WHERE r.resident_id = ?");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $result    = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $stmt->close();
}

if (!$user_data || $user_data['is_verified'] != 1) {
    header("Location: not-verified.php");
    exit();
}

$page_title = 'Disaster Information';

$disaster_columns  = [];
$active_disasters  = [];
$my_damage_reports = [];
$my_relief         = [];
$evacuation_centers= [];
$my_evacuee_status = null;

if (tableExists($conn, 'tbl_disaster_incidents')) {
    $result = $conn->query("SHOW COLUMNS FROM tbl_disaster_incidents");
    while ($row = $result->fetch_assoc()) $disaster_columns[] = $row['Field'];
}

if (tableExists($conn, 'tbl_disaster_incidents')) {
    $name_col = in_array('disaster_name', $disaster_columns) ? 'disaster_name' :
                (in_array('name', $disaster_columns) ? 'name' :
                (in_array('incident_name', $disaster_columns) ? 'incident_name' : 'disaster_type'));
    $active_disasters = fetchAll($conn, "SELECT *, {$name_col} as disaster_name FROM tbl_disaster_incidents WHERE status IN ('Active','Ongoing') ORDER BY incident_date DESC LIMIT 5");
}

if ($resident_id && tableExists($conn, 'tbl_damage_assessments')) {
    $my_damage_reports = fetchAll($conn, "SELECT da.* FROM tbl_damage_assessments da WHERE da.resident_id = ? ORDER BY da.assessment_date DESC", [$resident_id], 'i');
}

if ($resident_id && tableExists($conn, 'tbl_relief_distributions')) {
    $relief_columns = [];
    $result = $conn->query("SHOW COLUMNS FROM tbl_relief_distributions");
    while ($row = $result->fetch_assoc()) $relief_columns[] = $row['Field'];
    $has_ec = tableExists($conn, 'tbl_evacuation_centers');
    $relief_sql = "SELECT rd.*";
    if (in_array('disaster_id', $relief_columns) && !empty($disaster_columns)) {
        $nc = in_array('disaster_name', $disaster_columns) ? 'disaster_name' : (in_array('name', $disaster_columns) ? 'name' : 'disaster_type');
        $relief_sql .= ", di.{$nc} as disaster_name, di.disaster_type";
    }
    if (in_array('center_id', $relief_columns) && $has_ec) $relief_sql .= ", ec.center_name, ec.location as center_location";
    $relief_sql .= " FROM tbl_relief_distributions rd";
    if (in_array('disaster_id', $relief_columns) && !empty($disaster_columns)) $relief_sql .= " LEFT JOIN tbl_disaster_incidents di ON rd.disaster_id = di.incident_id";
    if (in_array('center_id', $relief_columns) && $has_ec) $relief_sql .= " LEFT JOIN tbl_evacuation_centers ec ON rd.center_id = ec.center_id";
    $relief_sql .= " WHERE rd.resident_id = ? ORDER BY rd.distribution_date DESC";
    $my_relief = fetchAll($conn, $relief_sql, [$resident_id], 'i');
}

if (tableExists($conn, 'tbl_evacuation_centers')) {
    $evacuation_centers = fetchAll($conn, "
        SELECT ec.*,
        COALESCE(SUM(CASE WHEN e.status='Active' THEN e.family_members ELSE 0 END),0) as current_evacuees,
        COUNT(CASE WHEN e.status='Active' THEN 1 END) as active_families
        FROM tbl_evacuation_centers ec
        LEFT JOIN tbl_evacuees e ON ec.center_id = e.center_id
        WHERE ec.status IN ('Active','Full')
        GROUP BY ec.center_id ORDER BY ec.center_name
    ");
}

if ($resident_id && tableExists($conn, 'tbl_evacuee_registrations')) {
    $evacuee_sql = "SELECT er.*, ec.center_name FROM tbl_evacuee_registrations er LEFT JOIN tbl_evacuation_centers ec ON er.center_id = ec.center_id WHERE er.resident_id = ? AND er.status = 'Active' ORDER BY er.registration_date DESC LIMIT 1";
    if (!empty($disaster_columns)) {
        $nc = in_array('disaster_name', $disaster_columns) ? 'disaster_name' : (in_array('name', $disaster_columns) ? 'name' : 'disaster_type');
        $evacuee_sql = "SELECT er.*, ec.center_name, di.{$nc} as disaster_name FROM tbl_evacuee_registrations er LEFT JOIN tbl_evacuation_centers ec ON er.center_id = ec.center_id LEFT JOIN tbl_disaster_incidents di ON er.disaster_id = di.incident_id WHERE er.resident_id = ? AND er.status = 'Active' ORDER BY er.registration_date DESC LIMIT 1";
    }
    $my_evacuee_status = fetchOne($conn, $evacuee_sql, [$resident_id], 'i');
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/waste-pages.css?v=' . time() . '">
<style>
/* ── Disaster-page extras ── */
.dis-evacuee-banner{background:linear-gradient(135deg,#b45309,var(--db-amber));border-radius:var(--db-radius);padding:16px 20px;display:flex;align-items:center;gap:14px;margin-bottom:18px;box-shadow:var(--db-shadow-lg)}
.dis-evacuee-banner i{font-size:24px;color:#fff;flex-shrink:0}
.dis-evacuee-banner__text{flex:1}
.dis-evacuee-banner__title{font-size:14px;font-weight:700;color:#fff;margin-bottom:2px}
.dis-evacuee-banner__sub{font-size:12.5px;color:rgba(255,255,255,.8)}

.dis-quicklink{flex:1 1 180px;background:var(--db-surf);border-radius:var(--db-radius);border:1px solid var(--db-border);box-shadow:var(--db-shadow);padding:22px 18px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:10px;transition:transform .2s,box-shadow .2s;text-decoration:none;color:var(--db-text)}
.dis-quicklink:hover{transform:translateY(-4px);box-shadow:var(--db-shadow-lg);color:var(--db-text);text-decoration:none}
.dis-quicklink__icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.dis-quicklink__title{font-size:14px;font-weight:700}
.dis-quicklink__desc{font-size:12px;color:var(--db-muted);line-height:1.5}

.ec-card{background:var(--db-surf);border:1px solid var(--db-border);border-radius:var(--db-radius);padding:16px;margin-bottom:12px;transition:box-shadow .2s}
.ec-card:hover{box-shadow:var(--db-shadow)}
.ec-card--full{background:var(--db-surf2)}
.ec-progress{height:8px;border-radius:4px;background:var(--db-border);overflow:hidden;margin:6px 0}
.ec-progress__bar{height:100%;border-radius:4px;transition:width .4s}
.ec-progress__bar--success{background:var(--db-success)}
.ec-progress__bar--warning{background:var(--db-warning)}
.ec-progress__bar--danger {background:var(--db-danger)}

.contact-card{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px;transition:transform .15s}
.contact-card:hover{transform:translateX(3px)}
.contact-card__icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
.contact-card__label{font-size:11px;color:var(--db-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.contact-card__num{font-family:"DM Mono",monospace;font-size:14px;font-weight:600;color:var(--db-text)}
</style>';

include __DIR__ . '/../../includes/header.php';

// badge helpers
function severityBadge($s){
    $m=['Low'=>['wp-badge--success','fa-circle'],'Medium'=>['wp-badge--warning','fa-exclamation-circle'],'High'=>['wp-badge--danger','fa-exclamation-triangle'],'Critical'=>['wp-badge--dark','fa-skull-crossbones']];
    [$cls,$ico]=$m[$s]??['wp-badge--muted','fa-circle'];
    return "<span class='wp-badge $cls'><i class='fas $ico'></i> $s</span>";
}
function statusBadge($s){
    $m=['Active'=>['wp-badge--success','fa-check-circle'],'Ongoing'=>['wp-badge--info','fa-spinner'],'Pending'=>['wp-badge--warning','fa-clock'],'Resolved'=>['wp-badge--muted','fa-archive'],'Closed'=>['wp-badge--muted','fa-archive'],'Distributed'=>['wp-badge--success','fa-check']];
    [$cls,$ico]=$m[$s]??['wp-badge--muted','fa-circle'];
    return "<span class='wp-badge $cls'><i class='fas $ico'></i> $s</span>";
}
?>

<!-- ── PAGE HERO ── -->
<div class="wp-hero">
    <div class="wp-hero__ring wp-hero__ring--1"></div>
    <div class="wp-hero__ring wp-hero__ring--2"></div>
    <div class="wp-hero__ring wp-hero__ring--3"></div>
    <div class="wp-hero__inner">
        <div class="wp-hero__left">
            <div class="wp-hero__icon wp-hero__icon--rose">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <h1 class="wp-hero__title">Disaster Information &amp; Assistance</h1>
                <p class="wp-hero__sub">View active disasters, report damage, and check relief status</p>
            </div>
        </div>
        <div class="wp-hero__actions">
            <span class="wp-badge wp-badge--danger" style="padding:7px 14px;font-size:12px;animation:dbPulse 2s infinite">
                <i class="fas fa-circle"></i> <?php echo count($active_disasters); ?> Active Disaster<?php echo count($active_disasters) !== 1 ? 's' : ''; ?>
            </span>
        </div>
    </div>
</div>

<?php echo displayMessage(); ?>

<!-- ── EVACUEE BANNER ── -->
<?php if ($my_evacuee_status): ?>
<div class="dis-evacuee-banner">
    <i class="fas fa-map-marker-alt"></i>
    <div class="dis-evacuee-banner__text">
        <div class="dis-evacuee-banner__title">You are currently registered as an evacuee</div>
        <div class="dis-evacuee-banner__sub">
            Currently at <strong><?php echo htmlspecialchars($my_evacuee_status['center_name']); ?></strong>
            <?php if (!empty($my_evacuee_status['disaster_name'])): ?>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($my_evacuee_status['disaster_name']); ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── STAT CARDS ── -->
<div class="wp-stats-row">
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--rose"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo count($active_disasters); ?></div>
            <div class="wp-stat-card__label">Active Disasters</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--rose"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--amber"><i class="fas fa-house-damage"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo count($my_damage_reports); ?></div>
            <div class="wp-stat-card__label">Damage Reports</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--amber"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--success"><i class="fas fa-hands-helping"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo count($my_relief); ?></div>
            <div class="wp-stat-card__label">Relief Received</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--success"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--blue"><i class="fas fa-home"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo count($evacuation_centers); ?></div>
            <div class="wp-stat-card__label">Evacuation Centers</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--blue"></div>
    </div>
</div>

<!-- ── QUICK ACTIONS ── -->
<div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px">
    <button class="dis-quicklink" onclick="openDamageModal()" style="background:var(--db-surf);cursor:pointer;border:1px solid var(--db-border)">
        <div class="dis-quicklink__icon" style="background:var(--db-rose-light);color:var(--db-rose)"><i class="fas fa-house-damage"></i></div>
        <div class="dis-quicklink__title">Report Damage</div>
        <div class="dis-quicklink__desc">Report property damage caused by disasters</div>
        <span class="wp-btn wp-btn--danger wp-btn--sm" style="margin-top:4px"><i class="fas fa-plus"></i> Report Now</span>
    </button>
    <a href="#relief-section" class="dis-quicklink">
        <div class="dis-quicklink__icon" style="background:var(--db-indigo-light);color:var(--db-indigo)"><i class="fas fa-hands-helping"></i></div>
        <div class="dis-quicklink__title">Relief Status</div>
        <div class="dis-quicklink__desc">Check your relief assistance status</div>
        <span class="wp-btn wp-btn--primary wp-btn--sm" style="margin-top:4px"><i class="fas fa-eye"></i> View Status</span>
    </a>
    <a href="#evacuation-section" class="dis-quicklink">
        <div class="dis-quicklink__icon" style="background:var(--db-success-light);color:var(--db-success)"><i class="fas fa-home"></i></div>
        <div class="dis-quicklink__title">Evacuation Centers</div>
        <div class="dis-quicklink__desc">View available evacuation centers near you</div>
        <span class="wp-btn wp-btn--success wp-btn--sm" style="margin-top:4px"><i class="fas fa-map-marker-alt"></i> View Centers</span>
    </a>
    <a href="tel:<?php echo BARANGAY_CONTACT; ?>" class="dis-quicklink">
        <div class="dis-quicklink__icon" style="background:var(--db-amber-light);color:var(--db-amber-dark)"><i class="fas fa-phone-alt"></i></div>
        <div class="dis-quicklink__title">Emergency Hotline</div>
        <div class="dis-quicklink__desc">Contact the barangay for emergencies</div>
        <span class="wp-btn wp-btn--amber wp-btn--sm" style="margin-top:4px;background:var(--db-amber);color:#fff"><i class="fas fa-phone"></i> <?php echo BARANGAY_CONTACT; ?></span>
    </a>
</div>

<!-- ── MAIN GRID ── -->
<div class="wp-grid">

    <!-- LEFT COLUMN -->
    <div>

        <!-- Active Disasters -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--rose"><i class="fas fa-exclamation-triangle"></i></span>
                    <h2>Active Disasters</h2>
                </div>
                <?php if (!empty($active_disasters)): ?>
                <span class="wp-badge wp-badge--danger"><?php echo count($active_disasters); ?> active</span>
                <?php endif; ?>
            </div>
            <?php if (empty($active_disasters)): ?>
            <div class="wp-empty">
                <i class="fas fa-check-circle" style="color:var(--db-success)"></i>
                <p>No active disasters at the moment — all clear!</p>
            </div>
            <?php else: ?>
            <div class="wp-table-wrap">
                <table class="wp-table">
                    <thead>
                        <tr><th>Date</th><th>Disaster</th><th>Type</th><th>Severity</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($active_disasters as $d): ?>
                    <tr>
                        <td><span class="wp-text-sm"><?php echo formatDate($d['incident_date']); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($d['disaster_name']); ?></strong></td>
                        <td><span class="wp-badge wp-badge--muted"><?php echo htmlspecialchars($d['disaster_type']); ?></span></td>
                        <td><?php echo severityBadge($d['severity']); ?></td>
                        <td><?php echo statusBadge($d['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Damage Reports -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--amber"><i class="fas fa-clipboard-list"></i></span>
                    <h2>My Damage Reports</h2>
                </div>
                <button class="wp-btn wp-btn--primary wp-btn--sm" onclick="openDamageModal()">
                    <i class="fas fa-plus"></i> New Report
                </button>
            </div>
            <?php if (empty($my_damage_reports)): ?>
            <div class="wp-empty">
                <i class="fas fa-file-alt"></i>
                <p>No damage reports submitted yet.</p>
                <button class="wp-btn wp-btn--danger wp-btn--sm" onclick="openDamageModal()" style="margin-top:4px">
                    <i class="fas fa-plus"></i> Submit Report
                </button>
            </div>
            <?php else: ?>
            <div class="wp-table-wrap">
                <table class="wp-table">
                    <thead>
                        <tr><th>Date</th><th>Disaster Type</th><th>Damage Type</th><th>Severity</th><th>Est. Cost</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($my_damage_reports as $r): ?>
                    <tr>
                        <td><span class="wp-text-sm"><?php echo formatDate($r['assessment_date']); ?></span></td>
                        <td><?php echo htmlspecialchars($r['disaster_type']); ?></td>
                        <td><?php echo htmlspecialchars($r['damage_type']); ?></td>
                        <td><?php echo severityBadge($r['severity']); ?></td>
                        <td><span class="wp-mono" style="font-size:12px">₱<?php echo number_format($r['estimated_cost'], 2); ?></span></td>
                        <td><?php echo statusBadge($r['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Relief Distributions -->
        <div class="wp-panel" id="relief-section">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--success"><i class="fas fa-hands-helping"></i></span>
                    <h2>My Relief Assistance</h2>
                </div>
                <?php if (!empty($my_relief)): ?>
                <span class="wp-badge wp-badge--success"><?php echo count($my_relief); ?> record<?php echo count($my_relief) > 1 ? 's' : ''; ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($my_relief)): ?>
            <div class="wp-empty">
                <i class="fas fa-box-open"></i>
                <p>No relief assistance received yet.</p>
            </div>
            <?php else: ?>
            <div class="wp-table-wrap">
                <table class="wp-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <?php if (!empty($my_relief[0]['disaster_name'])): ?><th>Disaster</th><?php endif; ?>
                            <?php if (!empty($my_relief[0]['center_name'])): ?><th>Center</th><?php endif; ?>
                            <th>Items Received</th>
                            <th>Qty</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($my_relief as $rel): ?>
                    <tr>
                        <td><span class="wp-text-sm"><?php echo formatDate($rel['distribution_date']); ?></span></td>
                        <?php if (!empty($rel['disaster_name'])): ?>
                        <td><?php echo htmlspecialchars($rel['disaster_name']); ?></td>
                        <?php endif; ?>
                        <?php if (!empty($rel['center_name'])): ?>
                        <td>
                            <strong><?php echo htmlspecialchars($rel['center_name']); ?></strong>
                            <?php if (!empty($rel['center_location'])): ?>
                            <br><span class="wp-text-sm"><?php echo htmlspecialchars($rel['center_location']); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($rel['items_distributed']); ?></td>
                        <td><span class="wp-id"><?php echo htmlspecialchars($rel['quantity']); ?></span></td>
                        <td><?php echo statusBadge($rel['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /left -->

    <!-- RIGHT SIDEBAR -->
    <div id="evacuation-section">

        <!-- Evacuation Centers -->
        <div class="wp-panel" style="margin-bottom:18px">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--blue"><i class="fas fa-home"></i></span>
                    <h2>Evacuation Centers</h2>
                </div>
                <?php if (!empty($evacuation_centers)): ?>
                <span class="wp-badge wp-badge--primary"><?php echo count($evacuation_centers); ?> open</span>
                <?php endif; ?>
            </div>

            <?php if (empty($evacuation_centers)): ?>
            <div class="wp-empty" style="padding:32px 16px">
                <i class="fas fa-home"></i>
                <p>No active evacuation centers at the moment.</p>
            </div>
            <?php else: ?>
            <div style="padding:16px">
            <?php foreach ($evacuation_centers as $c):
                $occ      = $c['capacity'] > 0 ? ($c['current_evacuees'] / $c['capacity']) * 100 : 0;
                $avail    = $c['capacity'] - $c['current_evacuees'];
                $occ_cls  = $occ >= 90 ? 'danger' : ($occ >= 70 ? 'warning' : 'success');
                $is_full  = $avail <= 0 || $c['status'] === 'Full';
            ?>
            <div class="ec-card <?php echo $is_full ? 'ec-card--full' : ''; ?>">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px">
                    <div style="font-size:14px;font-weight:700"><?php echo htmlspecialchars($c['center_name']); ?></div>
                    <?php if ($is_full): ?>
                    <span class="wp-badge wp-badge--danger">FULL</span>
                    <?php else: ?>
                    <span class="wp-badge wp-badge--success">Available</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px;color:var(--db-muted);margin-bottom:10px">
                    <i class="fas fa-map-marker-alt" style="margin-right:4px;color:var(--db-rose)"></i>
                    <?php echo htmlspecialchars($c['location']); ?>
                </div>
                <!-- Occupancy bar -->
                <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--db-muted);margin-bottom:4px">
                    <span>Occupancy</span>
                    <span class="wp-mono"><?php echo $c['current_evacuees']; ?>/<?php echo $c['capacity']; ?></span>
                </div>
                <div class="ec-progress">
                    <div class="ec-progress__bar ec-progress__bar--<?php echo $occ_cls; ?>"
                         style="width:<?php echo min($occ, 100); ?>%"></div>
                </div>
                <div style="display:flex;gap:14px;font-size:12px;margin-top:8px">
                    <span><i class="fas fa-users" style="color:var(--db-indigo);margin-right:4px"></i><strong><?php echo $c['active_families']; ?></strong> families</span>
                    <span><i class="fas fa-chair" style="color:var(--db-<?php echo $is_full ? 'danger' : 'success'; ?>);margin-right:4px"></i><strong><?php echo $avail; ?></strong> spaces left</span>
                </div>
                <?php if (!empty($c['contact_person'])): ?>
                <div style="font-size:11.5px;color:var(--db-muted);margin-top:8px;padding-top:8px;border-top:1px solid var(--db-border)">
                    <i class="fas fa-phone" style="margin-right:4px"></i>
                    <?php echo htmlspecialchars($c['contact_person']); ?>
                    <?php if (!empty($c['contact_number'])): ?> · <?php echo htmlspecialchars($c['contact_number']); ?><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Emergency Contacts -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--rose"><i class="fas fa-phone-alt"></i></span>
                    <h2>Emergency Contacts</h2>
                </div>
            </div>
            <div style="padding:16px;display:flex;flex-direction:column;gap:6px">
                <?php
                $contacts = [
                    ['Barangay Hall',  BARANGAY_CONTACT, 'var(--db-amber)',   'fa-landmark'],
                    ['NDRRMC Hotline', '911',            'var(--db-rose)',    'fa-shield-alt'],
                    ['Local Police',   '117',            'var(--db-navy)',    'fa-user-shield'],
                    ['Fire Department','160',            'var(--db-danger)',  'fa-fire-extinguisher'],
                ];
                foreach ($contacts as [$label, $num, $color, $ico]):
                ?>
                <a href="tel:<?php echo $num; ?>" class="contact-card" style="text-decoration:none">
                    <div class="contact-card__icon" style="background:<?php echo $color; ?>1a;color:<?php echo $color; ?>">
                        <i class="fas <?php echo $ico; ?>"></i>
                    </div>
                    <div>
                        <div class="contact-card__label"><?php echo $label; ?></div>
                        <div class="contact-card__num"><?php echo $num; ?></div>
                    </div>
                    <i class="fas fa-chevron-right" style="margin-left:auto;color:var(--db-border);font-size:11px"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /sidebar -->
</div><!-- /wp-grid -->


<!-- ══════════════════════════════════════
     REPORT DAMAGE MODAL
══════════════════════════════════════ -->
<div id="reportDamageModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-house-damage"></i> Report Property Damage</h3>
            <button class="db-modal__close" onclick="closeDamageModal()">×</button>
        </div>
        <form method="POST" action="submit-damage-report.php">
            <div class="db-modal__body">
                <div style="background:var(--db-info-light);border-left:4px solid var(--db-info);border-radius:8px;padding:12px 14px;margin-bottom:18px;font-size:13px;color:#1e40af">
                    <i class="fas fa-info-circle" style="margin-right:6px"></i>
                    Please provide accurate information about the damage to your property.
                </div>

                <div class="db-field-row">
                    <div class="db-field">
                        <label>Disaster Type <span class="req">*</span></label>
                        <select name="disaster_type" class="db-input" required>
                            <option value="">Select Type</option>
                            <?php foreach(['Typhoon','Flood','Earthquake','Fire','Landslide','Other'] as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="db-field">
                        <label>Date of Damage <span class="req">*</span></label>
                        <input type="date" name="assessment_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="db-field-row">
                    <div class="db-field">
                        <label>Specific Location <span class="req">*</span></label>
                        <input type="text" name="location" class="db-input" placeholder="e.g., Purok 1, Street name" required>
                    </div>
                    <div class="db-field">
                        <label>Damage Type <span class="req">*</span></label>
                        <select name="damage_type" class="db-input" required>
                            <option value="">Select Damage Type</option>
                            <option value="Structural">Structural (Walls, Roof)</option>
                            <option value="Partial">Partial Damage</option>
                            <option value="Total">Total Loss</option>
                            <option value="Infrastructure">Infrastructure</option>
                        </select>
                    </div>
                </div>

                <div class="db-field-row">
                    <div class="db-field">
                        <label>Severity <span class="req">*</span></label>
                        <select name="severity" class="db-input" required>
                            <option value="">Select Severity</option>
                            <option value="Low">Low — Minor repairs needed</option>
                            <option value="Medium">Medium — Significant repairs</option>
                            <option value="High">High — Major reconstruction</option>
                            <option value="Critical">Critical — Uninhabitable</option>
                        </select>
                    </div>
                    <div class="db-field">
                        <label>Estimated Cost (₱)</label>
                        <input type="number" name="estimated_cost" class="db-input" step="0.01" min="0" placeholder="Optional">
                    </div>
                </div>

                <div class="db-field">
                    <label>Description of Damage <span class="req">*</span></label>
                    <textarea name="description" class="db-input" rows="4" placeholder="Please describe the damage in detail…" required></textarea>
                </div>

                <div style="display:flex;gap:10px;margin-top:4px">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeDamageModal()">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-paper-plane"></i> Submit Report</button>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
/* reuse db-modal classes from dashboard-index.css for the modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px}
.db-modal--open{display:flex}
.db-modal__box{background:#fff;border-radius:20px;width:100%;max-width:600px;max-height:92vh;overflow-y:auto;box-shadow:0 8px 40px rgba(13,27,54,.14);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1)}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));border-radius:20px 20px 0 0}
.db-modal__header--danger{background:linear-gradient(135deg,#7f1d1d,var(--db-danger))}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0}
.db-modal__close{background:rgba(255,255,255,.12);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s}
.db-modal__close:hover{background:rgba(255,255,255,.22)}
.db-modal__body{padding:22px}
.db-field{margin-bottom:14px}
.db-field label{display:block;font-size:12.5px;font-weight:600;margin-bottom:5px;color:#0f172a}
.db-field .req{color:#e11d48}
.db-field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.db-input{width:100%;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;color:#0f172a;background:#fff;outline:none;transition:all .18s;appearance:none}
.db-input:focus{border-color:#1c3461;box-shadow:0 0 0 3px rgba(28,52,97,.1)}
textarea.db-input{resize:vertical;min-height:90px}
select.db-input{cursor:pointer}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;justify-content:center}
.db-btn--full{width:100%}
.db-btn--ghost{background:#f8fafc;color:#0f172a;border-color:#e2e8f0}
.db-btn--ghost:hover{background:#e2e8f0}
.db-btn--danger{background:#ef4444;color:#fff}
.db-btn--danger:hover{background:#be1239}
@media(max-width:600px){.db-field-row{grid-template-columns:1fr}}
@keyframes dbPulse{0%,100%{opacity:1}50%{opacity:.6}}
</style>

<script>
function openDamageModal()  { document.getElementById('reportDamageModal').classList.add('db-modal--open');  document.body.style.overflow='hidden'; }
function closeDamageModal() { document.getElementById('reportDamageModal').classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.id === 'reportDamageModal') closeDamageModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDamageModal(); });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>