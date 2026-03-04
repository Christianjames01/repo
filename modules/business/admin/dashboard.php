<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Business Permit Dashboard";

$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits");
$stats['total_permits'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'pending'");
$stats['pending'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'approved'");
$stats['approved'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'expired' OR (status = 'approved' AND expiry_date < CURDATE())");
$stats['expired'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT COUNT(*) as total FROM tbl_business_permits WHERE status = 'approved' AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stats['expiring_soon'] = $result->fetch_assoc()['total'];

$result = $conn->query("SELECT SUM(amount_paid) as total FROM tbl_business_permits WHERE payment_status = 'paid'");
$stats['total_revenue'] = $result->fetch_assoc()['total'] ?? 0;

$recent_applications = $conn->query("
    SELECT bp.*, r.first_name, r.last_name
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    ORDER BY bp.created_at DESC
    LIMIT 10
");

$monthly_revenue = $conn->query("
    SELECT DATE_FORMAT(payment_date, '%Y-%m') as month,
           SUM(amount_paid) as revenue, COUNT(*) as permits
    FROM tbl_business_permits
    WHERE payment_status = 'paid' AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND payment_date IS NOT NULL
    GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ORDER BY month
");
$chart_data = ['labels' => [], 'revenue' => [], 'permits' => []];
while ($row = $monthly_revenue->fetch_assoc()) {
    $chart_data['labels'][] = date('M Y', strtotime($row['month'] . '-01'));
    $chart_data['revenue'][] = $row['revenue'];
    $chart_data['permits'][] = $row['permits'];
}

$type_distribution = $conn->query("
    SELECT business_type, COUNT(*) as count
    FROM tbl_business_permits WHERE status = 'approved'
    GROUP BY business_type ORDER BY count DESC LIMIT 10
");

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
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
                <i class="fas fa-briefcase" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Business Permits
                </div>
                <h1 class="db-hero__title">Business Permit Dashboard</h1>
                <p class="db-hero__sub">Manage and monitor business permits across the barangay</p>
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

<!-- ═══ STAT CARDS ═══ -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue">
            <i class="fas fa-briefcase"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['total_permits']); ?></div>
            <div class="db-stat-card__label">Total Permits</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>

    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='applications.php?status=pending'">
        <div class="db-stat-card__icon db-stat-card__icon--amber">
            <i class="fas fa-clock"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['pending']); ?></div>
            <div class="db-stat-card__label">Pending Applications</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        <div class="db-stat-card__hint"><i class="fas fa-arrow-right"></i></div>
    </div>

    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['approved']); ?></div>
            <div class="db-stat-card__label">Active Permits</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>

    <div class="db-stat-card db-stat-card--clickable" onclick="window.location='renewals.php'">
        <div class="db-stat-card__icon" style="background:color-mix(in srgb,var(--db-rose) 12%,white);color:var(--db-rose);">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo number_format($stats['expiring_soon']); ?></div>
            <div class="db-stat-card__label">Expiring Soon</div>
        </div>
        <div class="db-stat-card__sparkline" style="background:linear-gradient(90deg,transparent,color-mix(in srgb,var(--db-rose) 20%,white));"></div>
        <div class="db-stat-card__hint"><i class="fas fa-arrow-right"></i></div>
    </div>

    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo">
            <i class="fas fa-peso-sign"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num" style="font-size:18px;">₱<?php echo number_format($stats['total_revenue'], 0); ?></div>
            <div class="db-stat-card__label">Total Revenue</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
</div>

<!-- ═══ MAIN GRID ═══ -->
<div class="db-grid">
    <div class="db-grid__main">

        <!-- Revenue Chart -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-chart-line"></i></span>
                    <h2>Revenue & Permits Trend</h2>
                </div>
            </div>
            <div style="padding:20px 24px;">
                <canvas id="revenueChart" height="90"></canvas>
            </div>
        </div>

        <!-- Recent Applications -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-file-alt"></i></span>
                    <h2>Recent Applications</h2>
                </div>
                <a href="applications.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
            </div>

            <?php if ($recent_applications->num_rows > 0): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Permit #</th>
                            <th>Business Name</th>
                            <th>Owner</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($app = $recent_applications->fetch_assoc()):
                        $badge_map = [
                            'pending' => 'db-badge--warning',
                            'for_inspection' => 'db-badge--info',
                            'approved' => 'db-badge--success',
                            'rejected' => 'db-badge--danger',
                            'expired' => 'db-badge--muted',
                            'cancelled' => 'db-badge--muted',
                        ];
                        $bc = $badge_map[$app['status']] ?? 'db-badge--muted';
                    ?>
                    <tr>
                        <td><span class="db-id"><?php echo htmlspecialchars($app['permit_number'] ?? '—'); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($app['business_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')); ?></td>
                        <td><span style="font-size:12px;color:var(--db-text-muted);"><?php echo htmlspecialchars($app['business_type'] ?? 'N/A'); ?></span></td>
                        <td><span style="font-size:12px;font-family:'DM Mono',monospace;"><?php echo date('M d, Y', strtotime($app['application_date'])); ?></span></td>
                        <td><span class="db-badge <?php echo $bc; ?>"><?php echo ucfirst(str_replace('_', ' ', $app['status'])); ?></span></td>
                        <td>
                            <a href="view-permit.php?id=<?php echo $app['permit_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer">
                <a href="applications.php" class="db-btn db-btn--outline db-btn--sm"><i class="fas fa-file-alt"></i> All Applications</a>
            </div>
            <?php else: ?>
            <div class="db-empty">
                <i class="fas fa-inbox"></i>
                <p>No applications found.</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /db-grid__main -->

    <div class="db-grid__side">

        <!-- Business Type Distribution -->
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-chart-pie"></i></span>
                    <h2>By Business Type</h2>
                </div>
            </div>
            <div style="padding:16px 20px;">
                <canvas id="typeChart" height="200"></canvas>
            </div>
        </div>

        <!-- Quick Actions -->
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
                    ['href' => 'applications.php', 'icon' => 'fa-file-alt',       'label' => 'View Applications',  'color' => 'var(--db-sky)',    'bg' => 'var(--db-sky-light)'],
                    ['href' => 'registry.php',     'icon' => 'fa-store',           'label' => 'Business Registry',  'color' => 'var(--db-indigo)', 'bg' => 'var(--db-indigo-light)'],
                    ['href' => 'renewals.php',      'icon' => 'fa-sync-alt',        'label' => 'Permit Renewals',    'color' => 'var(--db-teal)',   'bg' => 'var(--db-teal-light)'],
                    ['href' => 'applications.php?status=pending', 'icon' => 'fa-clock', 'label' => 'Pending Review', 'color' => 'var(--db-amber)', 'bg' => 'var(--db-amber-light)'],
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

        <!-- Expiring Soon Alert -->
        <?php if ($stats['expiring_soon'] > 0): ?>
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon" style="background:color-mix(in srgb,var(--db-rose) 12%,white);color:var(--db-rose);"><i class="fas fa-exclamation-triangle"></i></span>
                    <h2>Expiring Permits</h2>
                </div>
                <a href="renewals.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i></a>
            </div>
            <div style="padding:16px 20px;">
                <div style="background:color-mix(in srgb,var(--db-rose) 8%,white);border:1px solid color-mix(in srgb,var(--db-rose) 20%,white);border-radius:var(--db-radius-sm);padding:14px 16px;display:flex;align-items:center;gap:12px;">
                    <i class="fas fa-exclamation-circle" style="color:var(--db-rose);font-size:20px;flex-shrink:0;"></i>
                    <div>
                        <div style="font-weight:700;font-size:13px;margin-bottom:2px;"><?php echo $stats['expiring_soon']; ?> permit<?php echo $stats['expiring_soon'] > 1 ? 's' : ''; ?> expiring within 30 days</div>
                        <div style="font-size:11.5px;color:var(--db-muted);">Send reminders or process renewals now.</div>
                    </div>
                </div>
                <div style="margin-top:10px;">
                    <a href="renewals.php" class="db-btn db-btn--primary db-btn--sm db-btn--full"><i class="fas fa-sync-alt"></i> Process Renewals</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /db-grid__side -->
</div>

<style>
.db-quickaction-row {
    display:flex;align-items:center;gap:12px;padding:10px 12px;
    border-radius:var(--db-radius-sm);text-decoration:none;color:var(--db-text);
    font-size:13px;font-weight:600;border:1px solid var(--db-border);
    background:var(--db-surf);transition:all .18s ease;
}
.db-quickaction-row:hover { background:var(--db-surf2);transform:translateX(3px);box-shadow:var(--db-shadow);color:var(--db-text); }
.db-quickaction-icon { width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
setInterval(function(){
    const now=new Date();let h=now.getHours(),m=now.getMinutes(),s=now.getSeconds();
    const ap=h>=12?'PM':'AM';h=h%12||12;
    const el=document.getElementById('db-live-time');
    if(el)el.textContent=`${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')} ${ap}`;
},1000);

const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($chart_data['labels']); ?>,
        datasets: [{
            label: 'Revenue (₱)',
            data: <?php echo json_encode($chart_data['revenue']); ?>,
            borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.08)',
            tension: 0.4, yAxisID: 'y', fill: true
        }, {
            label: 'Permits Issued',
            data: <?php echo json_encode($chart_data['permits']); ?>,
            borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.08)',
            tension: 0.4, yAxisID: 'y1', fill: true
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: {
            y: { type:'linear', display:true, position:'left', title:{display:true,text:'Revenue (₱)'} },
            y1: { type:'linear', display:true, position:'right', title:{display:true,text:'Permits'}, grid:{drawOnChartArea:false} }
        }
    }
});

const typeCtx = document.getElementById('typeChart').getContext('2d');
new Chart(typeCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php $type_distribution->data_seek(0);$labels=[];while($row=$type_distribution->fetch_assoc())$labels[]="'".addslashes($row['business_type'])."'";echo implode(',',$labels); ?>],
        datasets: [{
            data: [<?php $type_distribution->data_seek(0);$data=[];while($row=$type_distribution->fetch_assoc())$data[]=$row['count'];echo implode(',',$data); ?>],
            backgroundColor: ['rgba(37,99,235,0.8)','rgba(16,185,129,0.8)','rgba(245,158,11,0.8)','rgba(239,68,68,0.8)','rgba(139,92,246,0.8)','rgba(6,182,212,0.8)','rgba(249,115,22,0.8)','rgba(20,184,166,0.8)','rgba(99,102,241,0.8)','rgba(236,72,153,0.8)']
        }]
    },
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{font:{size:11}} } } }
});
</script>

<?php include_once '../../../includes/footer.php'; ?>