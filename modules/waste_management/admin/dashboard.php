<?php
require_once('../../../config/config.php');

requireLogin();
requireRole(['Super Admin', 'Admin', 'Staff']);

$page_title = "Waste Management";

$total_schedules    = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_waste_schedules WHERE status = 'active'", [], '')['count'] ?? 0;
$total_programs     = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_recycling_programs WHERE status = 'active'", [], '')['count'] ?? 0;
$total_participants = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_recycling_participants WHERE status = 'active'", [], '')['count'] ?? 0;
$pending_issues     = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_waste_issues WHERE status = 'pending'", [], '')['count'] ?? 0;

$recent_reports = fetchAll($conn,
    "SELECT * FROM tbl_waste_collection_reports ORDER BY collection_date DESC, created_at DESC LIMIT 5",
    [], ''
);

$pending_waste_issues = fetchAll($conn,
    "SELECT * FROM tbl_waste_issues
     WHERE status IN ('pending','acknowledged')
     ORDER BY CASE urgency WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END,
     created_at DESC LIMIT 5",
    [], ''
);

$current_month = date('Y-m');
$monthly_stats = fetchOne($conn,
    "SELECT * FROM tbl_waste_statistics WHERE stat_month = ? ORDER BY stat_date DESC LIMIT 1",
    [$current_month], 's'
);

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
require_once '../../../includes/header.php';
?>

<!-- ═══════════════════════════════════════════
     HERO
