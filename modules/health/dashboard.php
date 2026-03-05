<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title    = 'Health Dashboard';
$user_role     = getCurrentUserRole();
$user_id       = getCurrentUserId();

// Get current resident ID if user is a resident
$resident_id = null;
if ($user_role === 'Resident') {
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $resident_id = $result->fetch_assoc()['resident_id'];
    }
    $stmt->close();
}

// ── Stats ─────────────────────────────────────────────────────
if ($user_role === 'Resident') {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_health_records WHERE resident_id = ?");
    $stmt->bind_param("i", $resident_id); $stmt->execute();
    $health_records = $stmt->get_result()->fetch_assoc()['total']; $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_vaccination_records WHERE resident_id = ?");
    $stmt->bind_param("i", $resident_id); $stmt->execute();
    $vaccinations = $stmt->get_result()->fetch_assoc()['total']; $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_appointments WHERE resident_id = ? AND status = 'Pending'");
    $stmt->bind_param("i", $resident_id); $stmt->execute();
    $pending_appointments = $stmt->get_result()->fetch_assoc()['total']; $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE resident_id = ? AND status = 'Pending'");
    $stmt->bind_param("i", $resident_id); $stmt->execute();
    $pending_assistance = $stmt->get_result()->fetch_assoc()['total']; $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id = ? ORDER BY record_date DESC LIMIT 5");
    $stmt->bind_param("i", $resident_id); $stmt->execute();
    $recent_records = $stmt->get_result(); $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM tbl_appointments WHERE resident_id = ? AND appointment_date >= CURDATE() AND status != 'Cancelled' ORDER BY appointment_date ASC, appointment_time ASC LIMIT 5");
    $stmt->bind_param("i", $resident_id); $stmt->execute();
    $upcoming_appointments = $stmt->get_result(); $stmt->close();

} else {
    $total_residents      = $conn->query("SELECT COUNT(*) as total FROM tbl_residents WHERE is_verified = 1")->fetch_assoc()['total'];
    $total_health_records = $conn->query("SELECT COUNT(*) as total FROM tbl_health_records")->fetch_assoc()['total'];
    $total_vaccinations   = $conn->query("SELECT COUNT(*) as total FROM tbl_vaccination_records")->fetch_assoc()['total'];
    $pending_appointments = $conn->query("SELECT COUNT(*) as total FROM tbl_appointments WHERE status = 'Pending'")->fetch_assoc()['total'];
    $pending_assistance   = $conn->query("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE status = 'Pending'")->fetch_assoc()['total'];

    $recent_appointments = $conn->query("
        SELECT a.*, r.first_name, r.last_name 
        FROM tbl_appointments a
        JOIN tbl_residents r ON a.resident_id = r.resident_id
        ORDER BY a.created_at DESC LIMIT 5
    ");

    $disease_alerts = $conn->query("
        SELECT * FROM tbl_disease_surveillance 
        WHERE status = 'Active' 
        ORDER BY report_date DESC LIMIT 5
    ");
}

include '../../includes/header.php';
?>

<link rel="stylesheet" href="/barangaylink1/assets/css/_db_shared.css">

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon" style="background:linear-gradient(135deg,#e11d48,#be123c);box-shadow:0 4px 16px rgba(225,29,72,.4);">
                <i class="fas fa-heartbeat"></i>
            </div>
            <div>
                <div class="rm-hero__title">Health Dashboard</div>
                <div class="rm-hero__sub">
                    <?php echo $user_role === 'Resident' ? 'Overview of your personal health services and records' : 'Overview of barangay health services and records'; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div style="padding: 0 24px 32px;">

<?php if ($user_role === 'Resident'): ?>
<!-- ══════════════════════════════════════════════════════════
     RESIDENT DASHBOARD
     ══════════════════════════════════════════════════════════ -->

<!-- Stat Cards -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-file-medical"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $health_records; ?></div><div class="db-stat-card__label">Health Records</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-syringe"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $vaccinations; ?></div><div class="db-stat-card__label">Vaccinations</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-calendar-check"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $pending_appointments; ?></div><div class="db-stat-card__label">Pending Appointments</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--purple"><i class="fas fa-hand-holding-medical"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-purple)"><?php echo $pending_assistance; ?></div><div class="db-stat-card__label">Assistance Requests</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--purple"></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="db-panel" style="animation-delay:.05s; margin-bottom:18px;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bolt"></i></div>
            <h2>Quick Actions</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px;">
            <?php
            $actions = [
                ['book-appointment.php',  'fa-calendar-plus',       'Book Appointment',    'sky'],
                ['my-vaccinations.php',   'fa-syringe',             'My Vaccinations',     'success'],
                ['my-health.php',         'fa-heartbeat',           'Health Profile',      'rose'],
                ['medical-assistance.php','fa-hand-holding-medical','Request Assistance',  'purple'],
            ];
            foreach ($actions as [$href, $icon, $label, $color]):
            ?>
            <a href="<?php echo $href; ?>" style="
                display:flex; flex-direction:column; align-items:center; gap:10px;
                padding:18px 12px; background:var(--db-surf2); border:1px solid var(--db-border);
                border-radius:var(--db-radius); text-decoration:none; color:var(--db-text);
                transition:all .2s; font-size:13px; font-weight:600; text-align:center;"
                onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='var(--db-shadow-lg)';this.style.borderColor='var(--db-<?php echo $color; ?>)';"
                onmouseout="this.style.transform='';this.style.boxShadow='';this.style.borderColor='var(--db-border)';">
                <div style="width:44px;height:44px;border-radius:12px;background:var(--db-<?php echo $color; ?>-light);color:var(--db-<?php echo $color; ?>);display:flex;align-items:center;justify-content:center;font-size:18px;">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Recent Health Records -->
<div class="db-panel" style="animation-delay:.1s;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-file-medical"></i></div>
            <h2>Recent Health Records</h2>
        </div>
        <a href="records.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i> View All</a>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Diagnosis</th>
                    <th>Healthcare Provider</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recent_records->num_rows > 0): ?>
                    <?php while ($record = $recent_records->fetch_assoc()): ?>
                    <tr>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($record['record_date'])); ?></span></td>
                        <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($record['record_type']); ?></span></td>
                        <td><?php echo htmlspecialchars($record['diagnosis']); ?></td>
                        <td><span class="db-text-sm"><?php echo htmlspecialchars($record['healthcare_provider']); ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4"><div class="db-empty" style="padding:32px;"><i class="fas fa-file-medical"></i><p>No health records yet</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Upcoming Appointments -->
