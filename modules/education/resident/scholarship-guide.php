<?php
require_once '../../../config/config.php';

requireLogin();
$page_title = 'Scholarship Guide';

$scholarships_sql = "SELECT * FROM tbl_education_scholarships WHERE status = 'active' ORDER BY scholarship_name";
$active_scholarships = fetchAll($conn, $scholarships_sql);

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
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-book-open" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-resident">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Scholarship Application Guide</h1>
                <p class="db-hero__sub">Everything you need to know about applying for barangay scholarships</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="student-portal.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back to Portal
            </a>
        </div>
    </div>
</div>


<!-- QUICK STATS -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue"><i class="fas fa-users"></i></div>
        <div>
            <div class="db-stat-card__num">All</div>
            <div class="db-stat-card__label">Open to Residents</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-hand-holding-usd"></i></div>
        <div>
            <div class="db-stat-card__num">Varies</div>
            <div class="db-stat-card__label">Financial Support</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div>
            <div class="db-stat-card__num">5–10</div>
            <div class="db-stat-card__label">Days Processing</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-award"></i></div>
        <div>
            <div class="db-stat-card__num"><?php echo count($active_scholarships); ?></div>
            <div class="db-stat-card__label">Active Programs</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
    </div>
</div>


<!-- MAIN GRID -->
<div class="db-grid">

    <!-- LEFT / MAIN -->
    <div class="db-grid__main">

        <!-- Overview -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-info-circle"></i></span>
                    <h2>Overview</h2>
                </div>
            </div>
            <div style="padding:18px 22px;">
                <p style="font-size:13.5px;color:var(--db-muted);line-height:1.8;margin:0;">
                    The Barangay Education Assistance Program aims to support deserving students in pursuing their education by providing financial assistance for tuition fees, school supplies, and other educational needs.
                </p>
            </div>
        </div>

        <!-- Application Process -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list-ol"></i></span>
                    <h2>Application Process</h2>
                </div>
            </div>
            <div style="padding:18px 22px;display:flex;flex-direction:column;gap:10px;">
                <?php
                $steps = [
                    ['num'=>1, 'color'=>'blue',   'icon'=>'fa-user-check',      'title'=>'Check Eligibility',         'desc'=>'Ensure you meet all the eligibility requirements before applying.'],
                    ['num'=>2, 'color'=>'amber',   'icon'=>'fa-file-alt',        'title'=>'Prepare Documents',         'desc'=>'Gather all required documents listed in the requirements section.'],
                    ['num'=>3, 'color'=>'indigo',  'icon'=>'fa-edit',            'title'=>'Submit Online Application', 'desc'=>'Fill out the online application form completely and accurately.', 'link'=>'apply-scholarship.php'],
                    ['num'=>4, 'color'=>'teal',    'icon'=>'fa-cloud-upload-alt','title'=>'Upload Documents',          'desc'=>'Upload scanned copies of required documents through the portal.'],
                    ['num'=>5, 'color'=>'rose',    'icon'=>'fa-search',          'title'=>'Wait for Evaluation',       'desc'=>'Your application will be reviewed within 5–10 working days.'],
                    ['num'=>6, 'color'=>'blue',    'icon'=>'fa-bell',            'title'=>'Receive Notification',      'desc'=>"You'll be notified of the decision via SMS, email, or through the portal."],
                ];
                foreach ($steps as $step): ?>
                <div style="display:flex;align-items:flex-start;gap:16px;padding:16px;border-radius:var(--db-radius);border:1px solid var(--db-border);background:var(--db-surf2);transition:box-shadow .2s,transform .2s;"
                     onmouseover="this.style.boxShadow='var(--db-shadow)';this.style.transform='translateX(2px)'"
                     onmouseout="this.style.boxShadow='';this.style.transform=''">
                    <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px;flex-shrink:0;">
                        <?php echo $step['num']; ?>
                    </div>
                    <div class="db-panel__icon db-panel__icon--<?php echo $step['color']; ?>" style="width:36px;height:36px;font-size:14px;flex-shrink:0;">
                        <i class="fas <?php echo $step['icon']; ?>"></i>
                    </div>
                    <div style="flex:1;">
                        <strong style="font-size:13.5px;"><?php echo $step['title']; ?></strong>
                        <p style="margin:4px 0 0;font-size:12.5px;color:var(--db-muted);line-height:1.6;"><?php echo $step['desc']; ?></p>
                        <?php if (!empty($step['link'])): ?>
                        <a href="<?php echo $step['link']; ?>" class="db-btn db-btn--primary db-btn--sm" style="margin-top:8px;">
                            <i class="fas fa-external-link-alt"></i> Go to Application Form
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Required Documents -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-file-alt"></i></span>
                    <h2>Required Documents</h2>
                </div>
                <span class="db-badge db-badge--warning">Checklist</span>
            </div>
            <div style="padding:18px 22px;">
                <?php
                $docs = [
                    ['Certificate of Enrollment',  'Current semester / school year'],
                    ['Latest Report Card',          'Previous semester grades'],
                    ['Good Moral Certificate',      'From previous school'],
                    ['Birth Certificate',           'NSO/PSA certified copy'],
                    ['Barangay Clearance',          'Issued within the last 3 months'],
                    ['Certificate of Indigency',    'For financial assistance applicants'],
                    ['2x2 ID Pictures',             'Recent, white background — 2 copies'],
                    ["Parent's / Guardian's ID",    'Valid government-issued ID'],
                ];
                foreach ($docs as [$name, $note]): ?>
                <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--db-border);">
                    <i class="fas fa-check-circle" style="color:var(--db-success);font-size:15px;flex-shrink:0;"></i>
                    <div>
                        <strong style="font-size:13px;"><?php echo $name; ?></strong>
                        <span style="color:var(--db-muted);font-size:12px;margin-left:8px;"><?php echo $note; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="db-alert db-alert--success" style="margin-top:14px;margin-bottom:0;">
                    <div class="db-alert__icon"><i class="fas fa-info-circle"></i></div>
                    <span style="font-size:12.5px;">All documents should be clear, legible scans or photos. Originals may be required for verification.</span>
                </div>
            </div>
        </div>

        <!-- Eligibility Criteria -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-user-check"></i></span>
                    <h2>Eligibility Criteria</h2>
                </div>
            </div>
            <div class="db-timeline" style="padding:18px 22px 8px 48px;position:relative;">
                <div style="position:absolute;left:34px;top:18px;bottom:18px;width:2px;background:var(--db-border);border-radius:2px;"></div>
                <?php
                $criteria = [
                    ['blue',  'Residency',         'Must be a bonafide resident of the barangay for at least 6 months'],
                    ['teal',  'Academic Standing', 'Must maintain at least 80% (or equivalent) general average'],
                    ['amber', 'Enrollment Status', 'Must be currently enrolled or have proof of acceptance in an accredited school'],
                    ['rose',  'Good Moral Character','No record of serious disciplinary actions'],
                    ['indigo','Financial Need',    'Must demonstrate genuine financial need (for assistance programs)'],
                ];
                foreach ($criteria as [$color, $title, $desc]): ?>
                <div style="display:flex;gap:14px;align-items:flex-start;padding-bottom:18px;position:relative;">
                    <div class="db-panel__icon db-panel__icon--<?php echo $color; ?>"
                         style="width:26px;height:26px;font-size:11px;flex-shrink:0;margin-left:-14px;z-index:1;">
                        <i class="fas fa-circle" style="font-size:8px;"></i>
                    </div>
                    <div>
                        <strong style="font-size:13.5px;"><?php echo $title; ?></strong>
                        <p style="margin:3px 0 0;font-size:12.5px;color:var(--db-muted);line-height:1.6;"><?php echo $desc; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- FAQs -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-question-circle"></i></span>
                    <h2>Frequently Asked Questions</h2>
                </div>
                <span class="db-badge db-badge--muted"><?php echo count([1,2,3,4,5,6,7]); ?> items</span>
            </div>
            <div style="padding:0 22px 8px;">
                <?php
                $faqs = [
                    ['When can I apply for a scholarship?',
                     'You can apply anytime during the school year. For specific scholarship programs, check the application period in the "Available Scholarships" section.'],
                    ['How long does the processing take?',
                     'Applications are typically processed within 5–10 working days. You\'ll receive a notification once your application has been reviewed.'],
                    ['Can I apply for multiple scholarships?',
                     'Yes, you can apply for multiple programs as long as you meet the eligibility requirements for each.'],
                    ['What if my application is rejected?',
                     'You will receive notification with the reason for rejection. You may reapply in the next cycle or address the issues mentioned.'],
                    ['How will I receive the scholarship?',
                     'Approved scholarships may be disbursed directly to your school or given as a cash grant, depending on the scholarship type and barangay policy.'],
                    ['Do I need to renew my scholarship every year?',
                     'Yes, most scholarships require annual renewal. You\'ll need to submit updated documents and maintain the required academic standing.'],
                    ['Who can I contact for more information?',
                     'Contact the Barangay Education Office at ' . BARANGAY_CONTACT . ' or email ' . BARANGAY_EMAIL . '.'],
                ];
                foreach ($faqs as $i => [$q, $a]): ?>
                <div style="padding:14px 0;border-bottom:1px solid var(--db-border);">
                    <button class="db-faq-toggle" onclick="toggleFaq(<?php echo $i; ?>)"
                            style="width:100%;background:none;border:none;text-align:left;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:0;">
                        <strong style="font-size:13.5px;color:var(--db-navy);"><?php echo $q; ?></strong>
                        <i class="fas fa-chevron-down db-faq-icon-<?php echo $i; ?>"
                           style="color:var(--db-muted);font-size:12px;flex-shrink:0;transition:transform .2s;"></i>
                    </button>
                    <div id="faq-<?php echo $i; ?>" style="display:none;margin-top:8px;">
                        <p style="margin:0;font-size:13px;color:var(--db-muted);line-height:1.75;"><?php echo $a; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /main -->


    <!-- RIGHT SIDEBAR -->
    <div class="db-grid__side">

        <!-- Quick Actions -->
        <div class="db-panel" style="position:sticky;top:20px;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-rocket"></i></span>
                    <h2>Quick Actions</h2>
                </div>
            </div>
            <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
                <a href="apply-scholarship.php" class="db-btn db-btn--primary db-btn--full" style="font-size:14px;padding:11px;">
                    <i class="fas fa-edit"></i> Apply Now
                </a>
                <a href="my-documents.php" class="db-quicklink-card db-quicklink-card--amber" style="flex:unset;">
                    <i class="fas fa-folder"></i>
                    <span>My Documents</span>
                    <i class="fas fa-arrow-right db-quicklink-card__arrow"></i>
                </a>
                <a href="student-portal.php" class="db-quicklink-card" style="flex:unset;">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>My Dashboard</span>
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
                <?php if (!empty($active_scholarships)): ?>
                <span class="db-badge db-badge--success"><?php echo count($active_scholarships); ?> open</span>
                <?php endif; ?>
            </div>
            <?php if (empty($active_scholarships)): ?>
            <div class="db-empty db-empty--sm">
                <i class="fas fa-award"></i>
                <p>No active scholarships at the moment</p>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;">
                <?php foreach ($active_scholarships as $i => $s): ?>
                <div style="padding:14px 20px;<?php echo $i < count($active_scholarships)-1 ? 'border-bottom:1px solid var(--db-border);' : ''; ?>">
                    <strong style="font-size:13px;display:block;margin-bottom:6px;"><?php echo htmlspecialchars($s['scholarship_name']); ?></strong>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                        <span class="db-badge db-badge--success">₱<?php echo number_format($s['amount'], 2); ?></span>
                        <?php if ($s['slots']): ?>
                        <span class="db-badge db-badge--info"><?php echo $s['slots']; ?> slots</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($s['application_end']): ?>
                    <div style="font-family:'DM Mono',monospace;font-size:10.5px;color:var(--db-muted);margin-bottom:8px;">
                        <i class="far fa-calendar"></i> Deadline: <?php echo date('M d, Y', strtotime($s['application_end'])); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Contact Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-phone-alt"></i></span>
                    <h2>Contact Information</h2>
                </div>
            </div>
            <div style="padding:18px 22px;display:flex;flex-direction:column;gap:10px;">
                <?php
                $contacts = [
                    ['fa-map-marker-alt','rose',  'Address', BARANGAY_ADDRESS],
                    ['fa-phone',         'blue',  'Phone',   BARANGAY_CONTACT],
                    ['fa-envelope',      'indigo','Email',   BARANGAY_EMAIL],
                ];
                foreach ($contacts as [$icon, $color, $label, $val]): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                    <span class="db-panel__icon db-panel__icon--<?php echo $color; ?>" style="width:30px;height:30px;font-size:12px;flex-shrink:0;">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </span>
                    <div>
                        <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;font-family:'DM Mono',monospace;"><?php echo $label; ?></div>
                        <strong style="font-size:12.5px;"><?php echo htmlspecialchars($val); ?></strong>
                    </div>
                </div>
                <?php endforeach; ?>

                <div style="padding:12px 14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);margin-top:4px;">
                    <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;font-family:'DM Mono',monospace;margin-bottom:8px;">Office Hours</div>
                    <div style="font-size:12.5px;line-height:1.8;">
                        <strong>Monday – Friday</strong>: 8:00 AM – 5:00 PM<br>
                        <strong>Saturday</strong>: 8:00 AM – 12:00 PM
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /sidebar -->
</div><!-- /grid -->


<script>
function toggleFaq(i) {
    const el   = document.getElementById('faq-' + i);
    const icon = document.querySelector('.db-faq-icon-' + i);
    const open = el.style.display !== 'none';
    el.style.display   = open ? 'none' : 'block';
    icon.style.transform = open ? '' : 'rotate(180deg)';
}
</script>

<?php $conn->close(); include '../../../includes/footer.php'; ?>