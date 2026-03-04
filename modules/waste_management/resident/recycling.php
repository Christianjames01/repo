<?php
require_once '../../../config/config.php';
requireLogin();
$user_role = getCurrentUserRole();
$user_id   = getCurrentUserId();
$page_title = 'Recycling Programs';

// Handle enrollment POST (unchanged logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_program'])) {
    $program_id  = (int)$_POST['program_id'];
    $resident_id = fetchOne($conn, "SELECT resident_id FROM tbl_users WHERE user_id = ?", [$user_id], 'i')['resident_id'] ?? null;
    if ($resident_id) {
        $existing = fetchOne($conn,
            "SELECT participant_id FROM tbl_recycling_participants WHERE program_id = ? AND resident_id = ?",
            [$program_id, $resident_id], 'ii'
        );
        if ($existing) {
            setMessage('You are already enrolled in this program!', 'warning');
        } else {
            $result = executeQuery($conn,
                "INSERT INTO tbl_recycling_participants (program_id, resident_id, enrollment_date, status) VALUES (?, ?, NOW(), 'active')",
                [$program_id, $resident_id], 'ii'
            );
            if ($result) {
                setMessage('Successfully enrolled in the recycling program!', 'success');
                logActivity($conn, $user_id, "Enrolled in recycling program ID: $program_id", 'tbl_recycling_participants', $conn->insert_id);
            } else {
                setMessage('Failed to enroll. Please try again.', 'error');
            }
        }
    }
    header('Location: recycling.php');
    exit();
}