<div class="db-panel" style="animation-delay:.15s;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-calendar-alt"></i></div>
            <h2>Upcoming Appointments</h2>
        </div>
        <a href="appointments.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i> View All</a>
    </div>
    <div class="db-panel__body">
        <?php if ($upcoming_appointments->num_rows > 0): ?>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <?php while ($apt = $upcoming_appointments->fetch_assoc()): ?>
            <div style="display:flex; gap:14px; align-items:center; padding:12px 14px; background:var(--db-surf2); border:1px solid var(--db-border); border-radius:var(--db-radius-sm);">
                <div style="background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light)); color:#fff; padding:10px 14px; border-radius:10px; text-align:center; min-width:54px; flex-shrink:0;">
                    <div style="font-size:20px; font-weight:800; line-height:1;"><?php echo date('d', strtotime($apt['appointment_date'])); ?></div>
                    <div style="font-size:10px; text-transform:uppercase; opacity:.8;"><?php echo date('M', strtotime($apt['appointment_date'])); ?></div>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:700; font-size:13.5px; margin-bottom:3px;"><?php echo htmlspecialchars($apt['appointment_type']); ?></div>
                    <div style="font-size:12px; color:var(--db-muted);">
                        <i class="fas fa-clock me-1" style="color:var(--db-sky);"></i><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?>
                        &nbsp;·&nbsp; <?php echo htmlspecialchars($apt['purpose']); ?>
                    </div>
                </div>
                <span class="db-badge db-badge--amber"><i class="fas fa-clock"></i> <?php echo $apt['status']; ?></span>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="db-empty" style="padding:32px;"><i class="fas fa-calendar"></i><p>No upcoming appointments</p><a href="book-appointment.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> Book Appointment</a></div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════
     ADMIN / STAFF DASHBOARD
     ══════════════════════════════════════════════════════════ -->

<!-- Stat Cards -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--navy"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($total_residents); ?></div><div class="db-stat-card__label">Total Residents</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--navy"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-file-medical"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo number_format($total_health_records); ?></div><div class="db-stat-card__label">Health Records</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--purple"><i class="fas fa-syringe"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-purple)"><?php echo number_format($total_vaccinations); ?></div><div class="db-stat-card__label">Vaccinations</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--purple"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-calendar-check"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $pending_appointments; ?></div><div class="db-stat-card__label">Pending Appointments</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-hand-holding-medical"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $pending_assistance; ?></div><div class="db-stat-card__label">Pending Assistance</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
</div>

