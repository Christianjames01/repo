<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Health Appointments';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $appointment_id = (int)$_POST['appointment_id'];
        $status = $_POST['status'];

        if ($status === 'Completed') {
            $attended_by      = isset($_POST['attended_by'])      ? trim($_POST['attended_by'])      : null;
            $diagnosis        = isset($_POST['diagnosis'])        ? trim($_POST['diagnosis'])        : null;
            $prescription     = isset($_POST['prescription'])     ? trim($_POST['prescription'])     : null;
            $follow_up_date   = isset($_POST['follow_up_date']) && $_POST['follow_up_date'] ? $_POST['follow_up_date'] : null;
            $notes            = isset($_POST['notes'])            ? trim($_POST['notes'])            : '';
            $note_text        = $notes ? "\n[" . date('Y-m-d H:i') . "] " . $notes : '';
            $stmt = $conn->prepare("UPDATE tbl_health_appointments SET status=?,attended_by=?,diagnosis=?,prescription=?,follow_up_date=?,notes=CONCAT(IFNULL(notes,''),?) WHERE appointment_id=?");
            $stmt->bind_param("ssssssi", $status, $attended_by, $diagnosis, $prescription, $follow_up_date, $note_text, $appointment_id);
        } elseif ($status === 'Cancelled') {
            $reason    = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';
            $note_text = "\n[" . date('Y-m-d H:i') . "] Cancelled by staff" . ($reason ? ": $reason" : '');
            $stmt = $conn->prepare("UPDATE tbl_health_appointments SET status=?,notes=CONCAT(IFNULL(notes,''),?) WHERE appointment_id=?");
            $stmt->bind_param("ssi", $status, $note_text, $appointment_id);
        } else {
            $notes     = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            $note_text = $notes ? "\n[" . date('Y-m-d H:i') . "] " . $notes : '';
            $stmt = $conn->prepare("UPDATE tbl_health_appointments SET status=?,notes=CONCAT(IFNULL(notes,''),?) WHERE appointment_id=?");
            $stmt->bind_param("ssi", $status, $note_text, $appointment_id);
        }

        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Appointment status updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating appointment status.";
        }
        $stmt->close();
        header("Location: appointments.php");
        exit;
    }
}

// Filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter   = isset($_GET['date'])   ? $_GET['date']   : '';
$type_filter   = isset($_GET['type'])   ? $_GET['type']   : '';
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_clauses = ["1=1"]; $params = []; $types = "";

if ($status_filter) { $where_clauses[] = "a.status=?";               $params[] = $status_filter; $types .= "s"; }
if ($date_filter)   { $where_clauses[] = "a.appointment_date=?";      $params[] = $date_filter;   $types .= "s"; }
else                { $where_clauses[] = "a.appointment_date >= CURDATE()"; }
if ($type_filter)   { $where_clauses[] = "a.appointment_type=?";      $params[] = $type_filter;   $types .= "s"; }
if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ?)";
    $sp = "%$search%"; $params[] = $sp; $params[] = $sp; $types .= "ss";
}
$where_sql = implode(" AND ", $where_clauses);

$sql = "SELECT a.*,r.first_name,r.last_name,r.contact_number,r.date_of_birth,u.username as created_by_name
        FROM tbl_health_appointments a
        JOIN tbl_residents r ON a.resident_id=r.resident_id
        LEFT JOIN tbl_users u ON a.created_by=u.user_id
        WHERE $where_sql ORDER BY a.appointment_date, a.appointment_time";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$appointments = $stmt->get_result();
$stmt->close();

$today_count    = $conn->query("SELECT COUNT(*) as c FROM tbl_health_appointments WHERE appointment_date=CURDATE() AND status IN('Scheduled','Confirmed')")->fetch_assoc()['c'];
$upcoming_count = $conn->query("SELECT COUNT(*) as c FROM tbl_health_appointments WHERE appointment_date>CURDATE() AND status IN('Scheduled','Confirmed')")->fetch_assoc()['c'];
$pending_count  = $conn->query("SELECT COUNT(*) as c FROM tbl_health_appointments WHERE status='Scheduled'")->fetch_assoc()['c'];
$total_count    = $conn->query("SELECT COUNT(*) as c FROM tbl_health_appointments")->fetch_assoc()['c'];

$appointment_types = $conn->query("SELECT DISTINCT appointment_type FROM tbl_health_appointments ORDER BY appointment_type");

include '../../includes/header.php';
?>

<link rel="stylesheet" href="/barangaylink1/assets/css/_db_shared.css">