$programs = fetchAll($conn, "SELECT * FROM tbl_recycling_programs WHERE status = 'active' ORDER BY created_at DESC");
$resident_id = fetchOne($conn, "SELECT resident_id FROM tbl_users WHERE user_id = ?", [$user_id], 'i')['resident_id'] ?? null;
$enrolled_programs = [];
if ($resident_id) {
    $enrolled_programs = fetchAll($conn,
        "SELECT rp.*, p.program_name, p.program_type
         FROM tbl_recycling_participants rp
         JOIN tbl_recycling_programs p ON rp.program_id = p.program_id
         WHERE rp.resident_id = ? AND rp.status = 'active'
         ORDER BY rp.enrollment_date DESC",
        [$resident_id], 'i'
    );
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/waste-pages.css?v=' . time() . '">';
include '../../../includes/header.php';

// Enrolled set for quick lookup
$enrolled_ids = array_column($enrolled_programs, 'program_id');

// Type icons map
$type_icons = [
    'Paper'    => ['fa-newspaper',     'wp-badge--info'],
    'Plastic'  => ['fa-recycle',       'wp-badge--primary'],
    'Metal'    => ['fa-cog',           'wp-badge--muted'],
    'Glass'    => ['fa-wine-bottle',   'wp-badge--info'],
    'E-Waste'  => ['fa-laptop',        'wp-badge--danger'],
    'Organic'  => ['fa-leaf',          'wp-badge--success'],
    'General'  => ['fa-recycle',       'wp-badge--teal'],
];
?>

<!-- ── PAGE HERO ── -->
<div class="wp-hero">
    <div class="wp-hero__ring wp-hero__ring--1"></div>
    <div class="wp-hero__ring wp-hero__ring--2"></div>
    <div class="wp-hero__ring wp-hero__ring--3"></div>
    <div class="wp-hero__inner">
        <div class="wp-hero__left">
            <div class="wp-hero__icon wp-hero__icon--teal">
                <i class="fas fa-recycle"></i>
            </div>
            <div>
                <h1 class="wp-hero__title">Recycling Programs</h1>
                <p class="wp-hero__sub">Join our programs and help build a cleaner, greener barangay</p>
            </div>
        </div>
        <?php if (!empty($enrolled_programs)): ?>
        <div class="wp-hero__actions">
            <span class="wp-badge wp-badge--success" style="padding:6px 14px;font-size:12px;">
                <i class="fas fa-check-circle"></i> Enrolled in <?php echo count($enrolled_programs); ?> program<?php echo count($enrolled_programs) > 1 ? 's' : ''; ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($msg = displayMessage()): ?>
<div style="margin-bottom:16px"><?php echo $msg; ?></div>
<?php endif; ?>

<!-- ── STAT CARDS ── -->
<div class="wp-stats-row">
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--teal"><i class="fas fa-recycle"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo count($programs); ?></div>
            <div class="wp-stat-card__label">Active Programs</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--teal"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--success"><i class="fas fa-user-check"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo count($enrolled_programs); ?></div>
            <div class="wp-stat-card__label">My Enrollments</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--success"></div>
    </div>
    <?php
    $total_kg    = array_sum(array_column($enrolled_programs, 'total_weight_kg'));
    $total_pts   = array_sum(array_column($enrolled_programs, 'points_earned'));
    $total_items = array_sum(array_column($enrolled_programs, 'total_items'));
    ?>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--amber"><i class="fas fa-weight"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($total_kg, 1); ?><small style="font-size:14px;font-weight:600"> kg</small></div>
            <div class="wp-stat-card__label">Total Contributed</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--amber"></div>
    </div>
    <div class="wp-stat-card">
        <div class="wp-stat-card__icon wp-stat-card__icon--indigo"><i class="fas fa-star"></i></div>
        <div>
            <div class="wp-stat-card__num"><?php echo number_format($total_pts); ?></div>
            <div class="wp-stat-card__label">Points Earned</div>
        </div>
        <div class="wp-stat-card__sparkline wp-stat-card__sparkline--indigo"></div>
    </div>
</div>

<!-- ── MY ENROLLED PROGRAMS ── -->
<?php if (!empty($enrolled_programs)): ?>
<div class="wp-enrolled-panel">
    <div class="wp-enrolled-panel__header">
        <i class="fas fa-check-circle"></i>
        <h2>My Enrolled Programs</h2>
    </div>
    <div class="wp-enrolled-panel__body">
        <div class="wp-table-wrap">
            <table class="wp-table">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Type</th>
                        <th>Enrolled Since</th>
                        <th>Contribution (kg)</th>
                        <th>Items</th>
                        <th>Points</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($enrolled_programs as $ep): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($ep['program_name']); ?></strong></td>
                    <td><span class="wp-badge wp-badge--teal"><?php echo htmlspecialchars($ep['program_type']); ?></span></td>
                    <td><span class="wp-text-sm"><?php echo date('M d, Y', strtotime($ep['enrollment_date'])); ?></span></td>
                    <td><strong><?php echo number_format($ep['total_weight_kg'], 2); ?> kg</strong></td>
                    <td><?php echo $ep['total_items']; ?></td>
                    <td>
                        <span class="wp-badge wp-badge--warning"><i class="fas fa-star"></i> <?php echo $ep['points_earned']; ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── AVAILABLE PROGRAMS HEADER ── -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
    <div style="font-size:16px;font-weight:800;color:var(--db-text)">Available Programs</div>
    <div style="flex:1;height:1px;background:var(--db-border)"></div>
    <span class="wp-badge wp-badge--muted"><?php echo count($programs); ?> active</span>
</div>

<?php if (empty($programs)): ?>
<div class="wp-panel">
    <div class="wp-empty">
        <i class="fas fa-inbox"></i>
        <p>No recycling programs available at the moment. Check back later.</p>
    </div>
</div>
<?php else: ?>

<!-- ── PROGRAM CARDS GRID ── -->
<div class="wp-program-grid">
    <?php foreach ($programs as $prog):
        $is_enrolled = in_array($prog['program_id'], $enrolled_ids);
        $pt = $prog['program_type'] ?? 'General';
        [$ico, $badge_cls] = $type_icons[$pt] ?? ['fa-recycle', 'wp-badge--teal'];
    ?>
    <div class="wp-program-card">
        <?php if ($is_enrolled): ?>
        <span class="wp-enrolled-badge"><i class="fas fa-check-circle"></i> Enrolled</span>
        <?php endif; ?>

        <div class="wp-program-card__accent"></div>
        <div class="wp-program-card__body">
            <div class="wp-program-card__head">
                <div>
                    <div class="wp-program-card__title"><?php echo htmlspecialchars($prog['program_name']); ?></div>
                    <span class="wp-badge <?php echo $badge_cls; ?>"><i class="fas <?php echo $ico; ?>"></i> <?php echo htmlspecialchars($pt); ?></span>
                </div>
                <div class="wp-program-card__icon-wrap">
                    <i class="fas <?php echo $ico; ?>"></i>
                </div>
            </div>

            <?php if (!empty($prog['description'])): ?>
            <p class="wp-program-card__desc"><?php echo nl2br(htmlspecialchars($prog['description'])); ?></p>
            <?php endif; ?>

            <div class="wp-program-card__meta">
                <?php if (!empty($prog['recyclable_items'])): ?>
                <div class="wp-program-card__meta-row">
                    <div class="wp-program-card__meta-icon" style="background:var(--db-success-light);color:var(--db-success)">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <div>
                        <div class="wp-program-card__meta-label">Accepted Materials</div>
                        <div class="wp-program-card__meta-val"><?php echo htmlspecialchars($prog['recyclable_items']); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($prog['collection_points'])): ?>
                <div class="wp-program-card__meta-row">
                    <div class="wp-program-card__meta-icon" style="background:var(--db-rose-light);color:var(--db-rose)">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div>
                        <div class="wp-program-card__meta-label">Collection Points</div>
                        <div class="wp-program-card__meta-val"><?php echo htmlspecialchars($prog['collection_points']); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($prog['schedule'])): ?>
                <div class="wp-program-card__meta-row">
                    <div class="wp-program-card__meta-icon" style="background:var(--db-info-light);color:var(--db-info)">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <div class="wp-program-card__meta-label">Schedule</div>
                        <div class="wp-program-card__meta-val"><?php echo htmlspecialchars($prog['schedule']); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($prog['incentive_type'])): ?>
                <div class="wp-program-card__meta-row">
                    <div class="wp-program-card__meta-icon" style="background:var(--db-amber-light);color:var(--db-amber-dark)">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div>
                        <div class="wp-program-card__meta-label">Incentives</div>
                        <div class="wp-program-card__meta-val"><?php echo htmlspecialchars($prog['incentive_type']); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="wp-program-card__footer">
            <div style="font-size:11.5px;color:var(--db-muted)">
                <?php if (!empty($prog['contact_person'])): ?>
                <div><i class="fas fa-user" style="width:14px;margin-right:4px"></i><?php echo htmlspecialchars($prog['contact_person']); ?></div>
                <?php endif; ?>
                <?php if (!empty($prog['contact_number'])): ?>
                <div><i class="fas fa-phone" style="width:14px;margin-right:4px"></i><?php echo htmlspecialchars($prog['contact_number']); ?></div>
                <?php endif; ?>
            </div>
            <?php if (!$is_enrolled): ?>
            <form method="POST" style="margin:0">
                <input type="hidden" name="program_id" value="<?php echo $prog['program_id']; ?>">
                <button type="submit" name="enroll_program" class="wp-btn wp-btn--success wp-btn--sm">
                    <i class="fas fa-user-plus"></i> Enroll Now
                </button>
            </form>
            <?php else: ?>
            <span class="wp-badge wp-badge--success" style="padding:6px 12px">
                <i class="fas fa-check-circle"></i> Enrolled
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── BENEFITS PANEL ── -->
<div class="wp-panel">
    <div class="wp-panel__header">
        <div class="wp-panel__title">
            <span class="wp-panel__icon wp-panel__icon--success"><i class="fas fa-seedling"></i></span>
            <h2>Benefits of Recycling</h2>
        </div>
    </div>
    <div class="wp-panel__body">
        <div style="display:flex;gap:14px;flex-wrap:wrap">
            <?php
            $benefits = [
                ['fas fa-globe-asia',  'var(--db-info)',    'Protects Environment', 'Reduces pollution and conserves natural resources'],
                ['fas fa-bolt',        'var(--db-amber)',   'Saves Energy',         'Recycling uses far less energy than producing new materials'],
                ['fas fa-coins',       'var(--db-success)', 'Earn Incentives',      'Get rewards and points for your recycling contributions'],
                ['fas fa-users',       'var(--db-teal)',    'Community Impact',     'Help build a cleaner, greener barangay for everyone'],
            ];
            foreach ($benefits as [$icon, $color, $title, $desc]):
            ?>
            <div class="wp-benefit-item">
                <i class="<?php echo $icon; ?>" style="color:<?php echo $color; ?>"></i>
                <div class="wp-benefit-item__title"><?php echo $title; ?></div>
                <div class="wp-benefit-item__desc"><?php echo $desc; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
$conn->close();
include '../../../includes/footer.php';
?>