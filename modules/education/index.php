<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Education Assistance';

$stats_sql = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN scholarship_status = 'active' THEN 1 ELSE 0 END) as active_scholars,
    SUM(CASE WHEN assistance_status = 'pending' THEN 1 ELSE 0 END) as pending_assistance,
    COUNT(DISTINCT student_id) as unique_students
    FROM tbl_education_students";
$stats = fetchOne($conn, $stats_sql);

$recent_sql = "SELECT es.*, r.first_name, r.last_name, r.contact_number
               FROM tbl_education_students es
               LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id
               ORDER BY es.application_date DESC
               LIMIT 10";
$recent_applications = fetchAll($conn, $recent_sql);

$scholarship_sql = "SELECT scholarship_type, COUNT(*) as count, SUM(scholarship_amount) as total_amount
                    FROM tbl_education_students
                    WHERE scholarship_status = 'active'
                    GROUP BY scholarship_type";
$scholarship_summary = fetchAll($conn, $scholarship_sql);

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                <i class="fas fa-graduation-cap" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Education Assistance Management</h1>
                <p class="db-hero__sub">Manage student scholarships and educational support programs</p>
            </div>
        </div>
        <div class="db-hero__right">
            <div style="display:flex;gap:8px;">
                <a href="add-student.php" class="db-btn db-btn--primary db-btn--sm">
                    <i class="fas fa-user-plus"></i> Add Student
                </a>
                <a href="reports.php" class="db-btn db-btn--ghost db-btn--sm"
                   style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                    <i class="fas fa-chart-bar"></i> Reports
                </a>
            </div>
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


<!-- STATS ROW -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-user-graduate"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $stats['total_students'] ?? 0; ?></div>
            <div class="db-stat-card__label">Total Students</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-certificate"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $stats['active_scholars'] ?? 0; ?></div>
            <div class="db-stat-card__label">Active Scholars</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $stats['pending_assistance'] ?? 0; ?></div>
            <div class="db-stat-card__label">Pending Assistance</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $stats['unique_students'] ?? 0; ?></div>
            <div class="db-stat-card__label">Unique Students</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
</div>


<!-- MAIN GRID -->
<div class="db-grid">

    <!-- LEFT / MAIN -->
    <div class="db-grid__main">

        <!-- Recent Applications -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-file-alt"></i></span>
                    <h2>Recent Applications</h2>
                </div>
                <a href="manage-students.php" class="db-btn db-btn--primary db-btn--sm">
                    <i class="fas fa-list"></i> View All
                </a>
            </div>

            <?php if (empty($recent_applications)): ?>
            <div class="db-empty">
                <i class="fas fa-inbox"></i>
                <p>No applications yet. They will appear here once students apply.</p>
            </div>
            <?php else: ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>School</th>
                            <th>Grade</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_applications as $app):
                        $sstatus = $app['scholarship_status'] ?? 'pending';
                        $badge_map = ['pending'=>'db-badge--warning','active'=>'db-badge--success','rejected'=>'db-badge--danger','expired'=>'db-badge--muted'];
                        $label_map = ['pending'=>'Pending','active'=>'Active Scholar','rejected'=>'Rejected','expired'=>'Expired'];
                        $sbadge = $badge_map[$sstatus] ?? 'db-badge--muted';
                        $slabel = $label_map[$sstatus] ?? ucfirst($sstatus);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></strong><br>
                            <span class="db-text-sm"><i class="fas fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($app['contact_number'] ?? 'N/A'); ?></span>
                        </td>
                        <td><span class="db-text-sm"><?php echo htmlspecialchars($app['school_name']); ?></span></td>
                        <td><span class="db-badge db-badge--primary"><?php echo htmlspecialchars($app['grade_level']); ?></span></td>
                        <td>
                            <?php if ($sstatus === 'active'): ?>
                                <span class="db-badge db-badge--success">Scholarship</span>
                            <?php elseif (($app['assistance_status'] ?? '') === 'approved'): ?>
                                <span class="db-badge db-badge--info">Assistance</span>
                            <?php else: ?>
                                <span class="db-badge db-badge--muted">General</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="db-badge <?php echo $sbadge; ?>"><?php echo $slabel; ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($app['application_date'])); ?></span></td>
                        <td>
                            <a href="view-student.php?id=<?php echo $app['student_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer">
                <a href="manage-students.php" class="db-btn db-btn--outline db-btn--sm">View All Students</a>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /main -->


    <!-- RIGHT SIDEBAR -->
    <div class="db-grid__side">

        <!-- Quick Actions -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bolt"></i></span>
                    <h2>Quick Actions</h2>
                </div>
            </div>
            <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
                <?php
                $links = [
                    ['manage-students.php',   'fa-users',             'blue',  'Manage Students'],
                    ['scholarships.php',       'fa-award',             'amber', 'Scholarship Programs'],
                    ['assistance-requests.php','fa-hand-holding-usd',  'teal',  'Assistance Requests'],
                    ['student-records.php',    'fa-folder-open',       'indigo','Student Records'],
                ];
                foreach ($links as [$href, $icon, $color, $label]):
                    $bg = ['blue'=>'var(--db-sky)','amber'=>'var(--db-amber)','teal'=>'var(--db-teal)','indigo'=>'var(--db-indigo)'][$color];
                ?>
                <a href="<?php echo $href; ?>"
                   style="display:flex;align-items:center;justify-content:space-between;padding:13px 16px;background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);text-decoration:none;color:var(--db-text);font-weight:600;font-size:13px;transition:all .2s;"
                   onmouseover="this.style.borderColor='var(--db-navy-light)';this.style.background='#f0f4ff';"
                   onmouseout="this.style.borderColor='var(--db-border)';this.style.background='var(--db-surf2)';">
                    <span style="display:flex;align-items:center;gap:10px;">
                        <span class="db-panel__icon db-panel__icon--<?php echo $color; ?>" style="width:28px;height:28px;font-size:11px;flex-shrink:0;">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </span>
                        <?php echo $label; ?>
                    </span>
                    <i class="fas fa-chevron-right" style="font-size:11px;color:var(--db-muted);"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Scholarship Summary -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-chart-pie"></i></span>
                    <h2>Scholarship Summary</h2>
                </div>
            </div>
            <?php if (empty($scholarship_summary)): ?>
            <div class="db-empty db-empty--sm">
                <i class="fas fa-award"></i>
                <p>No active scholarships yet</p>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;">
                <?php foreach ($scholarship_summary as $i => $s): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;<?php echo $i < count($scholarship_summary)-1 ? 'border-bottom:1px solid var(--db-border);' : ''; ?>
                            transition:background .15s;"
                     onmouseover="this.style.background='var(--db-surf2)'"
                     onmouseout="this.style.background=''">
                    <div>
                        <strong style="font-size:13px;display:block;"><?php echo htmlspecialchars($s['scholarship_type'] ?? 'N/A'); ?></strong>
                        <span class="db-text-sm"><i class="fas fa-users" style="font-size:10px;"></i> <?php echo $s['count']; ?> scholars</span>
                    </div>
                    <strong style="color:var(--db-teal);font-size:13px;">₱<?php echo number_format($s['total_amount'], 2); ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /sidebar -->
</div><!-- /grid -->

<script>
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../includes/footer.php'; ?>