<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = null;

if ($user_role === 'Resident') {
    $user_id = getCurrentUserId();
    $user_sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
    $user_data = fetchOne($conn, $user_sql, [$user_id], 'i');
    $resident_id = $user_data['resident_id'] ?? null;
}

$page_title = 'Student Portal';

$my_records_sql = "SELECT * FROM tbl_education_students WHERE resident_id = ? ORDER BY created_at DESC";
$my_records = $resident_id ? fetchAll($conn, $my_records_sql, [$resident_id], 'i') : [];

$scholarships_sql = "SELECT * FROM tbl_education_scholarships 
                     WHERE status = 'active' 
                     AND (application_end IS NULL OR application_end >= CURDATE())
                     ORDER BY created_at DESC";
$scholarships = fetchAll($conn, $scholarships_sql);

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                <i class="fas fa-user-graduate" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-resident">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Student Portal</h1>
                <p class="db-hero__sub">Manage your scholarship applications and educational assistance</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="apply-scholarship.php" class="db-btn db-btn--primary db-btn--sm">
                <i class="fas fa-plus"></i> Apply for Scholarship
            </a>
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


<!-- Welcome banner (only when no records) -->
<?php if (empty($my_records)): ?>
<div style="background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #224090 100%);
            border-radius: var(--db-radius-lg); padding: 32px 36px; margin-bottom: 24px;
            display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap;
            position:relative; overflow:hidden;">
    <div style="position:absolute;width:260px;height:260px;border-radius:50%;border:1px solid rgba(255,255,255,.06);top:-100px;right:-40px;"></div>
    <div style="position:relative;z-index:1;">
        <h3 style="color:#fff;font-size:20px;font-weight:800;margin-bottom:8px;">Welcome to the Student Portal!</h3>
        <p style="color:rgba(255,255,255,.6);margin:0;font-size:13.5px;">Apply for scholarships, track applications, and manage documents — all in one place.</p>
    </div>
    <a href="apply-scholarship.php" class="db-btn db-btn--primary" style="position:relative;z-index:1;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;flex-shrink:0;">
        <i class="fas fa-graduation-cap"></i> Apply Now
    </a>
</div>
<?php endif; ?>


<!-- STATS ROW -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-file-alt"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo count($my_records); ?></div>
            <div class="db-stat-card__label">My Applications</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
    <?php
    $active_count  = count(array_filter($my_records, fn($r) => $r['scholarship_status'] === 'active'));
    $pending_count = count(array_filter($my_records, fn($r) => $r['scholarship_status'] === 'pending'));
    ?>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-check-circle"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $active_count; ?></div>
            <div class="db-stat-card__label">Active Scholarships</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo $pending_count; ?></div>
            <div class="db-stat-card__label">Pending Review</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-award"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo count($scholarships); ?></div>
            <div class="db-stat-card__label">Available Scholarships</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
</div>