═══════════════════════════════════════════ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>

    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg,#0d9488,#0f766e);">
                <i class="fas fa-trash-alt" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Waste Management
                </div>
                <h1 class="db-hero__title">Waste Management Dashboard</h1>
                <p class="db-hero__sub">Monitor collection schedules, recycling programs, and waste issues</p>
            </div>
        </div>

        <div class="db-hero__right">
            <div class="db-hero__datetime">
                <div class="db-hero__date">
                    <i class="fas fa-calendar-day"></i>
                    <span><?php echo date('F j, Y'); ?></span>
                </div>
                <div class="db-hero__time" id="db-live-time"><?php echo date('g:i:s A'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Alerts -->
<?php echo displayMessage(); ?>


<!-- ═══════════════════════════════════════════
     STAT CARDS
═══════════════════════════════════════════ -->
<div class="db-stats-row">

    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('schedulesSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--blue">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $total_schedules; ?></div>
            <div class="db-stat-card__label">Active Schedules</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>

    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('programsSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--teal">
            <i class="fas fa-recycle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $total_programs; ?></div>
            <div class="db-stat-card__label">Active Programs</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>

    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo">
            <i class="fas fa-users"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $total_participants; ?></div>
            <div class="db-stat-card__label">Program Participants</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>

    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('issuesSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--amber">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $pending_issues; ?></div>
            <div class="db-stat-card__label">Pending Issues</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>

</div>


<!-- ═══════════════════════════════════════════
     MAIN GRID
═══════════════════════════════════════════ -->
<div class="db-grid">

    <!-- ── LEFT / MAIN COLUMN ── -->
    <div class="db-grid__main">

        <!-- ── PENDING ISSUES (toggled) ── -->
        <div class="db-panel" id="issuesSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-exclamation-triangle"></i></span>
                    <h2>Pending Waste Issues</h2>
                </div>
                <a href="reports-issues.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
            </div>

            <?php if (!empty($pending_waste_issues)): ?>
            <div class="db-announcements">
                <?php foreach ($pending_waste_issues as $issue):
                    $urg = $issue['urgency'] ?? 'low';
                    $urg_badge = ['high' => 'db-badge--danger', 'medium' => 'db-badge--warning', 'low' => 'db-badge--success'];
                    $urg_icon  = ['high' => 'fa-exclamation-circle', 'medium' => 'fa-exclamation-triangle', 'low' => 'fa-info-circle'];
                    $ann_class = ['high' => 'db-ann--urgent', 'medium' => 'db-ann--high', 'low' => 'db-ann--normal'];
                ?>
                <div class="db-ann <?php echo $ann_class[$urg] ?? 'db-ann--normal'; ?>">
                    <div class="db-ann__stripe"></div>
                    <div class="db-ann__body">
                        <div class="db-ann__top">
                            <div class="db-ann__meta">
                                <i class="fas <?php echo $urg_icon[$urg] ?? 'fa-info-circle'; ?> db-ann__icon"></i>
                                <div>
                                    <div class="db-ann__title"><?php echo htmlspecialchars($issue['issue_type']); ?></div>
                                    <span class="db-badge <?php echo $urg_badge[$urg] ?? 'db-badge--muted'; ?>">
                                        <?php echo strtoupper($urg); ?> URGENCY
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span class="db-badge db-badge--warning"><?php echo ucfirst($issue['status']); ?></span>
                            </div>
                        </div>
                        <div class="db-ann__content"><?php echo htmlspecialchars(substr($issue['description'], 0, 180)) . (strlen($issue['description']) > 180 ? '…' : ''); ?></div>
                        <div class="db-ann__date">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($issue['location']); ?>
                            &nbsp;·&nbsp;
                            <i class="far fa-clock"></i>
                            <?php echo date('F j, Y g:i A', strtotime($issue['created_at'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="db-empty">
                <i class="fas fa-check-circle" style="color:var(--db-success)"></i>
                <p>No pending issues — all clear!</p>
            </div>
            <?php endif; ?>

            <div class="db-panel__footer">
                <a href="reports-issues.php" class="db-btn db-btn--outline db-btn--sm"><i class="fas fa-exclamation-circle"></i> View All Issues</a>
            </div>
        </div>


        <!-- ── COLLECTION SCHEDULES LINK (toggled placeholder) ── -->
        <div class="db-panel" id="schedulesSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-calendar-alt"></i></span>
                    <h2>Active Collection Schedules</h2>
                </div>
                <a href="schedules.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> Manage</a>
            </div>
            <div class="db-empty">
                <i class="fas fa-calendar-alt"></i>
                <p>Open the full schedules manager to view and edit all active collection routes.</p>
                <a href="schedules.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-external-link-alt"></i> Open Schedules</a>
            </div>
        </div>


        <!-- ── RECYCLING PROGRAMS LINK (toggled placeholder) ── -->
        <div class="db-panel" id="programsSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-recycle"></i></span>
                    <h2>Active Recycling Programs</h2>
                </div>
                <a href="programs.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> Manage</a>
            </div>
            <div class="db-empty">
                <i class="fas fa-recycle"></i>
                <p>Open the recycling programs manager to enrol participants and track progress.</p>
                <a href="programs.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-external-link-alt"></i> Open Programs</a>
            </div>
        </div>


        <!-- ══════════════════════════════════════
             MONTHLY STATISTICS
        ══════════════════════════════════════ -->
        <?php if ($monthly_stats): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-chart-bar"></i></span>
                    <h2>Monthly Statistics — <?php echo date('F Y', strtotime($current_month . '-01')); ?></h2>
                </div>
            </div>

            <!-- Waste type breakdown -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1px;background:var(--db-border);border-bottom:1px solid var(--db-border);">

                <?php
                $tiles = [
                    ['label' => 'Biodegradable',    'value' => number_format($monthly_stats['biodegradable_kg'], 2)    . ' kg', 'icon' => 'fa-leaf',          'color' => 'var(--db-success)'],
                    ['label' => 'Non-Biodegradable','value' => number_format($monthly_stats['non_biodegradable_kg'], 2) . ' kg', 'icon' => 'fa-times-circle',  'color' => 'var(--db-rose)'],
                    ['label' => 'Recyclable',       'value' => number_format($monthly_stats['recyclable_kg'], 2)        . ' kg', 'icon' => 'fa-recycle',       'color' => 'var(--db-sky)'],
                    ['label' => 'Total Waste',      'value' => number_format($monthly_stats['total_kg'], 2)             . ' kg', 'icon' => 'fa-trash-alt',     'color' => 'var(--db-indigo)'],
                ];
                foreach ($tiles as $t): ?>
                <div style="background:var(--db-surf);padding:22px 20px;display:flex;align-items:center;gap:14px;">
                    <div style="width:42px;height:42px;border-radius:11px;background:color-mix(in srgb,<?php echo $t['color']; ?> 12%,white);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="fas <?php echo $t['icon']; ?>" style="font-size:17px;color:<?php echo $t['color']; ?>;"></i>
                    </div>
                    <div>
                        <div style="font-size:18px;font-weight:800;letter-spacing:-0.5px;color:var(--db-text);"><?php echo $t['value']; ?></div>
                        <div style="font-size:10.5px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:0.4px;"><?php echo $t['label']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- KPI row -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0;padding:18px 22px;">
                <?php
                $kpis = [
                    ['label' => 'Households Served', 'value' => number_format($monthly_stats['households_served']),                 'icon' => 'fa-home'],
                    ['label' => 'Recycling Rate',    'value' => number_format($monthly_stats['recycling_rate'], 2) . '%',           'icon' => 'fa-percentage'],
                    ['label' => 'Landfill Diversion','value' => number_format($monthly_stats['landfill_diversion_rate'], 2) . '%',  'icon' => 'fa-chart-pie'],
                ];
                foreach ($kpis as $k): ?>
                <div style="text-align:center;padding:12px;">
                    <div style="font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
                        <i class="fas <?php echo $k['icon']; ?> me-1"></i><?php echo $k['label']; ?>
                    </div>
                    <div style="font-size:22px;font-weight:800;letter-spacing:-0.5px;"><?php echo $k['value']; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>


        <!-- ══════════════════════════════════════
             RECENT COLLECTION REPORTS
        ══════════════════════════════════════ -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-clipboard-list"></i></span>
                    <h2>Recent Collection Reports</h2>
                </div>
                <a href="reports.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
            </div>

            <?php if (!empty($recent_reports)): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Area / Zone</th>
                            <th>Waste Type</th>
                            <th>Quantity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_reports as $report):
                        $status = $report['status'] ?? 'pending';
                        $st_map = [
                            'completed' => 'db-badge--success',
                            'in_progress' => 'db-badge--primary',
                            'pending' => 'db-badge--warning',
                            'cancelled' => 'db-badge--muted',
                        ];
                        $waste_icons = [
                            'biodegradable'     => 'fa-leaf',
                            'non-biodegradable' => 'fa-times-circle',
                            'recyclable'        => 'fa-recycle',
                            'hazardous'         => 'fa-skull-crossbones',
                        ];
                        $wtype = strtolower($report['waste_type'] ?? '');
                        $wicon = $waste_icons[$wtype] ?? 'fa-trash-alt';
                    ?>
                    <tr>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($report['collection_date'])); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($report['area_zone']); ?></strong></td>
                        <td>
                            <i class="fas <?php echo $wicon; ?> me-1" style="color:var(--db-muted)"></i>
                            <?php echo ucfirst($report['waste_type']); ?>
                        </td>
                        <td>
                            <?php echo $report['quantity_kg']
                                ? '<span style="font-family:\'DM Mono\',monospace;font-size:12px;">' . number_format($report['quantity_kg'], 2) . ' kg</span>'
                                : '<span class="db-text-muted">—</span>'; ?>
                        </td>
                        <td>
                            <span class="db-badge <?php echo $st_map[$status] ?? 'db-badge--muted'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer">
                <a href="reports.php" class="db-btn db-btn--outline db-btn--sm"><i class="fas fa-clipboard-list"></i> View All Reports</a>
            </div>
            <?php else: ?>
            <div class="db-empty">
                <i class="fas fa-clipboard-list"></i>
                <p>No collection reports yet.</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /db-grid__main -->


    <!-- ── RIGHT SIDEBAR COLUMN ── -->
    <div class="db-grid__side">

        <!-- ══════════════════════════════════════
             QUICK ACTIONS
        ══════════════════════════════════════ -->
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bolt"></i></span>
                    <h2>Quick Actions</h2>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;padding:16px 18px;">

                <?php
                $actions = [
                    ['href' => 'schedules.php',      'icon' => 'fa-calendar-plus',      'label' => 'Manage Schedules',     'color' => 'var(--db-sky)',    'bg' => 'var(--db-sky-light)'],
                    ['href' => 'programs.php',       'icon' => 'fa-leaf',               'label' => 'Recycling Programs',   'color' => 'var(--db-teal)',   'bg' => 'var(--db-teal-light)'],
                    ['href' => 'reports.php',        'icon' => 'fa-file-alt',           'label' => 'Collection Reports',   'color' => 'var(--db-indigo)', 'bg' => 'var(--db-indigo-light)'],
                    ['href' => 'reports-issues.php', 'icon' => 'fa-exclamation-triangle','label' => 'Waste Issues',        'color' => 'var(--db-amber-dark)', 'bg' => 'var(--db-amber-light)'],
                ];
                foreach ($actions as $a): ?>
                <a href="<?php echo $a['href']; ?>" class="db-quickaction-row">
                    <div class="db-quickaction-icon" style="background:<?php echo $a['bg']; ?>;color:<?php echo $a['color']; ?>;">
                        <i class="fas <?php echo $a['icon']; ?>"></i>
                    </div>
                    <span><?php echo $a['label']; ?></span>
                    <i class="fas fa-chevron-right" style="margin-left:auto;font-size:10px;color:var(--db-muted);"></i>
                </a>
                <?php endforeach; ?>

            </div>
        </div>


        <!-- ══════════════════════════════════════
             PENDING ISSUES SIDEBAR FEED
        ══════════════════════════════════════ -->
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-exclamation-circle"></i></span>
                    <h2>Urgent Issues</h2>
                </div>
                <a href="reports-issues.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i></a>
            </div>

            <?php if (!empty($pending_waste_issues)): ?>
            <div style="display:flex;flex-direction:column;gap:0;">
                <?php foreach ($pending_waste_issues as $issue):
                    $urg = $issue['urgency'] ?? 'low';
                    $urg_badge = ['high' => 'db-badge--danger', 'medium' => 'db-badge--warning', 'low' => 'db-badge--success'];
                    $urg_dot   = ['high' => 'var(--db-rose)', 'medium' => 'var(--db-amber)', 'low' => 'var(--db-success)'];
                ?>
                <div style="padding:14px 18px;border-bottom:1px solid var(--db-border);display:flex;gap:12px;align-items:flex-start;">
                    <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $urg_dot[$urg] ?? 'var(--db-muted)'; ?>;margin-top:5px;flex-shrink:0;box-shadow:0 0 0 3px <?php echo $urg_dot[$urg] ?? 'var(--db-muted)'; ?>22;"></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;font-weight:700;margin-bottom:3px;"><?php echo htmlspecialchars($issue['issue_type']); ?></div>
                        <div style="font-size:11.5px;color:var(--db-muted);line-height:1.5;margin-bottom:6px;">
                            <?php echo htmlspecialchars(substr($issue['description'], 0, 80)) . (strlen($issue['description']) > 80 ? '…' : ''); ?>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span class="db-badge <?php echo $urg_badge[$urg] ?? 'db-badge--muted'; ?>"><?php echo strtoupper($urg); ?></span>
                            <span style="font-family:'DM Mono',monospace;font-size:10px;color:var(--db-muted);">
                                <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars(substr($issue['location'], 0, 28)); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="db-panel__footer">
                <a href="reports-issues.php" class="db-btn db-btn--outline db-btn--sm"><i class="fas fa-list"></i> All Issues</a>
            </div>
            <?php else: ?>
            <div class="db-empty db-empty--sm">
                <i class="fas fa-check-circle" style="color:var(--db-success)"></i>
                <p>No pending issues</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /db-grid__side -->

</div><!-- /db-grid -->


<!-- ═══════════════════════════════════════════
     EXTRA STYLES (scoped additions only)
═══════════════════════════════════════════ -->
<style>
/* Quick action rows in the sidebar */
.db-quickaction-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-radius: var(--db-radius-sm);
    text-decoration: none;
    color: var(--db-text);
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--db-border);
    background: var(--db-surf);
    transition: all .18s ease;
}
.db-quickaction-row:hover {
    background: var(--db-surf2);
    transform: translateX(3px);
    box-shadow: var(--db-shadow);
    color: var(--db-text);
}
.db-quickaction-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
</style>


<script>
/* Live clock */
setInterval(function () {
    const now = new Date();
    let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const el = document.getElementById('db-live-time');
    if (el) el.textContent = `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')} ${ap}`;
}, 1000);

/* Toggle sections (one at a time) */
const WM_SECTIONS = ['schedulesSection','programsSection','issuesSection'];
function toggleSection(id, triggerCard) {
    const target = document.getElementById(id);
    if (!target) return;
    const isOpen = target.style.display !== 'none';
    WM_SECTIONS.forEach(sid => { const s = document.getElementById(sid); if (s) s.style.display = 'none'; });
    document.querySelectorAll('.db-stat-card--clickable').forEach(c => c.classList.remove('db-stat-card--active'));
    if (!isOpen) {
        target.style.display = 'block';
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (triggerCard) triggerCard.classList.add('db-stat-card--active');
    }
}

/* Auto-dismiss alerts */
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php require_once '../../../includes/footer.php'; ?>