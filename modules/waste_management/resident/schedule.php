<?php
require_once '../../../config/config.php';
requireLogin();
$user_role = getCurrentUserRole();
$page_title = 'Waste Collection Schedule';

$user_id  = getCurrentUserId();
$user_info = fetchOne($conn,
    "SELECT r.address, r.purok FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id WHERE u.user_id = ?",
    [$user_id], 'i'
);

$zones       = fetchAll($conn, "SELECT DISTINCT area_zone FROM tbl_waste_schedules WHERE status = 'active' ORDER BY area_zone");
$filter_zone = $_GET['zone'] ?? '';

$where_clause = "WHERE status = 'active'";
$params = []; $types = '';
if (!empty($filter_zone)) {
    $where_clause .= " AND (area_zone = ? OR area_zone = 'All Zones')";
    $params[] = $filter_zone; $types .= 's';
}

$schedules = fetchAll($conn,
    "SELECT * FROM tbl_waste_schedules $where_clause
     ORDER BY FIELD(collection_day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), collection_time ASC",
    $params, $types
);

$schedule_by_day = [];
foreach ($schedules as $s) $schedule_by_day[$s['collection_day']][] = $s;

$extra_css = '<link rel="stylesheet" href="../../../assets/css/waste-pages.css?v=' . time() . '">';
include '../../../includes/header.php';

$waste_config = [
    'biodegradable'     => ['icon'=>'fa-leaf',           'color'=>'var(--db-success)', 'bg'=>'var(--db-success-light)', 'badge'=>'wp-badge--success', 'label'=>'Biodegradable Waste'],
    'non-biodegradable' => ['icon'=>'fa-trash',          'color'=>'var(--db-muted)',   'bg'=>'var(--db-surf2)',         'badge'=>'wp-badge--muted',   'label'=>'Non-Biodegradable Waste'],
    'recyclable'        => ['icon'=>'fa-recycle',        'color'=>'var(--db-sky)',     'bg'=>'var(--db-sky-light)',     'badge'=>'wp-badge--primary', 'label'=>'Recyclable Materials'],
    'hazardous'         => ['icon'=>'fa-skull-crossbones','color'=>'var(--db-rose)',   'bg'=>'var(--db-rose-light)',    'badge'=>'wp-badge--danger',  'label'=>'Hazardous Waste'],
    'mixed'             => ['icon'=>'fa-trash-alt',      'color'=>'var(--db-amber)',   'bg'=>'var(--db-amber-light)',   'badge'=>'wp-badge--warning', 'label'=>'Mixed Waste'],
];
?>

<!-- ── PAGE HERO ── -->
<div class="wp-hero">
    <div class="wp-hero__ring wp-hero__ring--1"></div>
    <div class="wp-hero__ring wp-hero__ring--2"></div>
    <div class="wp-hero__ring wp-hero__ring--3"></div>
    <div class="wp-hero__inner">
        <div class="wp-hero__left">
            <div class="wp-hero__icon wp-hero__icon--sky">
                <i class="fas fa-truck"></i>
            </div>
            <div>
                <h1 class="wp-hero__title">Waste Collection Schedule</h1>
                <p class="wp-hero__sub">View garbage collection days and times for your area</p>
            </div>
        </div>
        <div class="wp-hero__actions">
            <a href="report-issue.php" class="wp-btn wp-btn--rose" style="background:var(--db-rose);color:#fff">
                <i class="fas fa-exclamation-circle"></i> Report Issue
            </a>
        </div>
    </div>
</div>