<!-- MAIN GRID -->
<div class="db-grid">

    <!-- LEFT / MAIN -->
    <div class="db-grid__main">

        <!-- My Applications -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-file-alt"></i></span>
                    <h2>My Applications</h2>
                </div>
                <a href="apply-scholarship.php" class="db-btn db-btn--primary db-btn--sm">
                    <i class="fas fa-plus"></i> New Application
                </a>
            </div>

            <?php if (empty($my_records)): ?>
            <div class="db-empty">
                <i class="fas fa-inbox"></i>
                <p>You haven't submitted any applications yet.</p>
                <a href="apply-scholarship.php" class="db-btn db-btn--primary db-btn--sm">
                    <i class="fas fa-plus"></i> Submit Your First Application
                </a>
            </div>
            <?php else: ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>School</th>
                            <th>Level / Course</th>
                            <th>Scholarship</th>
                            <th>Applied</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($my_records as $record):
                        $status = $record['scholarship_status'];
                        $badge_map = [
                            'pending'  => 'db-badge--warning',
                            'active'   => 'db-badge--success',
                            'rejected' => 'db-badge--danger',
                            'expired'  => 'db-badge--muted',
                        ];
                        $label_map = [
                            'pending'  => 'Pending Review',
                            'active'   => 'Active Scholar',
                            'rejected' => 'Rejected',
                            'expired'  => 'Expired',
                        ];
                        $icon_map = [
                            'pending'  => 'fa-clock',
                            'active'   => 'fa-check-circle',
                            'rejected' => 'fa-times-circle',
                            'expired'  => 'fa-hourglass-end',
                        ];
                        $badge_cls = $badge_map[$status]  ?? 'db-badge--muted';
                        $label     = $label_map[$status]  ?? ucfirst($status);
                        $icon      = $icon_map[$status]   ?? 'fa-circle';
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($record['school_name']); ?></strong></td>
                        <td>
                            <span class="db-badge db-badge--primary"><?php echo htmlspecialchars($record['grade_level']); ?></span>
                            <?php if ($record['course']): ?>
                            <br><span class="db-text-sm"><?php echo htmlspecialchars($record['course']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($record['scholarship_type']): ?>
                                <?php echo htmlspecialchars($record['scholarship_type']); ?>
                                <?php if ($record['scholarship_amount'] > 0): ?>
                                <br><span class="db-text-sm" style="color:var(--db-teal);">₱<?php echo number_format($record['scholarship_amount'], 2); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="db-text-muted">General</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($record['application_date'])); ?></span></td>
                        <td><span class="db-badge <?php echo $badge_cls; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo $label; ?></span></td>
                        <td>
                            <a href="view-application.php?id=<?php echo $record['student_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>

                    <?php if ($status === 'active'): ?>
                    <tr style="background:var(--db-surf2);">
                        <td colspan="6" style="padding:0 16px 14px;">
                            <div style="display:flex;gap:24px;flex-wrap:wrap;padding-top:10px;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="width:10px;height:10px;background:var(--db-success);border-radius:50%;display:inline-block;"></span>
                                    <span style="font-size:12px;color:var(--db-muted);">Approved:
                                        <strong style="color:var(--db-text);"><?php echo date('M d, Y', strtotime($record['approval_date'])); ?></strong>
                                    </span>
                                </div>
                                <?php if ($record['scholarship_start_date']): ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="width:10px;height:10px;background:var(--db-sky);border-radius:50%;display:inline-block;"></span>
                                    <span style="font-size:12px;color:var(--db-muted);">Active:
                                        <strong style="color:var(--db-text);"><?php echo date('M d, Y', strtotime($record['scholarship_start_date'])); ?></strong>
                                        <?php if ($record['scholarship_end_date']): ?>
                                        – <?php echo date('M d, Y', strtotime($record['scholarship_end_date'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /main -->


    <!-- RIGHT SIDEBAR -->
    <div class="db-grid__side">

        <!-- Quick Links -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-link"></i></span>
                    <h2>Quick Links</h2>
                </div>
            </div>
            <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
                <a href="request-assistance.php" class="db-quicklink-card" style="flex:unset;">
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Request Assistance</span>
                    <i class="fas fa-arrow-right db-quicklink-card__arrow"></i>
                </a>
                <a href="my-documents.php" class="db-quicklink-card db-quicklink-card--amber" style="flex:unset;">
                    <i class="fas fa-folder-open"></i>
                    <span>My Documents</span>
                    <i class="fas fa-arrow-right db-quicklink-card__arrow"></i>
                </a>
                <a href="scholarship-guide.php" class="db-quicklink-card" style="flex:unset;background:linear-gradient(135deg,#0d9488,#0f766e);">
                    <i class="fas fa-book"></i>
                    <span>Scholarship Guide</span>
                    <i class="fas fa-arrow-right db-quicklink-card__arrow"></i>
                </a>
            </div>
        </div>

        <!-- Available Scholarships -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-award"></i></span>
                    <h2>Available Scholarships</h2>
                </div>
                <?php if (!empty($scholarships)): ?>
                <span class="db-badge db-badge--success"><?php echo count($scholarships); ?> open</span>
                <?php endif; ?>
            </div>

            <?php if (empty($scholarships)): ?>
            <div class="db-empty db-empty--sm">
                <i class="fas fa-award"></i>
                <p>No active scholarships at the moment</p>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:0;">
                <?php foreach ($scholarships as $i => $scholarship): ?>
                <div style="padding:14px 20px;<?php echo $i < count($scholarships)-1 ? 'border-bottom:1px solid var(--db-border);' : ''; ?>">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px;">
                        <strong style="font-size:13px;"><?php echo htmlspecialchars($scholarship['scholarship_name']); ?></strong>
                        <span class="db-badge db-badge--success" style="flex-shrink:0;">₱<?php echo number_format($scholarship['amount'], 2); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                        <?php if ($scholarship['slots']): ?>
                        <span class="db-badge db-badge--info"><i class="fas fa-users"></i> <?php echo $scholarship['slots']; ?> slots</span>
                        <?php endif; ?>
                        <?php if ($scholarship['application_end']): ?>
                        <span class="db-badge db-badge--muted"><i class="far fa-clock"></i> Until <?php echo date('M d', strtotime($scholarship['application_end'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="apply-scholarship.php?type=<?php echo $scholarship['scholarship_id']; ?>" class="db-btn db-btn--primary db-btn--sm db-btn--full">
                        <i class="fas fa-paper-plane"></i> Apply Now
                    </a>
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

<?php $conn->close(); include '../../../includes/footer.php'; ?>