<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
requireLogin();

$user_id = getCurrentUserId();

$stmt = $conn->prepare("SELECT r.is_verified,r.id_photo,r.first_name,r.last_name,r.resident_id FROM tbl_users u JOIN tbl_residents r ON u.resident_id=r.resident_id WHERE u.user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data || $user_data['is_verified'] != 1) { header("Location: health-verification.php"); exit(); }

$page_title  = 'My Vaccinations';
$resident_id = $user_data['resident_id'];

$stmt = $conn->prepare("SELECT * FROM tbl_vaccination_records WHERE resident_id=? ORDER BY vaccination_date DESC");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$vaccinations = $stmt->get_result();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total_vaccinations, COUNT(DISTINCT vaccine_type) as vaccine_types, SUM(CASE WHEN dose_number>=total_doses THEN 1 ELSE 0 END) as complete_series, MAX(vaccination_date) as last_vaccination FROM tbl_vaccination_records WHERE resident_id=?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<style>
:root { --rs-blue:#4299e1; --rs-green:#48bb78; --rs-orange:#ed8936; --rs-purple:#9f7aea; --rs-red:#f56565; --rs-radius:12px; --rs-shadow:0 2px 8px rgba(0,0,0,.08); --rs-shadow-md:0 4px 16px rgba(0,0,0,.12); }

.rs-page { padding:1.5rem; }
.rs-page-header { margin-bottom:1.5rem; }
.rs-page-header h2 { font-size:1.4rem; font-weight:700; color:#2d3748; margin:0 0 4px; display:flex; align-items:center; gap:10px; }
.rs-page-header p  { color:#718096; margin:0; font-size:.9rem; }

/* Stat cards */
.rs-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.5rem; }
.rs-stat { background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); padding:1.25rem; display:flex; align-items:center; gap:.9rem; transition:box-shadow .2s,transform .2s; }
.rs-stat:hover { box-shadow:var(--rs-shadow-md); transform:translateY(-3px); }
.rs-stat__icon { width:52px; height:52px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#fff; flex-shrink:0; }
.rs-stat__val  { font-size:1.5rem; font-weight:700; color:#2d3748; line-height:1.2; }
.rs-stat__lbl  { font-size:.8rem; color:#718096; font-weight:500; margin-top:2px; }

/* Vaccination cards */
.rs-vax-card { background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); border-left:4px solid var(--rs-blue); margin-bottom:1.25rem; padding:1.5rem; transition:box-shadow .2s,transform .2s; }
.rs-vax-card:hover { box-shadow:var(--rs-shadow-md); transform:translateY(-2px); }
.rs-vax-card.complete   { border-left-color:var(--rs-green); }
.rs-vax-card.incomplete { border-left-color:var(--rs-orange); }

.rs-vax-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; }
.rs-vax-name { font-size:1.05rem; font-weight:700; color:#2d3748; margin-bottom:.35rem; }
.rs-vax-type { display:inline-block; padding:.2rem .65rem; background:#bee3f8; color:#2c5282; border-radius:10px; font-size:.75rem; font-weight:600; }

.rs-badge { display:inline-block; padding:.3rem .75rem; border-radius:50px; font-size:.78rem; font-weight:600; }
.rs-badge--green  { background:#c6f6d5; color:#276749; }
.rs-badge--yellow { background:#fefcbf; color:#744210; }

.rs-vax-details { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.9rem; padding-top:1rem; border-top:2px solid #e9ecef; }
.rs-vax-detail label { font-size:.72rem; color:#718096; text-transform:uppercase; letter-spacing:.5px; font-weight:600; display:block; margin-bottom:.2rem; }
.rs-vax-detail span  { font-size:.9rem; color:#2d3748; font-weight:500; }

/* Next dose alert */
.rs-dose-alert { display:flex; align-items:center; gap:.9rem; padding:.85rem 1rem; margin-top:1rem; border-radius:8px; border-left:4px solid; }
.rs-dose-alert.overdue  { background:linear-gradient(135deg,#fff5f5,#ffe5e5); border-left-color:var(--rs-red); }
.rs-dose-alert.soon     { background:linear-gradient(135deg,#fffaf0,#fff8e1); border-left-color:var(--rs-orange); }
.rs-dose-alert.ok       { background:linear-gradient(135deg,#f0f9ff,#e7f1ff); border-left-color:var(--rs-blue); }
.rs-dose-alert__icon { font-size:1.4rem; }
.rs-dose-alert.overdue .rs-dose-alert__icon { color:var(--rs-red); }
.rs-dose-alert.soon    .rs-dose-alert__icon { color:var(--rs-orange); }
.rs-dose-alert.ok      .rs-dose-alert__icon { color:var(--rs-blue); }
.rs-dose-alert__label { font-size:.8rem; font-weight:700; display:block; margin-bottom:.15rem; }
.rs-dose-alert.overdue .rs-dose-alert__label { color:#c53030; }
.rs-dose-alert.soon    .rs-dose-alert__label { color:#c05621; }
.rs-dose-alert.ok      .rs-dose-alert__label { color:#2c5282; }
.rs-dose-alert__date { font-size:.95rem; font-weight:600; color:#2d3748; }
.rs-dose-days { font-size:.8rem; font-weight:400; color:#718096; margin-left:.4rem; }

/* Progress */
.rs-progress { display:flex; align-items:center; gap:.75rem; margin-top:1rem; }
.rs-progress-bar { flex:1; height:7px; background:#e2e8f0; border-radius:4px; overflow:hidden; }
.rs-progress-fill { height:100%; background:linear-gradient(90deg,var(--rs-green),#38a169); transition:width .3s; }
.rs-progress-pct  { font-size:.8rem; font-weight:600; color:#2d3748; min-width:38px; text-align:right; }

.rs-empty { text-align:center; padding:3rem; color:#a0aec0; background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); }
.rs-empty i { font-size:3rem; margin-bottom:1rem; display:block; opacity:.4; }
.rs-empty p { font-size:.95rem; margin:0; }

@media(max-width:768px) { .rs-stats { grid-template-columns:repeat(2,1fr); } }
</style>

<div class="rs-page">
    <div class="rs-page-header">
        <h2><i class="fas fa-syringe" style="color:var(--rs-green);"></i> My Vaccination Records</h2>
        <p>Track your immunization history</p>
    </div>

    <!-- Stats -->
    <div class="rs-stats">
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-blue);"><i class="fas fa-syringe"></i></div>
            <div><div class="rs-stat__val"><?php echo $summary['total_vaccinations']??0; ?></div><div class="rs-stat__lbl">Total Vaccinations</div></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-green);"><i class="fas fa-check-circle"></i></div>
            <div><div class="rs-stat__val"><?php echo $summary['complete_series']??0; ?></div><div class="rs-stat__lbl">Complete Series</div></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-purple);"><i class="fas fa-shield-virus"></i></div>
            <div><div class="rs-stat__val"><?php echo $summary['vaccine_types']??0; ?></div><div class="rs-stat__lbl">Vaccine Types</div></div>
        </div>
        <div class="rs-stat">
            <div class="rs-stat__icon" style="background:var(--rs-orange);"><i class="fas fa-calendar-alt"></i></div>
            <div><div class="rs-stat__val" style="font-size:1rem;"><?php echo $summary['last_vaccination'] ? date('M d, Y', strtotime($summary['last_vaccination'])) : 'N/A'; ?></div><div class="rs-stat__lbl">Last Vaccination</div></div>
        </div>
    </div>

    <!-- Records -->
    <?php if ($vaccinations->num_rows > 0): ?>
        <?php while ($vax = $vaccinations->fetch_assoc()):
            $is_complete      = $vax['dose_number'] >= $vax['total_doses'];
            $progress_percent = ($vax['dose_number'] / max($vax['total_doses'],1)) * 100;
        ?>
        <div class="rs-vax-card <?php echo $is_complete ? 'complete' : 'incomplete'; ?>">
            <div class="rs-vax-header">
                <div>
                    <div class="rs-vax-name"><?php echo htmlspecialchars($vax['vaccine_name']); ?></div>
                    <span class="rs-vax-type"><?php echo htmlspecialchars($vax['vaccine_type']); ?></span>
                </div>
                <span class="rs-badge <?php echo $is_complete ? 'rs-badge--green' : 'rs-badge--yellow'; ?>">
                    <i class="fas fa-<?php echo $is_complete ? 'check-circle' : 'clock'; ?> me-1"></i>
                    <?php echo $is_complete ? 'Complete' : 'Incomplete'; ?>
                </span>
            </div>

            <div class="rs-vax-details">
                <div class="rs-vax-detail"><label>Vaccination Date</label><span><?php echo date('F j, Y', strtotime($vax['vaccination_date'])); ?></span></div>
                <div class="rs-vax-detail"><label>Dose</label><span>Dose <?php echo $vax['dose_number']; ?> of <?php echo $vax['total_doses']; ?></span></div>
                <?php if (!empty($vax['vaccine_brand'])): ?><div class="rs-vax-detail"><label>Brand</label><span><?php echo htmlspecialchars($vax['vaccine_brand']); ?></span></div><?php endif; ?>
                <?php if (!empty($vax['batch_number'])): ?><div class="rs-vax-detail"><label>Batch No.</label><span><?php echo htmlspecialchars($vax['batch_number']); ?></span></div><?php endif; ?>
                <?php if (!empty($vax['administered_by'])): ?><div class="rs-vax-detail"><label>Administered By</label><span><?php echo htmlspecialchars($vax['administered_by']); ?></span></div><?php endif; ?>
                <?php if (!empty($vax['vaccination_site'])): ?><div class="rs-vax-detail"><label>Site</label><span><?php echo htmlspecialchars($vax['vaccination_site']); ?></span></div><?php endif; ?>
            </div>

            <?php if (!empty($vax['next_dose_date'])):
                $nd     = strtotime($vax['next_dose_date']);
                $today  = strtotime(date('Y-m-d'));
                $diff   = floor(($nd - $today) / 86400);
                $cls    = $diff < 0 ? 'overdue' : ($diff <= 7 ? 'soon' : 'ok');
                $icon   = $diff < 0 ? 'exclamation-triangle' : ($diff <= 7 ? 'clock' : 'calendar-check');
                $lbl    = $diff < 0 ? 'Next Dose Overdue!' : ($diff <= 7 ? 'Next Dose Coming Soon' : 'Next Dose Scheduled');
            ?>
            <div class="rs-dose-alert <?php echo $cls; ?>">
                <div class="rs-dose-alert__icon"><i class="fas fa-<?php echo $icon; ?>"></i></div>
                <div>
                    <span class="rs-dose-alert__label"><?php echo $lbl; ?></span>
                    <div class="rs-dose-alert__date">
                        <?php echo date('F j, Y', $nd); ?>
                        <?php if ($diff < 0): ?><span class="rs-dose-days">(<?php echo abs($diff); ?>d overdue)</span>
                        <?php elseif ($diff <= 7): ?><span class="rs-dose-days">(in <?php echo $diff; ?> days)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="rs-progress">
                <div class="rs-progress-bar"><div class="rs-progress-fill" style="width:<?php echo $progress_percent; ?>%;"></div></div>
                <span class="rs-progress-pct"><?php echo round($progress_percent); ?>%</span>
            </div>

            <?php if (!empty($vax['side_effects'])): ?>
            <div style="margin-top:.9rem;padding-top:.9rem;border-top:2px solid #e9ecef;">
                <strong style="font-size:.78rem;color:#718096;text-transform:uppercase;letter-spacing:.4px;">Side Effects</strong>
                <p style="margin:.4rem 0 0;color:#4a5568;font-size:.875rem;"><?php echo nl2br(htmlspecialchars($vax['side_effects'])); ?></p>
            </div>
            <?php endif; ?>
            <?php if (!empty($vax['remarks'])): ?>
            <div style="margin-top:.6rem;">
                <strong style="font-size:.78rem;color:#718096;text-transform:uppercase;letter-spacing:.4px;">Remarks</strong>
                <p style="margin:.4rem 0 0;color:#4a5568;font-size:.875rem;"><?php echo nl2br(htmlspecialchars($vax['remarks'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="rs-empty">
            <i class="fas fa-syringe"></i>
            <h5 style="color:#4a5568;margin-bottom:.5rem;">No Vaccination Records</h5>
            <p>You don't have any vaccination records yet. Visit the Barangay Health Center to get vaccinated.</p>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>