<!-- ── ZONE FILTER ── -->
<div class="wp-filter" style="margin-bottom:24px">
    <div class="wp-filter__group">
        <span class="wp-filter__label">Filter by Zone</span>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <select name="zone" class="wp-input" onchange="this.form.submit()" style="min-width:200px">
                <option value="">All Zones</option>
                <?php foreach ($zones as $z): ?>
                <option value="<?php echo htmlspecialchars($z['area_zone']); ?>"
                    <?php echo $filter_zone === $z['area_zone'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($z['area_zone']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($filter_zone)): ?>
            <a href="?" class="wp-btn wp-btn--ghost wp-btn--sm"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if (!empty($filter_zone)): ?>
    <div style="display:flex;align-items:flex-end;padding-bottom:2px">
        <span class="wp-badge wp-badge--primary"><i class="fas fa-map-marker-alt"></i> Zone: <?php echo htmlspecialchars($filter_zone); ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- ── REMINDER PANEL ── -->
<div class="wp-panel" style="margin-bottom:24px">
    <div class="wp-panel__header">
        <div class="wp-panel__title">
            <span class="wp-panel__icon wp-panel__icon--amber"><i class="fas fa-bell"></i></span>
            <h2>Important Reminders</h2>
        </div>
    </div>
    <div class="wp-panel__body">
        <div style="display:flex;flex-wrap:wrap;gap:10px">
            <?php
            $reminders = [
                ['fa-check-circle','var(--db-success)', 'Segregate your waste: biodegradable and non-biodegradable'],
                ['fa-clock',       'var(--db-info)',    'Place bins outside before the scheduled collection time'],
                ['fa-trash',       'var(--db-amber-dark)','Use proper trash bags and ensure bins are covered'],
                ['fa-exclamation', 'var(--db-rose)',    'Do not mix different types of waste'],
                ['fa-skull',       'var(--db-muted)',   'Hazardous waste must only be disposed on special collection days'],
            ];
            foreach ($reminders as [$ico, $col, $txt]):
            ?>
            <div style="display:flex;align-items:center;gap:8px;padding:9px 14px;background:var(--db-surf2);border-radius:8px;font-size:12.5px;flex:1 1 260px;border:1px solid var(--db-border)">
                <i class="fas <?php echo $ico; ?>" style="color:<?php echo $col; ?>;width:16px;text-align:center;flex-shrink:0"></i>
                <span><?php echo $txt; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── SCHEDULE BY DAY ── -->
<?php if (empty($schedules)): ?>
<div class="wp-panel">
    <div class="wp-empty">
        <i class="fas fa-calendar-times"></i>
        <p>No schedules available for the selected zone. Please check back later or contact the barangay office.</p>
    </div>
</div>

<?php else:
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
foreach ($days as $day):
    if (!isset($schedule_by_day[$day])) continue;
    // Day of week for "today" highlight
    $today     = date('l');
    $is_today  = ($day === $today);
?>
<div class="wp-schedule-day">
    <div class="wp-schedule-day__header">
        <?php if ($is_today): ?>
        <div class="wp-schedule-day__dot" style="background:var(--db-amber);box-shadow:0 0 0 4px var(--db-amber-light)"></div>
        <?php else: ?>
        <div class="wp-schedule-day__dot" style="background:var(--db-muted);box-shadow:0 0 0 3px var(--db-border)"></div>
        <?php endif; ?>
        <div class="wp-schedule-day__name"><?php echo $day; ?></div>
        <?php if ($is_today): ?>
        <span class="wp-badge wp-badge--warning"><i class="fas fa-star"></i> Today</span>
        <?php endif; ?>
        <div class="wp-schedule-day__line"></div>
        <span class="wp-badge wp-badge--muted"><?php echo count($schedule_by_day[$day]); ?> route<?php echo count($schedule_by_day[$day]) > 1 ? 's' : ''; ?></span>
    </div>

    <div class="wp-schedule-grid">
        <?php foreach ($schedule_by_day[$day] as $sched):
            $wtype = $sched['waste_type'] ?? 'mixed';
            $conf  = $waste_config[$wtype] ?? $waste_config['mixed'];
        ?>
        <div class="wp-sched-card wp-sched-card--<?php echo $wtype; ?>">
            <div class="wp-sched-card__head">
                <div>
                    <div class="wp-sched-card__title"><?php echo $conf['label']; ?></div>
                    <div class="wp-sched-card__zone">
                        <i class="fas fa-map-marker-alt" style="margin-right:3px;color:var(--db-rose)"></i>
                        <?php echo htmlspecialchars($sched['area_zone']); ?>
                        <?php if (!empty($sched['purok'])): ?> — <?php echo htmlspecialchars($sched['purok']); ?><?php endif; ?>
                    </div>
                </div>
                <div class="wp-sched-card__type-icon" style="background:<?php echo $conf['bg']; ?>;color:<?php echo $conf['color']; ?>">
                    <i class="fas <?php echo $conf['icon']; ?>"></i>
                </div>
            </div>
            <div class="wp-sched-card__details">
                <div class="wp-sched-card__detail">
                    <i class="fas fa-clock" style="color:var(--db-navy)"></i>
                    <span><strong><?php echo date('g:i A', strtotime($sched['collection_time'])); ?></strong></span>
                    <span class="wp-badge <?php echo $conf['badge']; ?>" style="margin-left:auto"><?php echo ucfirst($wtype); ?></span>
                </div>
                <?php if (!empty($sched['collector_name'])): ?>
                <div class="wp-sched-card__detail">
                    <i class="fas fa-user"></i>
                    <span><?php echo htmlspecialchars($sched['collector_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($sched['truck_number'])): ?>
                <div class="wp-sched-card__detail">
                    <i class="fas fa-truck"></i>
                    <span>Truck <?php echo htmlspecialchars($sched['truck_number']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($sched['notes'])): ?>
            <p class="wp-sched-card__notes"><i class="fas fa-info-circle" style="margin-right:4px"></i><?php echo htmlspecialchars($sched['notes']); ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; endif; ?>

<!-- ── WASTE SEGREGATION GUIDE ── -->
<div class="wp-panel">
    <div class="wp-panel__header">
        <div class="wp-panel__title">
            <span class="wp-panel__icon wp-panel__icon--teal"><i class="fas fa-recycle"></i></span>
            <h2>Waste Segregation Guide</h2>
        </div>
    </div>
    <div class="wp-panel__body">
        <div style="display:flex;gap:14px;flex-wrap:wrap">
            <?php foreach ($waste_config as $key => $c): ?>
            <div class="wp-benefit-item">
                <i class="fas <?php echo $c['icon']; ?>" style="color:<?php echo $c['color']; ?>"></i>
                <div class="wp-benefit-item__title"><?php echo $c['label']; ?></div>
                <div class="wp-benefit-item__desc">
                    <?php
                    $seg_examples = [
                        'biodegradable'     => 'Food scraps, garden waste, paper, cardboard',
                        'non-biodegradable' => 'Plastics, styrofoam, diapers, sanitary items',
                        'recyclable'        => 'Bottles, cans, clean paper, cardboard boxes',
                        'hazardous'         => 'Batteries, chemicals, electronics, medical waste',
                        'mixed'             => 'General household waste not otherwise sorted',
                    ];
                    echo $seg_examples[$key] ?? '';
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="wp-panel__footer">
        <span style="font-size:12px;color:var(--db-muted)">
            <i class="fas fa-phone-alt" style="color:var(--db-success);margin-right:6px"></i>
            Questions? Contact the barangay office at <strong>(123) 456-7890</strong>
        </span>
    </div>
</div>

<?php
$conn->close();
include '../../../includes/footer.php';
?>