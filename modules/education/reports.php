<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Education Reports';

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');

// Overall Statistics
$stats = fetchOne($conn, "SELECT
    COUNT(DISTINCT student_id) as total_students,
    COUNT(DISTINCT CASE WHEN scholarship_status='active'   THEN student_id END) as active_scholars,
    COUNT(DISTINCT CASE WHEN scholarship_status='pending'  THEN student_id END) as pending_applications,
    COUNT(DISTINCT CASE WHEN scholarship_status='rejected' THEN student_id END) as rejected_applications,
    SUM(CASE WHEN scholarship_status='active' THEN scholarship_amount ELSE 0 END) as total_scholarship_amount,
    COUNT(DISTINCT school_name) as total_schools
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?", [$start_date, $end_date], 'ss');

// Scholarship by Type
$scholarship_types = fetchAll($conn, "SELECT scholarship_type,
    COUNT(*) as count, SUM(scholarship_amount) as total_amount
    FROM tbl_education_students
    WHERE scholarship_status='active' AND application_date BETWEEN ? AND ?
    GROUP BY scholarship_type ORDER BY count DESC", [$start_date, $end_date], 'ss');

// Grade Level Distribution
$grade_distribution = fetchAll($conn, "SELECT grade_level,
    COUNT(*) as count,
    COUNT(CASE WHEN scholarship_status='active' THEN 1 END) as scholars
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?
    GROUP BY grade_level ORDER BY grade_level", [$start_date, $end_date], 'ss');

// Top Schools
$school_distribution = fetchAll($conn, "SELECT school_name,
    COUNT(*) as count,
    COUNT(CASE WHEN scholarship_status='active' THEN 1 END) as scholars,
    SUM(CASE WHEN scholarship_status='active' THEN scholarship_amount ELSE 0 END) as total_amount
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?
    GROUP BY school_name ORDER BY count DESC LIMIT 10", [$start_date, $end_date], 'ss');

// Monthly Trend
$monthly_trend = fetchAll($conn, "SELECT DATE_FORMAT(application_date,'%Y-%m') as month,
    COUNT(*) as applications,
    COUNT(CASE WHEN scholarship_status='active' THEN 1 END) as approved
    FROM tbl_education_students
    WHERE application_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(application_date,'%Y-%m')
    ORDER BY month DESC LIMIT 12", [$start_date, $end_date], 'ss');

// Assistance Summary
$assistance_stats = fetchOne($conn, "SELECT
    COUNT(*) as total_requests,
    COUNT(CASE WHEN status='pending'   THEN 1 END) as pending,
    COUNT(CASE WHEN status='approved'  THEN 1 END) as approved,
    COUNT(CASE WHEN status='completed' THEN 1 END) as completed,
    SUM(CASE WHEN status IN('approved','completed') THEN approved_amount ELSE 0 END) as total_assistance
    FROM tbl_education_assistance_requests
    WHERE request_date BETWEEN ? AND ?", [$start_date, $end_date], 'ss');

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero no-print">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-chart-bar" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Education Reports</h1>
                <p class="db-hero__sub">Comprehensive scholarship and assistance analytics</p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;">
            <button onclick="window.print()" class="db-btn db-btn--ghost db-btn--sm"
                    style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-print"></i> Print
            </button>
            <button onclick="exportToExcel()" class="db-btn db-btn--primary db-btn--sm">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
        </div>
    </div>
</div>


<!-- PRINT HEADER (hidden on screen) -->
<div style="display:none;" class="print-only">
    <div style="text-align:center;margin-bottom:24px;">
        <h2>Barangay Education Assistance Report</h2>
        <p>Period: <?php echo date('F d, Y', strtotime($start_date)); ?> — <?php echo date('F d, Y', strtotime($end_date)); ?></p>
        <p>Generated: <?php echo date('F d, Y h:i A'); ?></p>
    </div>
</div>


<!-- DATE RANGE FILTER -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-filter"></i></span>
            <h2>Date Range Filter</h2>
        </div>
    </div>
    <div style="padding:18px 22px;">
        <form method="GET" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">
            <div class="db-field" style="margin:0;">
                <label>Start Date</label>
                <input type="date" name="start_date" class="db-input" value="<?php echo $start_date; ?>" required>
            </div>
            <div class="db-field" style="margin:0;">
                <label>End Date</label>
                <input type="date" name="end_date" class="db-input" value="<?php echo $end_date; ?>" required>
            </div>
            <button type="submit" class="db-btn db-btn--primary">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
        </form>
        <div style="margin-top:12px;font-size:12px;color:var(--db-muted);">
            Showing data from <strong><?php echo date('F d, Y', strtotime($start_date)); ?></strong> to <strong><?php echo date('F d, Y', strtotime($end_date)); ?></strong>
        </div>
    </div>
</div>


<!-- STATS ROW -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-users"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo number_format($stats['total_students'] ?? 0); ?></div>
            <div class="db-stat-card__label">Total Students</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-user-graduate"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo number_format($stats['active_scholars'] ?? 0); ?></div>
            <div class="db-stat-card__label">Active Scholars</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo number_format($stats['pending_applications'] ?? 0); ?></div>
            <div class="db-stat-card__label">Pending</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="db-stat-card__num" style="font-size:16px;">₱<?php echo number_format($stats['total_scholarship_amount'] ?? 0, 0); ?></div>
            <div class="db-stat-card__label">Total Disbursed</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
</div>


<!-- ROW 1: Scholarship by Type + Grade Distribution -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;">

    <!-- Scholarship by Type -->
    <div class="db-panel" style="margin:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-award"></i></span>
                <h2>Scholarships by Type</h2>
            </div>
        </div>
        <?php if (empty($scholarship_types)): ?>
        <div class="db-empty db-empty--sm"><i class="fas fa-chart-pie"></i><p>No scholarship data</p></div>
        <?php else: ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead><tr><th>Type</th><th class="text-center">Scholars</th><th>Total Amount</th></tr></thead>
                <tbody>
                <?php foreach ($scholarship_types as $t): ?>
                <tr>
                    <td><?php echo htmlspecialchars($t['scholarship_type'] ?? 'N/A'); ?></td>
                    <td style="text-align:center;"><span class="db-badge db-badge--primary"><?php echo $t['count']; ?></span></td>
                    <td><strong style="color:var(--db-teal);">₱<?php echo number_format($t['total_amount'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Grade Level Distribution -->
    <div class="db-panel" style="margin:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-graduation-cap"></i></span>
                <h2>Grade Level Distribution</h2>
            </div>
        </div>
        <?php if (empty($grade_distribution)): ?>
        <div class="db-empty db-empty--sm"><i class="fas fa-chart-bar"></i><p>No grade level data</p></div>
        <?php else: ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead><tr><th>Grade Level</th><th>Students</th><th>Scholars</th><th>Rate</th></tr></thead>
                <tbody>
                <?php foreach ($grade_distribution as $g):
                    $rate = $g['count'] > 0 ? ($g['scholars'] / $g['count']) * 100 : 0;
                ?>
                <tr>
                    <td><span class="db-badge db-badge--primary"><?php echo htmlspecialchars($g['grade_level']); ?></span></td>
                    <td><?php echo $g['count']; ?></td>
                    <td><span class="db-badge db-badge--success"><?php echo $g['scholars']; ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:6px;background:var(--db-border);border-radius:3px;overflow:hidden;">
                                <div style="width:<?php echo min($rate, 100); ?>%;height:100%;background:var(--db-teal);border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px;color:var(--db-muted);white-space:nowrap;"><?php echo number_format($rate, 0); ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- TOP SCHOOLS -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-school"></i></span>
            <h2>Top 10 Schools</h2>
        </div>
        <span class="db-badge db-badge--muted"><?php echo count($school_distribution); ?> schools</span>
    </div>
    <?php if (empty($school_distribution)): ?>
    <div class="db-empty db-empty--sm"><i class="fas fa-school"></i><p>No school data available</p></div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>#</th><th>School Name</th><th>Total Students</th><th>Scholars</th><th>Total Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($school_distribution as $i => $sch): ?>
            <tr>
                <td style="font-family:'DM Mono',monospace;color:var(--db-muted);font-size:12px;"><?php echo str_pad($i + 1, 2, '0', STR_PAD_LEFT); ?></td>
                <td><strong><?php echo htmlspecialchars($sch['school_name']); ?></strong></td>
                <td><?php echo $sch['count']; ?></td>
                <td><span class="db-badge db-badge--success"><?php echo $sch['scholars']; ?></span></td>
                <td><strong style="color:var(--db-teal);">₱<?php echo number_format($sch['total_amount'], 2); ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>


<!-- ROW 2: Monthly Trend + Assistance Summary -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px;">

    <!-- Monthly Trend -->
    <div class="db-panel" style="margin:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-chart-line"></i></span>
                <h2>Monthly Application Trend</h2>
            </div>
        </div>
        <?php if (empty($monthly_trend)): ?>
        <div class="db-empty db-empty--sm"><i class="fas fa-chart-line"></i><p>No monthly data available</p></div>
        <?php else: ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead><tr><th>Month</th><th>Applications</th><th>Approved</th><th>Rate</th></tr></thead>
                <tbody>
                <?php foreach ($monthly_trend as $m):
                    $approval_rate = $m['applications'] > 0 ? ($m['approved'] / $m['applications']) * 100 : 0;
                ?>
                <tr>
                    <td style="font-size:12.5px;"><?php echo date('M Y', strtotime($m['month'] . '-01')); ?></td>
                    <td><?php echo $m['applications']; ?></td>
                    <td><span class="db-badge db-badge--success"><?php echo $m['approved']; ?></span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:6px;background:var(--db-border);border-radius:3px;overflow:hidden;">
                                <div style="width:<?php echo min($approval_rate, 100); ?>%;height:100%;background:var(--db-sky);border-radius:3px;"></div>
                            </div>
                            <span style="font-size:11px;color:var(--db-muted);"><?php echo number_format($approval_rate, 0); ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Assistance Summary -->
    <div class="db-panel" style="margin:0;">
        <div class="db-panel__header">
            <div class="db-panel__title">
                <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-hand-holding-usd"></i></span>
                <h2>Assistance Requests Summary</h2>
            </div>
        </div>
        <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <?php
            $asst_items = [
                ['blue',  'Total Requests', number_format($assistance_stats['total_requests'] ?? 0)],
                ['amber', 'Pending',         number_format($assistance_stats['pending']        ?? 0)],
                ['teal',  'Approved',        number_format($assistance_stats['approved']       ?? 0)],
                ['indigo','Completed',       number_format($assistance_stats['completed']      ?? 0)],
            ];
            foreach ($asst_items as [$color, $label, $value]): ?>
            <div style="padding:14px 16px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;"><?php echo $label; ?></div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="db-panel__icon db-panel__icon--<?php echo $color; ?>" style="width:24px;height:24px;font-size:10px;flex-shrink:0;"><i class="fas fa-circle" style="font-size:6px;"></i></span>
                    <strong style="font-size:22px;"><?php echo $value; ?></strong>
                </div>
            </div>
            <?php endforeach; ?>

            <div style="grid-column:1/-1;padding:16px 20px;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));border-radius:var(--db-radius-sm);display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:10px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.5px;">Total Assistance Given</div>
                    <strong style="font-size:22px;color:#fff;">₱<?php echo number_format($assistance_stats['total_assistance'] ?? 0, 2); ?></strong>
                </div>
                <i class="fas fa-hand-holding-usd" style="font-size:28px;color:rgba(255,255,255,.2);"></i>
            </div>
        </div>
    </div>
</div>


<style>
@media print {
    .no-print { display: none !important; }
    .print-only { display: block !important; }
    .db-panel { box-shadow: none !important; border: 1px solid #ddd !important; page-break-inside: avoid; }
    .db-hero { display: none !important; }
}
</style>

<script>
function exportToExcel() {
    const tables = document.querySelectorAll('table');
    let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    html += '<head><meta charset="UTF-8"></head><body>';
    html += '<h1>Barangay Education Assistance Report</h1>';
    html += '<p>Period: <?php echo date("F d, Y", strtotime($start_date)); ?> to <?php echo date("F d, Y", strtotime($end_date)); ?></p>';
    tables.forEach(t => html += t.outerHTML);
    html += '</body></html>';
    const uri = 'data:application/vnd.ms-excel;base64,' + btoa(unescape(encodeURIComponent(html)));
    const a = document.createElement('a');
    a.href = uri;
    a.download = 'education-report-<?php echo $start_date; ?>-to-<?php echo $end_date; ?>.xls';
    a.click();
}
</script>

<?php $conn->close(); include '../../includes/footer.php'; ?>