<!-- Hero — matches vaccination hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon" style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4299e1,#2b6cb0);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(66,153,225,.4);">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div>
                <div class="rm-hero__title">Health Appointments</div>
                <div class="rm-hero__sub">Manage health center appointments and consultations</div>
            </div>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats — matches vaccination stats row -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-calendar-day"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo number_format($today_count); ?></div><div class="db-stat-card__label">Today's Appointments</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-calendar-alt"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($upcoming_count); ?></div><div class="db-stat-card__label">Upcoming</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--purple"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-purple)"><?php echo number_format($pending_count); ?></div><div class="db-stat-card__label">Pending Confirmation</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--purple"></div>
    </div>
</div>

<!-- Filter Bar — matches vaccination filter panel -->
<div class="db-panel" style="margin-bottom:18px;">
    <div class="db-panel__body" style="padding:14px 18px;">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label class="db-form-label" style="margin-bottom:5px;">Search</label>
                <div style="position:relative;">
                    <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--db-muted);font-size:12px;"></i>
                    <input type="text" name="search" class="db-form-control" style="padding-left:32px;"
                           placeholder="Search by resident name…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div style="min-width:160px;">
                <label class="db-form-label" style="margin-bottom:5px;">Status</label>
                <select name="status" class="db-form-select">
                    <option value="">All Statuses</option>
                    <?php foreach(['Scheduled','Confirmed','Completed','Cancelled','No-Show'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:160px;">
                <label class="db-form-label" style="margin-bottom:5px;">Type</label>
                <select name="type" class="db-form-select">
                    <option value="">All Types</option>
                    <?php while ($t = $appointment_types->fetch_assoc()): ?>
                    <option value="<?php echo htmlspecialchars($t['appointment_type']); ?>" <?php echo $type_filter===$t['appointment_type']?'selected':''; ?>>
                        <?php echo htmlspecialchars($t['appointment_type']); ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div style="min-width:150px;">
                <label class="db-form-label" style="margin-bottom:5px;">Date</label>
                <input type="date" name="date" class="db-form-control" value="<?php echo $date_filter; ?>">
            </div>
            <div style="display:flex;gap:8px;padding-bottom:1px;">
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($search||$status_filter||$type_filter||$date_filter): ?>
                <a href="appointments.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table — matches vaccination table panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-list"></i></div>
            <h2>Appointments</h2>
            <span class="db-badge db-badge--muted"><?php echo number_format($total_count); ?> total</span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Resident</th>
                    <th>Type</th>
                    <th>Purpose</th>
                    <th>Status</th>
                    <th>Attended By</th>
                    <th>Follow-up</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($appointments->num_rows > 0): ?>
                <?php while ($apt = $appointments->fetch_assoc()):
                    $age = $apt['date_of_birth'] ? floor((time()-strtotime($apt['date_of_birth']))/31556926) : 'N/A';
                    $apt_date = strtotime($apt['appointment_date']);
                    $today    = strtotime(date('Y-m-d'));
                    $is_today = $apt_date === $today;
                    $is_past  = $apt_date < $today;

                    $status_badge = match($apt['status']) {
                        'Confirmed'  => 'db-badge--success',
                        'Scheduled'  => 'db-badge--amber',
                        'Completed'  => 'db-badge--sky',
                        'Cancelled'  => 'db-badge--rose',
                        'No-Show'    => 'db-badge--muted',
                        default      => 'db-badge--muted',
                    };

                    $fu_date = !empty($apt['follow_up_date']) ? strtotime($apt['follow_up_date']) : null;
                    $fu_diff = $fu_date ? floor(($fu_date - $today) / 86400) : null;
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;font-size:13px;"><?php echo date('M d, Y', $apt_date); ?></div>
                        <div style="font-size:11px;color:var(--db-muted);"><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></div>
                        <?php if ($is_today): ?>
                            <span class="db-badge db-badge--sky" style="font-size:10px;margin-top:2px;"><i class="fas fa-circle" style="font-size:7px;"></i> Today</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:700;font-size:13px;"><?php echo htmlspecialchars($apt['first_name'].' '.$apt['last_name']); ?></div>
                        <div style="font-size:11px;color:var(--db-muted);"><?php echo $age; ?> yrs · <?php echo htmlspecialchars($apt['contact_number']); ?></div>
                    </td>
                    <td><span class="db-badge db-badge--sky"><?php echo htmlspecialchars($apt['appointment_type']); ?></span></td>
                    <td>
                        <span class="db-text-sm" style="max-width:180px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($apt['purpose']); ?>">
                            <?php echo htmlspecialchars($apt['purpose']); ?>
                        </span>
                        <?php if ($apt['symptoms']): ?>
                        <div style="font-size:11px;color:var(--db-muted);margin-top:2px;">
                            <i class="fas fa-notes-medical" style="font-size:10px;"></i> <?php echo htmlspecialchars(mb_strimwidth($apt['symptoms'],0,40,'…')); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="db-badge <?php echo $status_badge; ?>"><?php echo $apt['status']; ?></span></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars($apt['attended_by'] ?: '—'); ?></span></td>
                    <td>
                        <?php if ($fu_date): ?>
                        <div style="font-size:12.5px;font-weight:600;"><?php echo date('M d, Y', $fu_date); ?></div>
                        <?php if ($fu_diff < 0): ?>
                            <span class="db-badge db-badge--rose"><i class="fas fa-exclamation-triangle"></i> <?php echo abs($fu_diff); ?>d overdue</span>
                        <?php elseif ($fu_diff <= 7): ?>
                            <span class="db-badge db-badge--amber"><i class="fas fa-clock"></i> <?php echo $fu_diff; ?>d left</span>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--db-muted);">In <?php echo $fu_diff; ?> days</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="color:var(--db-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="db-btn db-btn--ghost db-btn--sm" onclick='viewAppointment(<?php echo json_encode($apt); ?>)' title="View"><i class="fas fa-eye"></i></button>
                            <?php if ($apt['status'] === 'Scheduled'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="appointment_id" value="<?php echo $apt['appointment_id']; ?>">
                                <input type="hidden" name="status" value="Confirmed">
                                <button type="submit" class="db-btn db-btn--success db-btn--sm" title="Confirm"><i class="fas fa-check"></i></button>
                            </form>
                            <?php endif; ?>
                            <?php if (in_array($apt['status'], ['Scheduled','Confirmed'])): ?>
                            <button class="db-btn db-btn--primary db-btn--sm" onclick='completeAppointment(<?php echo json_encode($apt); ?>)' title="Complete"><i class="fas fa-check-double"></i></button>
                            <button class="db-btn db-btn--sm" style="background:var(--db-rose);color:#fff;border:none;" onclick='openCancelModal(<?php echo $apt["appointment_id"]; ?>, <?php echo json_encode($apt); ?>)' title="Cancel"><i class="fas fa-times"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8">
                    <div style="text-align:center;padding:40px;color:var(--db-muted);">
                        <i class="fas fa-calendar-times" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                        No appointments found
                    </div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /padding wrapper -->

<!-- Complete Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;">
                    <i class="fas fa-check-double"></i> Complete Appointment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="appointment_id" id="complete_apt_id">
                <input type="hidden" name="status" value="Completed">
                <div class="modal-body" style="padding:20px;">
                    <div class="db-form-row db-form-row--2">
                        <div class="db-form-group">
                            <label class="db-form-label">Attended By</label>
                            <input type="text" name="attended_by" class="db-form-control" placeholder="Doctor / Nurse name">
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label">Follow-up Date</label>
                            <input type="date" name="follow_up_date" class="db-form-control">
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Diagnosis</label>
                            <textarea name="diagnosis" class="db-form-textarea" rows="2"></textarea>
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Prescription</label>
                            <textarea name="prescription" class="db-form-textarea" rows="2"></textarea>
                        </div>
                        <div class="db-form-group" style="grid-column:1/-1;">
                            <label class="db-form-label">Notes</label>
                            <textarea name="notes" class="db-form-textarea" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Complete Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="db-modal-header" style="background:linear-gradient(135deg,#e53e3e,#c53030);">
                <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;">
                    <i class="fas fa-exclamation-triangle"></i> Cancel Appointment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="appointment_id" id="cancel_apt_id">
                <input type="hidden" name="status" value="Cancelled">
                <div class="modal-body" style="padding:20px;">
                    <div id="cancelInfo" style="background:var(--db-surf2);border-radius:10px;padding:14px;margin-bottom:16px;border-left:4px solid var(--db-amber);font-size:13px;"></div>
                    <div style="background:#fff5f5;border-left:4px solid var(--db-rose);padding:12px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;color:#c53030;">
                        <i class="fas fa-info-circle"></i> Are you sure you want to cancel this appointment? This cannot be undone.
                    </div>
                    <div class="db-form-group">
                        <label class="db-form-label">Reason for Cancellation</label>
                        <textarea name="cancellation_reason" class="db-form-textarea" rows="3" placeholder="Optional reason…"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-arrow-left"></i> Go Back</button>
                    <button type="submit" class="db-btn db-btn--sm" style="background:var(--db-rose);color:#fff;border:none;padding:8px 18px;border-radius:8px;font-weight:600;cursor:pointer;">
                        <i class="fas fa-times-circle"></i> Yes, Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function completeAppointment(apt) {
    document.getElementById('complete_apt_id').value = apt.appointment_id;
    new bootstrap.Modal(document.getElementById('completeModal')).show();
}

function openCancelModal(id, apt) {
    document.getElementById('cancel_apt_id').value = id;
    document.getElementById('cancelInfo').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:6px;">
            <div style="display:flex;justify-content:space-between;"><span style="color:var(--db-muted);font-weight:600;">Patient</span><span style="font-weight:700;">${apt.first_name} ${apt.last_name}</span></div>
            <div style="display:flex;justify-content:space-between;"><span style="color:var(--db-muted);font-weight:600;">Type</span><span>${apt.appointment_type}</span></div>
            <div style="display:flex;justify-content:space-between;"><span style="color:var(--db-muted);font-weight:600;">Date</span><span>${new Date(apt.appointment_date).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>
            <div style="display:flex;justify-content:space-between;"><span style="color:var(--db-muted);font-weight:600;">Time</span><span>${new Date('1970-01-01T'+apt.appointment_time).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})}</span></div>
        </div>`;
    new bootstrap.Modal(document.getElementById('cancelModal')).show();
}

function viewAppointment(apt) {
    const statusBadge = {
        'Confirmed': 'db-badge--success', 'Scheduled': 'db-badge--amber',
        'Completed': 'db-badge--sky',     'Cancelled':  'db-badge--rose',
        'No-Show':   'db-badge--muted'
    }[apt.status] || 'db-badge--muted';

    const fmt = d => new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
    const infoRow = (label, value) =>
        `<div style="display:flex;gap:12px;padding:8px 0;border-bottom:1px solid var(--db-border);font-size:13px;">
            <span style="min-width:160px;color:var(--db-muted);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;padding-top:2px;">${label}</span>
            <span style="flex:1;color:var(--db-text);">${value}</span>
        </div>`;

    const body = `
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Patient</div>
            ${infoRow('Name','<strong>'+apt.first_name+' '+apt.last_name+'</strong>')}
            ${infoRow('Contact', apt.contact_number||'N/A')}
        </div>
        <div style="margin-bottom:18px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Appointment</div>
            ${infoRow('Type','<span class="db-badge db-badge--sky">'+apt.appointment_type+'</span>')}
            ${infoRow('Status','<span class="db-badge '+statusBadge+'">'+apt.status+'</span>')}
            ${infoRow('Date', fmt(apt.appointment_date))}
            ${infoRow('Time', new Date('1970-01-01T'+apt.appointment_time).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}))}
            ${infoRow('Purpose', apt.purpose||'N/A')}
            ${apt.symptoms ? infoRow('Symptoms', apt.symptoms) : ''}
            ${apt.special_instructions ? infoRow('Instructions', apt.special_instructions) : ''}
        </div>
        ${apt.attended_by||apt.diagnosis||apt.prescription||apt.follow_up_date ? `
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);font-weight:700;margin-bottom:8px;">Consultation</div>
            ${apt.attended_by   ? infoRow('Attended By', apt.attended_by)   : ''}
            ${apt.diagnosis     ? infoRow('Diagnosis',   apt.diagnosis)     : ''}
            ${apt.prescription  ? infoRow('Prescription',apt.prescription)  : ''}
            ${apt.follow_up_date? infoRow('Follow-up',   fmt(apt.follow_up_date)) : ''}
            ${apt.notes         ? infoRow('Notes','<span style="white-space:pre-wrap;">'+apt.notes+'</span>') : ''}
        </div>` : ''}`;

    const el = document.createElement('div');
    el.className = 'modal fade'; el.setAttribute('tabindex','-1');
    el.innerHTML = `<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="db-modal-header">
            <h5 style="margin:0;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;color:#fff;"><i class="fas fa-calendar-check"></i> Appointment Details</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="padding:20px;max-height:65vh;overflow-y:auto;">${body}</div>
        <div class="modal-footer" style="padding:14px 20px;border-top:1px solid var(--db-border);">
            <button class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
        </div>
    </div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el);
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
}

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.transition='opacity .4s'; a.style.opacity='0';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>