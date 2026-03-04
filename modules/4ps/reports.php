<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

$page_title = '4Ps Reports';

$start_date  = isset($_GET['start_date'])  ? $_GET['start_date']  : date('Y-01-01');
$end_date    = isset($_GET['end_date'])    ? $_GET['end_date']    : date('Y-m-d');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';

// Overall stats
$stmt = $conn->prepare("SELECT
    COUNT(*) as total_beneficiaries,
    SUM(CASE WHEN status='Active'    THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status='Inactive'  THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN status='Suspended' THEN 1 ELSE 0 END) as suspended,
    SUM(CASE WHEN status='Graduated' THEN 1 ELSE 0 END) as graduated,
    SUM(CASE WHEN status='Active' THEN monthly_grant ELSE 0 END) as total_active_grants,
    SUM(monthly_grant) as total_all_grants,
    AVG(CASE WHEN status='Active' THEN monthly_grant ELSE NULL END) as avg_grant,
    SUM(CASE WHEN compliance_status='Compliant'     THEN 1 ELSE 0 END) as compliant,
    SUM(CASE WHEN compliance_status='Non-Compliant' THEN 1 ELSE 0 END) as non_compliant,
    SUM(CASE WHEN compliance_status='Partial'       THEN 1 ELSE 0 END) as partial_compliant
    FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?");
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Status distribution
$stmt = $conn->prepare("SELECT status, COUNT(*) as count, SUM(monthly_grant) as total_grant,
    ROUND((COUNT(*)*100.0/(SELECT COUNT(*) FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?)),1) as percentage
    FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?
    GROUP BY status ORDER BY count DESC");
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$status_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Compliance distribution
$stmt = $conn->prepare("SELECT compliance_status, COUNT(*) as count, SUM(monthly_grant) as total_grant,
    ROUND((COUNT(*)*100.0/(SELECT COUNT(*) FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?)),1) as percentage
    FROM tbl_4ps_beneficiaries WHERE date_registered BETWEEN ? AND ?
    GROUP BY compliance_status ORDER BY count DESC");
$stmt->bind_param("ssss", $start_date, $end_date, $start_date, $end_date);
$stmt->execute();
$compliance_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Monthly trend (last 12 months)
$trend_result = $conn->query("SELECT DATE_FORMAT(date_registered,'%Y-%m') as month,
    DATE_FORMAT(date_registered,'%b %Y') as month_label, COUNT(*) as registrations
    FROM tbl_4ps_beneficiaries
    WHERE date_registered >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_registered,'%Y-%m')
    ORDER BY month DESC LIMIT 12");
$trend_data = array_reverse($trend_result->fetch_all(MYSQLI_ASSOC));

include __DIR__ . '/../../includes/header.php';
?>

<!-- PAGE HERO -->
<div class="bps-hero">
    <div class="bps-hero__ring bps-hero__ring--1"></div>
    <div class="bps-hero__ring bps-hero__ring--2"></div>
    <div class="bps-hero__ring bps-hero__ring--3"></div>
    <div class="bps-hero__inner">
        <div class="bps-hero__left">
            <div class="bps-hero__icon" style="background:linear-gradient(135deg,#6366f1,#4f46e5);">
                <i class="fas fa-chart-bar"></i>
            </div>
            <div>
                <h1 class="bps-hero__title">4Ps Reports &amp; Analytics</h1>
                <p class="bps-hero__sub">
                    Beneficiaries data for <?php echo date('M d, Y', strtotime($start_date)); ?> &mdash; <?php echo date('M d, Y', strtotime($end_date)); ?>
                </p>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
            <a href="beneficiaries-debug.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>
</div>

<div class="container-fluid">

    <!-- STAT CARDS -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['total_beneficiaries']); ?></div>
                    <div class="stat-label">Total Beneficiaries</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                    <div class="stat-label">Active</div>
                    <div class="stat-sub"><?php echo $stats['total_beneficiaries'] > 0 ? round(($stats['active']/$stats['total_beneficiaries'])*100,1) : 0; ?>% of total</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-info"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-content">
                    <div class="stat-value stat-value--sm">&#8369;<?php echo number_format($stats['total_active_grants'], 2); ?></div>
                    <div class="stat-label">Monthly Budget</div>
                    <div class="stat-sub">Active beneficiaries</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <div class="stat-icon bg-warning"><i class="fas fa-calculator"></i></div>
                <div class="stat-content">
                    <div class="stat-value stat-value--sm">&#8369;<?php echo number_format($stats['avg_grant'], 2); ?></div>
                    <div class="stat-label">Average Grant</div>
                    <div class="stat-sub">Per family</div>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER -->
    <div class="card shadow mb-4">
        <div class="card-header">
            <h5><i class="fas fa-filter" style="opacity:.7"></i> Filter Report</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Report Type</label>
                    <select class="form-select" name="report_type">
                        <option value="overview"   <?php echo $report_type=='overview'?'selected':''; ?>>Overview</option>
                        <option value="status"     <?php echo $report_type=='status'?'selected':''; ?>>Status Analysis</option>
                        <option value="compliance" <?php echo $report_type=='compliance'?'selected':''; ?>>Compliance Report</option>
                        <option value="trend"      <?php echo $report_type=='trend'?'selected':''; ?>>Registration Trend</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row">

        <!-- LEFT: Status + Trend tables -->
        <div class="col-lg-8">

            <!-- Status Distribution -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie" style="opacity:.7"></i> Status Distribution</h5>
                    <small><?php echo number_format($stats['total_beneficiaries']); ?> total beneficiaries</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover" id="beneficiariesTable">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Share</th>
                                <th>Total Grant</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $status_badge = ['Active'=>'bg-success','Suspended'=>'bg-warning','Graduated'=>'bg-info','Inactive'=>'bg-secondary'];
                        $bar_color    = ['Active'=>'var(--db-success)','Suspended'=>'var(--db-warning)','Graduated'=>'var(--db-sky)','Inactive'=>'var(--db-muted)'];
                        foreach ($status_data as $row):
                            $bc = $status_badge[$row['status']] ?? 'bg-secondary';
                            $bv = $bar_color[$row['status']]   ?? 'var(--db-muted)';
                        ?>
                        <tr>
                            <td><span class="badge <?php echo $bc; ?>"><?php echo $row['status']; ?></span></td>
                            <td><strong><?php echo number_format($row['count']); ?></strong></td>
                            <td style="min-width:180px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress-bar-wrap">
                                        <div class="progress-bar-fill" style="width:<?php echo $row['percentage']; ?>%;background:<?php echo $bv; ?>;"></div>
                                    </div>
                                    <span class="pct-label"><?php echo $row['percentage']; ?>%</span>
                                </div>
                            </td>
                            <td><strong style="color:var(--db-teal)">&#8369;<?php echo number_format($row['total_grant'], 2); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-foot-row">
                                <td><strong>Total</strong></td>
                                <td><strong><?php echo number_format($stats['total_beneficiaries']); ?></strong></td>
                                <td><span class="pct-label">100%</span></td>
                                <td><strong style="color:var(--db-teal)">&#8369;<?php echo number_format($stats['total_all_grants'], 2); ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Registration Trend -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line" style="opacity:.7"></i> Registration Trend</h5>
                    <small><i class="fas fa-info-circle"></i> Last 12 months</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>New Registrations</th>
                                <th>Trend</th>
                                <th>Visual</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $max_reg = !empty($trend_data) ? max(array_column($trend_data,'registrations')) : 1;
                        $prev = 0;
                        foreach ($trend_data as $idx => $row):
                            $change = $idx > 0 ? $row['registrations'] - $prev : 0;
                            $prev   = $row['registrations'];
                            $bar_pct = $max_reg > 0 ? ($row['registrations']/$max_reg*100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo $row['month_label']; ?></strong></td>
                            <td>
                                <span class="badge bg-primary"><?php echo number_format($row['registrations']); ?></span>
                            </td>
                            <td>
                                <?php if ($idx > 0): ?>
                                    <?php if ($change > 0): ?>
                                        <span style="color:var(--db-success);font-size:12px;font-weight:600;"><i class="fas fa-arrow-up"></i> +<?php echo $change; ?></span>
                                    <?php elseif ($change < 0): ?>
                                        <span style="color:var(--db-danger);font-size:12px;font-weight:600;"><i class="fas fa-arrow-down"></i> <?php echo $change; ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--db-muted);font-size:12px;"><i class="fas fa-minus"></i> &mdash;</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--db-muted);font-size:12px;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:120px;">
                                <div class="progress-bar-wrap">
                                    <div class="progress-bar-fill" style="width:<?php echo $bar_pct; ?>%;background:var(--db-sky);"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-foot-row">
                                <td><strong>12-Month Total</strong></td>
                                <td><span class="badge bg-primary"><?php echo number_format(array_sum(array_column($trend_data,'registrations'))); ?></span></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div><!-- /col-lg-8 -->

        <!-- RIGHT: Compliance + Breakdowns -->
        <div class="col-lg-4">

            <!-- Compliance Distribution -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-clipboard-check" style="opacity:.7"></i> Compliance Status</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr><th>Status</th><th>Count</th><th>Grant</th></tr>
                        </thead>
                        <tbody>
                        <?php
                        $comp_badge = ['Compliant'=>'bg-success','Partial'=>'bg-warning','Non-Compliant'=>'bg-danger'];
                        $comp_bar   = ['Compliant'=>'var(--db-success)','Partial'=>'var(--db-warning)','Non-Compliant'=>'var(--db-danger)'];
                        foreach ($compliance_data as $row):
                            $cc = $comp_badge[$row['compliance_status']] ?? 'bg-secondary';
                            $cb = $comp_bar[$row['compliance_status']]   ?? 'var(--db-muted)';
                        ?>
                        <tr>
                            <td>
                                <span class="badge <?php echo $cc; ?>"><?php echo $row['compliance_status']; ?></span>
                                <div class="progress-bar-wrap mt-1">
                                    <div class="progress-bar-fill" style="width:<?php echo $row['percentage']; ?>%;background:<?php echo $cb; ?>;"></div>
                                </div>
                            </td>
                            <td>
                                <strong><?php echo number_format($row['count']); ?></strong><br>
                                <span class="pct-label"><?php echo $row['percentage']; ?>%</span>
                            </td>
                            <td>
                                <strong style="color:var(--db-teal);font-family:'DM Mono',monospace;font-size:11.5px;">
                                    &#8369;<?php echo number_format($row['total_grant'],2); ?>
                                </strong>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Status Breakdown -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-list-alt" style="opacity:.7"></i> Status Breakdown</h5>
                </div>
                <div class="card-body" style="padding:8px 22px 16px !important;">
                    <?php
                    $breakdown = [
                        ['icon'=>'fa-check-circle','color'=>'var(--db-success)','label'=>'Active','val'=>$stats['active']],
                        ['icon'=>'fa-pause-circle','color'=>'var(--db-warning)','label'=>'Suspended','val'=>$stats['suspended']],
                        ['icon'=>'fa-graduation-cap','color'=>'var(--db-sky)','label'=>'Graduated','val'=>$stats['graduated']],
                        ['icon'=>'fa-times-circle','color'=>'var(--db-muted)','label'=>'Inactive','val'=>$stats['inactive']],
                    ];
                    foreach ($breakdown as $b):
                    ?>
                    <div class="breakdown-row">
                        <span class="breakdown-label">
                            <i class="fas <?php echo $b['icon']; ?>" style="color:<?php echo $b['color']; ?>;width:14px;"></i>
                            <?php echo $b['label']; ?>
                        </span>
                        <strong style="font-family:'DM Mono',monospace;color:var(--db-indigo)"><?php echo number_format($b['val']); ?></strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Compliance Breakdown -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-shield-alt" style="opacity:.7"></i> Compliance Breakdown</h5>
                </div>
                <div class="card-body" style="padding:8px 22px 16px !important;">
                    <?php
                    $comp_bd = [
                        ['icon'=>'fa-check-double','color'=>'var(--db-success)','label'=>'Compliant','val'=>$stats['compliant']],
                        ['icon'=>'fa-exclamation-triangle','color'=>'var(--db-warning)','label'=>'Partial','val'=>$stats['partial_compliant']],
                        ['icon'=>'fa-times-circle','color'=>'var(--db-danger)','label'=>'Non-Compliant','val'=>$stats['non_compliant']],
                    ];
                    foreach ($comp_bd as $b):
                    ?>
                    <div class="breakdown-row">
                        <span class="breakdown-label">
                            <i class="fas <?php echo $b['icon']; ?>" style="color:<?php echo $b['color']; ?>;width:14px;"></i>
                            <?php echo $b['label']; ?>
                        </span>
                        <strong style="font-family:'DM Mono',monospace;color:var(--db-indigo)"><?php echo number_format($b['val']); ?></strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Financial Summary -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-coins" style="opacity:.7"></i> Financial Summary</h5>
                </div>
                <div class="card-body">
                    <div class="fin-box" style="border-color:rgba(14,165,233,.3)">
                        <div class="fin-label">Active Monthly Budget</div>
                        <div class="fin-value" style="color:var(--db-sky)">&#8369;<?php echo number_format($stats['total_active_grants'],2); ?></div>
                    </div>
                    <div class="fin-box" style="border-color:rgba(99,102,241,.3)">
                        <div class="fin-label">Total (All Statuses)</div>
                        <div class="fin-value" style="color:var(--db-indigo)">&#8369;<?php echo number_format($stats['total_all_grants'],2); ?></div>
                    </div>
                    <div class="fin-box" style="border-color:rgba(16,185,129,.3);margin-bottom:0">
                        <div class="fin-label">Average Grant / Family</div>
                        <div class="fin-value" style="color:var(--db-success)">&#8369;<?php echo number_format($stats['avg_grant'],2); ?></div>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-4 -->
    </div><!-- /row -->
</div><!-- /container-fluid -->

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root {
    --db-navy:#0d1b36; --db-navy-mid:#152849; --db-navy-light:#1c3461;
    --db-amber:#f59e0b; --db-amber-light:#fef3c7; --db-amber-dark:#b45309;
    --db-teal:#0d9488; --db-sky:#0ea5e9; --db-sky-light:#e0f2fe;
    --db-indigo:#6366f1; --db-indigo-light:#e0e7ff;
    --db-success:#10b981; --db-success-light:#d1fae5;
    --db-warning:#f59e0b; --db-warning-light:#fef3c7;
    --db-danger:#ef4444; --db-danger-light:#fee2e2;
    --db-bg:#eef2f7; --db-surf:#ffffff; --db-surf2:#f8fafc;
    --db-border:#e2e8f0; --db-text:#0f172a; --db-muted:#64748b;
    --db-radius:14px; --db-radius-sm:8px; --db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;line-height:1.6}

/* Hero */
.bps-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden}
.bps-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none}
.bps-hero__ring--1{width:320px;height:320px;top:-140px;right:-80px}
.bps-hero__ring--2{width:200px;height:200px;top:-60px;right:60px;border-color:rgba(245,158,11,.12)}
.bps-hero__ring--3{width:120px;height:120px;bottom:-50px;left:35%;border-color:rgba(13,148,136,.14)}
.bps-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
.bps-hero__left{display:flex;align-items:center;gap:18px}
.bps-hero__icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
.bps-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.4px;margin-bottom:2px}
.bps-hero__sub{font-size:13px;color:rgba(255,255,255,.55);margin:0}

