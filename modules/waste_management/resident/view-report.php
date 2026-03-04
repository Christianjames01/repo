<?php
require_once('../../../config/config.php');
requireLogin();

$page_title = "View Report Details";

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('Invalid report ID', 'danger');
    header('Location: my-reports.php');
    exit();
}

$issue_id = (int)$_GET['id'];
$user_id  = $_SESSION['user_id'];

$report = fetchOne($conn,
    "SELECT issue_id, reporter_id, reporter_name, reporter_contact, issue_type,
            location, description, urgency, status, photo_path, created_at
     FROM tbl_waste_issues
     WHERE issue_id = ? AND reporter_id = ?",
    [$issue_id, $user_id], 'ii'
);

if (!$report) {
    setMessage('Report not found or you do not have permission to view it.', 'danger');
    header('Location: my-reports.php');
    exit();
}

$extra_css = '<link rel="stylesheet" href="../../../assets/css/waste-pages.css?v=' . time() . '">';
require_once '../../../includes/header.php';

// Urgency config
$urg_cfg = [
    'low'      => ['wp-badge--success', 'fa-circle',           'Low',      'Can wait a few days'],
    'medium'   => ['wp-badge--warning', 'fa-exclamation-circle','Medium',  'Needs attention soon'],
    'high'     => ['wp-badge--danger',  'fa-exclamation-triangle','High',  'Urgent attention required'],
    'critical' => ['wp-badge--dark',    'fa-skull-crossbones', 'Critical', 'Immediate action needed'],
];
$urg = $urg_cfg[strtolower(trim($report['urgency']))] ?? ['wp-badge--muted','fa-circle','Unknown',''];

// Status config
$st_cfg = [
    'pending'     => ['wp-badge--warning', 'fa-clock',         'Pending'],
    'in progress' => ['wp-badge--info',    'fa-spinner',       'In Progress'],
    'resolved'    => ['wp-badge--success', 'fa-check-circle',  'Resolved'],
    'closed'      => ['wp-badge--muted',   'fa-archive',       'Closed'],
];
$st = $st_cfg[strtolower(trim($report['status']))] ?? ['wp-badge--muted','fa-circle', ucfirst($report['status'])];

// Timeline state
$status_lc = strtolower(trim($report['status']));
?>

<!-- ── PAGE HERO ── -->
<div class="wp-hero">
    <div class="wp-hero__ring wp-hero__ring--1"></div>
    <div class="wp-hero__ring wp-hero__ring--2"></div>
    <div class="wp-hero__ring wp-hero__ring--3"></div>
    <div class="wp-hero__inner">
        <div class="wp-hero__left">
            <div class="wp-hero__icon wp-hero__icon--indigo">
                <i class="fas fa-file-alt"></i>
            </div>
            <div>
                <h1 class="wp-hero__title">Report <span class="wp-mono" style="color:var(--db-amber)">#<?php echo $report['issue_id']; ?></span></h1>
                <p class="wp-hero__sub"><?php echo htmlspecialchars($report['issue_type']); ?> — <?php echo date('F j, Y', strtotime($report['created_at'])); ?></p>
            </div>
        </div>
        <div class="wp-hero__actions">
            <span class="wp-badge <?php echo $st[0]; ?>" style="padding:7px 14px;font-size:12px">
                <i class="fas <?php echo $st[1]; ?>"></i> <?php echo $st[2]; ?>
            </span>
            <a href="my-reports.php" class="wp-btn wp-btn--ghost">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<?php if ($msg = displayMessage()): ?>
<div style="margin-bottom:16px"><?php echo $msg; ?></div>
<?php endif; ?>