<!-- Quick Actions -->
<div class="db-panel" style="animation-delay:.05s; margin-bottom:18px;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bolt"></i></div>
            <h2>Quick Actions</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px;">
            <?php
            $admin_actions = [
                ['records.php',            'fa-file-medical',        'Health Records',      'sky'],
                ['vaccinations.php',       'fa-syringe',             'Vaccinations',        'success'],
                ['appointments.php',       'fa-calendar-check',      'Appointments',        'amber'],
                ['medical-assistance.php', 'fa-hand-holding-medical','Medical Assistance',  'purple'],
                ['disease-surveillance.php','fa-virus',              'Disease Surveillance','rose'],
            ];
            foreach ($admin_actions as [$href, $icon, $label, $color]):
            ?>
            <a href="<?php echo $href; ?>" style="
                display:flex; flex-direction:column; align-items:center; gap:10px;
                padding:18px 12px; background:var(--db-surf2); border:1px solid var(--db-border);
                border-radius:var(--db-radius); text-decoration:none; color:var(--db-text);
                transition:all .2s; font-size:13px; font-weight:600; text-align:center;"
                onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='var(--db-shadow-lg)';this.style.borderColor='var(--db-<?php echo $color; ?>)';"
                onmouseout="this.style.transform='';this.style.boxShadow='';this.style.borderColor='var(--db-border)';">
                <div style="width:44px;height:44px;border-radius:12px;background:var(--db-<?php echo $color; ?>-light);color:var(--db-<?php echo $color; ?>);display:flex;align-items:center;justify-content:center;font-size:18px;">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; align-items:start;">

<!-- Recent Appointments -->
<div class="db-panel" style="animation-delay:.1s;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-calendar-alt"></i></div>
            <h2>Recent Appointments</h2>
        </div>
        <a href="appointments.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i> View All</a>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Resident</th>
                    <th>Type</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recent_appointments->num_rows > 0): ?>
                    <?php while ($apt = $recent_appointments->fetch_assoc()):
                        $st = $apt['status'];
                        $sc = $st === 'Confirmed' ? 'success' : ($st === 'Pending' ? 'amber' : 'muted');
                    ?>
                    <tr>
                        <td>
                            <span class="db-text-sm"><?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?></span><br>
                            <span class="db-text-sm" style="color:#94a3b8;"><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></span>
                        </td>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></td>
                        <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($apt['appointment_type']); ?></span></td>
                        <td><span class="db-badge db-badge--<?php echo $sc; ?>"><?php echo $st; ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4"><div class="db-empty" style="padding:28px;"><i class="fas fa-calendar"></i><p>No recent appointments</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Disease Surveillance Alerts -->
<div class="db-panel" style="animation-delay:.15s;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-virus"></i></div>
            <h2>Surveillance Alerts</h2>
        </div>
        <a href="disease-surveillance.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i> View All</a>
    </div>
    <div class="db-panel__body">
        <?php if ($disease_alerts->num_rows > 0): ?>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <?php while ($alert = $disease_alerts->fetch_assoc()):
                $sev = strtolower($alert['severity']);
                $sev_badge = $sev === 'high' ? 'rose' : ($sev === 'medium' ? 'amber' : 'success');
                $sev_bg    = $sev === 'high' ? '#fff5f5' : ($sev === 'medium' ? '#fffbeb' : '#f0fdf4');
                $sev_border= $sev === 'high' ? 'var(--db-rose)' : ($sev === 'medium' ? 'var(--db-amber)' : 'var(--db-success)');
            ?>
            <div style="display:flex; gap:12px; align-items:flex-start; padding:12px 14px;
                        background:<?php echo $sev_bg; ?>; border:1px solid var(--db-border);
                        border-left:3px solid <?php echo $sev_border; ?>; border-radius:var(--db-radius-sm);">
                <div style="width:40px;height:40px;border-radius:10px;background:var(--db-rose-light);color:var(--db-rose);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">
                    <i class="fas fa-virus"></i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-weight:700; font-size:13px; margin-bottom:4px;"><?php echo htmlspecialchars($alert['disease_name']); ?></div>
                    <div style="font-size:11.5px; color:var(--db-muted); display:flex; gap:12px; flex-wrap:wrap; margin-bottom:3px;">
                        <span><i class="fas fa-map-marker-alt me-1" style="color:var(--db-rose);"></i><?php echo htmlspecialchars($alert['location']); ?></span>
                        <span><i class="fas fa-users me-1"></i><?php echo $alert['affected_count']; ?> cases</span>
                    </div>
                    <div style="font-size:10.5px; color:#94a3b8; font-family:'DM Mono',monospace;"><?php echo date('M d, Y', strtotime($alert['report_date'])); ?></div>
                </div>
                <span class="db-badge db-badge--<?php echo $sev_badge; ?>"><?php echo $alert['severity']; ?></span>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="db-empty" style="padding:32px;">
            <i class="fas fa-check-circle" style="color:var(--db-success);"></i>
            <p>No active disease alerts</p>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /two-col grid -->
<?php endif; ?>

</div><!-- /padding wrapper -->

<?php include '../../includes/footer.php'; ?>