/* Container */
.container-fluid{padding:0 24px 40px;max-width:1400px;margin:0 auto}

/* Alerts */
.alert{border-radius:var(--db-radius);border:none;border-left:4px solid;font-family:'Sora',sans-serif;font-size:13.5px;font-weight:500;padding:14px 18px;margin-bottom:16px;animation:dbFadeUp .3s ease both}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* Stat Cards */
.stat-card{background:var(--db-surf);border-radius:var(--db-radius);padding:20px 18px 16px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);display:flex;align-items:center;gap:14px;transition:transform .2s,box-shadow .2s;animation:dbFadeUp .35s ease both}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg)}
.stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
.stat-icon.bg-primary{background:linear-gradient(135deg,var(--db-sky),#0284c7)}
.stat-icon.bg-success{background:linear-gradient(135deg,var(--db-success),#059669)}
.stat-icon.bg-warning{background:linear-gradient(135deg,var(--db-amber),var(--db-amber-dark))}
.stat-icon.bg-info{background:linear-gradient(135deg,var(--db-teal),#0f766e)}
.stat-value{font-size:26px;font-weight:800;letter-spacing:-1px;color:var(--db-text);line-height:1;font-family:'Sora',sans-serif}
.stat-value--sm{font-size:18px;letter-spacing:0}
.stat-label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
.stat-sub{font-size:10.5px;color:var(--db-muted);margin-top:2px}

/* Cards */
.card{background:var(--db-surf);border-radius:var(--db-radius-lg) !important;border:1px solid var(--db-border) !important;box-shadow:var(--db-shadow);overflow:hidden;animation:dbFadeUp .35s ease both}
.card-header{padding:18px 22px !important;border-bottom:1px solid var(--db-border) !important;background:var(--db-surf) !important;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.card-header h5{font-size:15px;font-weight:700;color:var(--db-text);margin:0;display:flex;align-items:center;gap:8px}
.card-header h5::before{content:'';display:inline-block;width:4px;height:18px;background:linear-gradient(to bottom,var(--db-teal),var(--db-sky));border-radius:2px}
.card-header small{font-size:11.5px;color:var(--db-muted);font-family:'DM Mono',monospace}
.card-body{padding:20px 22px !important}

/* Form */
.form-label{font-size:12px;font-weight:600;color:var(--db-text);margin-bottom:5px;font-family:'Sora',sans-serif}
.form-control,.form-select{border:1.5px solid var(--db-border) !important;border-radius:var(--db-radius-sm) !important;font-family:'Sora',sans-serif !important;font-size:13px !important;color:var(--db-text) !important;background:var(--db-surf) !important;padding:9px 13px !important;transition:all .18s !important;box-shadow:none !important}
.form-control:focus,.form-select:focus{border-color:var(--db-navy-light) !important;box-shadow:0 0 0 3px rgba(28,52,97,.1) !important}

/* Buttons */
.btn{font-family:'Sora',sans-serif !important;font-weight:600 !important;border-radius:var(--db-radius-sm) !important;font-size:13px !important;transition:all .18s ease !important;display:inline-flex !important;align-items:center !important;gap:6px !important}
.btn-primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light)) !important;border-color:transparent !important;color:#fff !important}
.btn-primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0) !important;transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25) !important;color:#fff !important}
.btn-secondary{background:var(--db-surf2) !important;border-color:var(--db-border) !important;color:var(--db-text) !important}
.btn-secondary:hover{background:var(--db-border) !important;color:var(--db-text) !important}

/* Table */
.table-responsive{overflow-x:auto}
.table{width:100%;border-collapse:collapse;font-size:12.5px;font-family:'Sora',sans-serif;margin:0 !important}
.table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light))}
.table thead th{color:rgba(255,255,255,.85) !important;font-family:'DM Mono',monospace !important;font-size:10px !important;font-weight:500 !important;text-transform:uppercase !important;letter-spacing:.8px !important;padding:12px 16px !important;white-space:nowrap !important;border:none !important;background:transparent !important}
.table tbody tr{border-bottom:1px solid var(--db-border) !important;transition:background .12s}
.table tbody tr:last-child{border-bottom:none !important}
.table tbody tr:hover{background:#f0f6ff !important}
.table tbody td{padding:12px 16px !important;vertical-align:middle !important;border:none !important;color:var(--db-text) !important}
.table-foot-row td{padding:12px 16px !important;background:var(--db-surf2) !important;border-top:2px solid var(--db-border) !important;font-size:12.5px}

/* Badges */
.badge{padding:3px 10px !important;border-radius:20px !important;font-family:'DM Mono',monospace !important;font-size:10px !important;font-weight:500 !important;letter-spacing:.3px !important}
.bg-success{background:var(--db-success-light) !important;color:#065f46 !important}
.bg-secondary{background:var(--db-surf2) !important;color:var(--db-muted) !important;border:1px solid var(--db-border) !important}
.bg-warning{background:var(--db-warning-light) !important;color:#92400e !important}
.bg-info{background:var(--db-sky-light) !important;color:#0369a1 !important}
.bg-danger{background:var(--db-danger-light) !important;color:#7f1d1d !important}
.bg-primary{background:var(--db-indigo-light) !important;color:#3730a3 !important}

/* Progress bars */
.progress-bar-wrap{height:8px;background:var(--db-border);border-radius:4px;overflow:hidden;flex:1}
.progress-bar-fill{height:100%;border-radius:4px;transition:width .6s ease}
.pct-label{font-family:'DM Mono',monospace;font-size:10.5px;color:var(--db-muted);min-width:36px;flex-shrink:0}

/* Breakdown rows */
.breakdown-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--db-border)}
.breakdown-row:last-child{border-bottom:none}
.breakdown-label{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--db-text)}

/* Financial boxes */
.fin-box{padding:12px 14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);margin-bottom:10px;border-left:3px solid}
.fin-label{font-size:11px;color:var(--db-muted);margin-bottom:4px;font-weight:500;text-transform:uppercase;letter-spacing:.4px}
.fin-value{font-size:20px;font-weight:800;font-family:'DM Mono',monospace;letter-spacing:-0.5px}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--db-surf2)}
::-webkit-scrollbar-thumb{background:var(--db-border);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--db-muted)}

@media print{
    .bps-hero,form,.btn{display:none !important}
    .card{box-shadow:none !important;border:1px solid #ddd !important;page-break-inside:avoid}
}
@media(max-width:900px){.bps-hero{padding:20px;border-radius:0}.bps-hero__title{font-size:18px}.container-fluid{padding:0 14px 32px}}
</style>

<script>
function printReport() { window.print(); }
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.stat-card,.card').forEach((el,i) => {
        el.style.animationDelay = (i * 0.04) + 's';
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>