<div class="wp-grid">

    <!-- ── LEFT: MAIN DETAILS ── -->
    <div>

        <!-- Issue Info Panel -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--indigo"><i class="fas fa-info-circle"></i></span>
                    <h2>Issue Details</h2>
                </div>
                <div style="display:flex;gap:6px">
                    <span class="wp-badge <?php echo $st[0]; ?>"><i class="fas <?php echo $st[1]; ?>"></i> <?php echo $st[2]; ?></span>
                    <span class="wp-badge <?php echo $urg[0]; ?>"><i class="fas <?php echo $urg[1]; ?>"></i> <?php echo $urg[2]; ?></span>
                </div>
            </div>
            <div class="wp-panel__body" style="padding:0">
                <!-- Detail Rows -->
                <div style="padding:0 22px">
                    <div class="wp-detail-row">
                        <div class="wp-detail-row__icon" style="background:var(--db-amber-light);color:var(--db-amber-dark)">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div style="flex:1">
                            <div class="wp-detail-row__label">Issue Type</div>
                            <div class="wp-detail-row__val"><?php echo htmlspecialchars($report['issue_type']); ?></div>
                        </div>
                    </div>
                    <div class="wp-detail-row">
                        <div class="wp-detail-row__icon" style="background:var(--db-rose-light);color:var(--db-rose)">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div style="flex:1">
                            <div class="wp-detail-row__label">Location</div>
                            <div class="wp-detail-row__val"><?php echo htmlspecialchars($report['location']); ?></div>
                        </div>
                    </div>
                    <div class="wp-detail-row">
                        <div class="wp-detail-row__icon" style="background:var(--db-sky-light);color:var(--db-sky)">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <div style="flex:1">
                            <div class="wp-detail-row__label">Urgency</div>
                            <div class="wp-detail-row__val">
                                <span class="wp-badge <?php echo $urg[0]; ?>" style="margin-right:6px"><i class="fas <?php echo $urg[1]; ?>"></i> <?php echo $urg[2]; ?></span>
                                <span style="font-size:12px;color:var(--db-muted);font-weight:400"><?php echo $urg[3]; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="wp-detail-row">
                        <div class="wp-detail-row__icon" style="background:var(--db-info-light);color:var(--db-info)">
                            <i class="far fa-calendar"></i>
                        </div>
                        <div style="flex:1">
                            <div class="wp-detail-row__label">Date Reported</div>
                            <div class="wp-detail-row__val">
                                <?php echo date('F j, Y \a\t g:i A', strtotime($report['created_at'])); ?>
                                <span style="font-size:11px;color:var(--db-muted);margin-left:6px">(<?php echo timeAgo($report['created_at']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div style="padding:18px 22px;background:var(--db-surf2);border-top:1px solid var(--db-border)">
                    <p style="font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">
                        <i class="fas fa-align-left" style="margin-right:5px"></i>Description
                    </p>
                    <p style="font-size:13.5px;line-height:1.8;color:var(--db-text);margin:0"><?php echo nl2br(htmlspecialchars($report['description'])); ?></p>
                </div>
            </div>
        </div>

        <!-- Photo Panel -->
        <?php if (!empty($report['photo_path'])): ?>
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--amber"><i class="fas fa-camera"></i></span>
                    <h2>Photo Evidence</h2>
                </div>
                <a href="../../../<?php echo htmlspecialchars($report['photo_path']); ?>" download class="wp-btn wp-btn--ghost wp-btn--sm">
                    <i class="fas fa-download"></i> Download
                </a>
            </div>
            <div class="wp-panel__body">
                <div class="wp-photo-wrap" onclick="openImgModal('../../../<?php echo htmlspecialchars($report['photo_path']); ?>')">
                    <img src="../../../<?php echo htmlspecialchars($report['photo_path']); ?>" alt="Issue Photo">
                </div>
                <p style="font-size:11.5px;color:var(--db-muted);margin-top:8px;text-align:center">
                    <i class="fas fa-search-plus" style="margin-right:4px"></i>Click to enlarge
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status Banner -->
        <?php if ($status_lc === 'resolved'): ?>
        <div class="wp-panel" style="border-color:var(--db-success);overflow:hidden">
            <div style="background:linear-gradient(135deg,var(--db-success),#059669);padding:18px 22px;display:flex;align-items:center;gap:14px">
                <div style="width:44px;height:44px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#fff">Issue Resolved</div>
                    <div style="font-size:12.5px;color:rgba(255,255,255,.75)">This issue has been resolved by our team. Thank you for helping keep our barangay clean!</div>
                </div>
            </div>
        </div>
        <?php elseif ($status_lc === 'in progress'): ?>
        <div class="wp-panel" style="border-color:var(--db-info);overflow:hidden">
            <div style="background:linear-gradient(135deg,var(--db-info),#2563eb);padding:18px 22px;display:flex;align-items:center;gap:14px">
                <div style="width:44px;height:44px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0">
                    <i class="fas fa-cog fa-spin"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:700;color:#fff">In Progress</div>
                    <div style="font-size:12.5px;color:rgba(255,255,255,.75)">Our waste management team is actively addressing your reported issue.</div>
                </div>
            </div>
        </div>
        <?php elseif ($status_lc === 'closed'): ?>
        <div class="wp-info-box">
            <div class="wp-info-box__title"><i class="fas fa-archive"></i> Report Closed</div>
            This report has been closed. If you still have concerns, please submit a new report.
        </div>
        <?php endif; ?>

        <!-- Timeline Panel -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--teal"><i class="fas fa-history"></i></span>
                    <h2>Report Timeline</h2>
                </div>
            </div>
            <div class="wp-timeline">
                <div class="wp-timeline__item wp-timeline__item--submitted">
                    <div class="wp-timeline__dot"></div>
                    <div class="wp-timeline__card">
                        <div class="wp-timeline__title"><i class="fas fa-flag" style="margin-right:6px"></i>Report Submitted</div>
                        <div class="wp-timeline__meta"><?php echo date('F j, Y \a\t g:i A', strtotime($report['created_at'])); ?> · <?php echo timeAgo($report['created_at']); ?></div>
                    </div>
                </div>

                <?php if (in_array($status_lc, ['in progress','resolved','closed'])): ?>
                <div class="wp-timeline__item wp-timeline__item--progress">
                    <div class="wp-timeline__dot"></div>
                    <div class="wp-timeline__card">
                        <div class="wp-timeline__title"><i class="fas fa-cog" style="margin-right:6px"></i>Report Under Review</div>
                        <div class="wp-timeline__meta">Reviewed and assigned to waste management team</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($status_lc === 'in progress'): ?>
                <div class="wp-timeline__item wp-timeline__item--progress">
                    <div class="wp-timeline__dot"></div>
                    <div class="wp-timeline__card">
                        <div class="wp-timeline__title"><i class="fas fa-tools" style="margin-right:6px"></i>In Progress</div>
                        <div class="wp-timeline__meta">Issue is being actively addressed by our team</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($status_lc === 'resolved'): ?>
                <div class="wp-timeline__item wp-timeline__item--resolved">
                    <div class="wp-timeline__dot"></div>
                    <div class="wp-timeline__card">
                        <div class="wp-timeline__title"><i class="fas fa-check-circle" style="margin-right:6px"></i>Issue Resolved</div>
                        <div class="wp-timeline__meta">The reported issue has been successfully resolved</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($status_lc === 'closed'): ?>
                <div class="wp-timeline__item wp-timeline__item--closed">
                    <div class="wp-timeline__dot"></div>
                    <div class="wp-timeline__card">
                        <div class="wp-timeline__title"><i class="fas fa-archive" style="margin-right:6px"></i>Report Closed</div>
                        <div class="wp-timeline__meta">This report has been archived</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── RIGHT SIDEBAR ── -->
    <div>

        <!-- Quick Info -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--indigo"><i class="fas fa-tag"></i></span>
                    <h2>Report Summary</h2>
                </div>
            </div>
            <div class="wp-panel__body" style="display:flex;flex-direction:column;gap:12px">
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:12px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px">Report ID</span>
                    <span class="wp-id" style="font-size:14px">#<?php echo $report['issue_id']; ?></span>
                </div>
                <div style="height:1px;background:var(--db-border)"></div>
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:12px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px">Status</span>
                    <span class="wp-badge <?php echo $st[0]; ?>"><i class="fas <?php echo $st[1]; ?>"></i> <?php echo $st[2]; ?></span>
                </div>
                <div style="height:1px;background:var(--db-border)"></div>
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:12px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px">Urgency</span>
                    <span class="wp-badge <?php echo $urg[0]; ?>"><i class="fas <?php echo $urg[1]; ?>"></i> <?php echo $urg[2]; ?></span>
                </div>
                <div style="height:1px;background:var(--db-border)"></div>
                <div>
                    <span style="font-size:12px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px">Date Reported</span>
                    <span style="font-size:13px;font-family:'DM Mono',monospace"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></span>
                    <span style="font-size:11px;color:var(--db-muted);display:block"><?php echo timeAgo($report['created_at']); ?></span>
                </div>
            </div>
        </div>

        <!-- Reporter Info -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--teal"><i class="fas fa-user"></i></span>
                    <h2>Reporter Info</h2>
                </div>
            </div>
            <div class="wp-panel__body" style="display:flex;flex-direction:column;gap:10px">
                <div class="wp-detail-row" style="border:none;padding:0">
                    <div class="wp-detail-row__icon" style="background:var(--db-sky-light);color:var(--db-sky)">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <div>
                        <div class="wp-detail-row__label">Name</div>
                        <div class="wp-detail-row__val"><?php echo htmlspecialchars($report['reporter_name']); ?></div>
                    </div>
                </div>
                <div class="wp-detail-row" style="border:none;padding:0">
                    <div class="wp-detail-row__icon" style="background:var(--db-success-light);color:var(--db-success)">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div>
                        <div class="wp-detail-row__label">Contact</div>
                        <div class="wp-detail-row__val"><?php echo htmlspecialchars($report['reporter_contact']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- What's Next -->
        <div class="wp-panel">
            <div class="wp-panel__header">
                <div class="wp-panel__title">
                    <span class="wp-panel__icon wp-panel__icon--amber"><i class="fas fa-question-circle"></i></span>
                    <h2>What's Next?</h2>
                </div>
            </div>
            <div class="wp-panel__body" style="display:flex;flex-direction:column;gap:8px">
                <?php if ($status_lc === 'pending'): ?>
                <div class="wp-info-box">
                    <div class="wp-info-box__title"><i class="fas fa-clock"></i> Awaiting Review</div>
                    <ul style="margin:0;padding-left:16px;font-size:12.5px">
                        <li>Your report is being reviewed by our team</li>
                        <li>We typically respond within 24–48 hours</li>
                        <li>You'll see status updates on this page</li>
                    </ul>
                </div>
                <?php elseif ($status_lc === 'in progress'): ?>
                <div class="wp-info-box">
                    <div class="wp-info-box__title"><i class="fas fa-cog"></i> Being Addressed</div>
                    <ul style="margin:0;padding-left:16px;font-size:12.5px">
                        <li>Your issue is actively being worked on</li>
                        <li>Our team is on location or scheduling a visit</li>
                        <li>You'll be notified once resolved</li>
                    </ul>
                </div>
                <?php elseif ($status_lc === 'resolved'): ?>
                <div class="wp-info-box" style="background:var(--db-success);opacity:.95">
                    <div class="wp-info-box__title" style="color:#fff"><i class="fas fa-check-circle"></i> All Done!</div>
                    <p style="font-size:12.5px;margin:0;color:rgba(255,255,255,.85)">Thank you for helping keep our barangay clean and safe.</p>
                </div>
                <?php elseif ($status_lc === 'closed'): ?>
                <div class="wp-info-box">
                    <div class="wp-info-box__title"><i class="fas fa-archive"></i> Closed</div>
                    <p style="font-size:12.5px;margin:0">If you still have concerns, please submit a new report.</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="wp-panel__footer">
                <div style="font-size:12px;color:var(--db-muted)">
                    <div><i class="fas fa-phone-alt" style="color:var(--db-success);margin-right:6px"></i><strong>(123) 456-7890</strong></div>
                    <div style="margin-top:2px"><i class="fas fa-envelope" style="color:var(--db-info);margin-right:6px"></i>waste@barangay.gov.ph</div>
                </div>
            </div>
        </div>

        <a href="report-issue.php" class="wp-btn wp-btn--primary" style="width:100%;justify-content:center;margin-top:4px">
            <i class="fas fa-plus"></i> Report Another Issue
        </a>
    </div>
</div>

<!-- ── IMAGE MODAL ── -->
<div id="imgModal" class="wp-modal" onclick="if(event.target===this)closeImgModal()">
    <div class="wp-modal__img-wrap">
        <button class="wp-modal__close" onclick="closeImgModal()"><i class="fas fa-times"></i></button>
        <img id="modalImg" src="" alt="Photo Evidence">
    </div>
</div>

<script>
function openImgModal(src) {
    document.getElementById('modalImg').src = src;
    document.getElementById('imgModal').classList.add('wp-modal--open');
    document.body.style.overflow = 'hidden';
}
function closeImgModal() {
    document.getElementById('imgModal').classList.remove('wp-modal--open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeImgModal(); });
</script>

<?php require_once '../../../includes/footer.php'; ?>