<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Resident']);

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT r.is_verified,r.id_photo,r.first_name,r.last_name,r.resident_id FROM tbl_users u JOIN tbl_residents r ON u.resident_id=r.resident_id WHERE u.user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data || $user_data['is_verified'] != 1) { header("Location: health-verification.php"); exit(); }

$page_title  = 'Book Appointment';
$resident_id = $user_data['resident_id'];

// BOOK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_appointment') {
    $appointment_type     = trim($_POST['appointment_type']);
    $appointment_date     = $_POST['appointment_date'];
    $appointment_time     = $_POST['appointment_time'];
    $contact_number       = trim($_POST['contact_number']);
    $purpose              = trim($_POST['purpose']);
    $symptoms             = isset($_POST['symptoms'])             ? trim($_POST['symptoms'])             : '';
    $special_instructions = isset($_POST['special_instructions']) ? trim($_POST['special_instructions']) : '';

    if (strtotime($appointment_date) < strtotime('+1 day', strtotime(date('Y-m-d')))) {
        $_SESSION['error_message'] = "Appointments must be booked at least 1 day in advance.";
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_health_appointments (resident_id,appointment_type,appointment_date,appointment_time,contact_number,purpose,symptoms,special_instructions,status,created_by) VALUES (?,?,?,?,?,?,?,?,'Scheduled',?)");
        $stmt->bind_param("isssssssi", $resident_id, $appointment_type, $appointment_date, $appointment_time, $contact_number, $purpose, $symptoms, $special_instructions, $user_id);
        if ($stmt->execute()) {
            $appointment_id = $stmt->insert_id;
            $stmt->close();
            if ($appointment_id > 0) {
                $resident_name        = $user_data['first_name'] . ' ' . $user_data['last_name'];
                $notification_title   = "New Health Appointment Booked";
                $notification_message = "$resident_name has booked a $appointment_type appointment for " . date('F j, Y', strtotime($appointment_date)) . " at " . date('g:i A', strtotime($appointment_time)) . ". Purpose: $purpose";
                $admin_users = $conn->query("SELECT user_id FROM tbl_users WHERE role IN ('Admin','Staff','Super Admin','Super Administrator')");
                if ($admin_users) { while ($admin = $admin_users->fetch_assoc()) { createNotification($conn, $admin['user_id'], $notification_title, $notification_message, 'appointment_booked', $appointment_id, 'appointment'); } }
                $_SESSION['success_message'] = "Appointment booked successfully! You will be notified once it's confirmed.";
            } else { $_SESSION['error_message'] = "Appointment was created but there was an issue. Please contact admin."; }
        } else { $_SESSION['error_message'] = "Error booking appointment. Please try again."; $stmt->close(); }
    }
    header("Location: book-appointment.php"); exit;
}

// CANCEL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_appointment') {
    $appointment_id      = (int)$_POST['appointment_id'];
    $cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';
    if ($appointment_id <= 0) { $_SESSION['error_message'] = "Invalid appointment ID."; header("Location: book-appointment.php"); exit; }
    $stmt = $conn->prepare("SELECT a.*,r.first_name,r.last_name FROM tbl_health_appointments a JOIN tbl_residents r ON a.resident_id=r.resident_id WHERE a.appointment_id=? AND a.resident_id=?");
    $stmt->bind_param("ii", $appointment_id, $resident_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $appointment = $result->fetch_assoc(); $stmt->close();
        if (in_array($appointment['status'], ['Scheduled','Confirmed'])) {
            $notes = "\n[" . date('Y-m-d H:i') . "] Cancelled by resident" . ($cancellation_reason ? ": $cancellation_reason" : '');
            $stmt = $conn->prepare("UPDATE tbl_health_appointments SET status='Cancelled',notes=CONCAT(IFNULL(notes,''),?) WHERE appointment_id=?");
            $stmt->bind_param("si", $notes, $appointment_id);
            if ($stmt->execute()) {
                $stmt->close();
                $resident_name        = $appointment['first_name'] . ' ' . $appointment['last_name'];
                $notification_title   = "Appointment Cancelled";
                $notification_message = "$resident_name has cancelled their " . $appointment['appointment_type'] . " appointment scheduled for " . date('F j, Y', strtotime($appointment['appointment_date'])) . " at " . date('g:i A', strtotime($appointment['appointment_time'])) . ($cancellation_reason ? ". Reason: $cancellation_reason" : ".");
                $admin_users = $conn->query("SELECT user_id FROM tbl_users WHERE role IN ('Admin','Staff','Super Admin','Super Administrator')");
                if ($admin_users) { while ($admin = $admin_users->fetch_assoc()) { createNotification($conn, $admin['user_id'], $notification_title, $notification_message, 'appointment_cancelled', $appointment_id, 'appointment'); } }
                $_SESSION['success_message'] = "Appointment cancelled successfully.";
            } else { $_SESSION['error_message'] = "Error cancelling appointment."; $stmt->close(); }
        } else { $_SESSION['error_message'] = "This appointment cannot be cancelled."; }
    } else { $_SESSION['error_message'] = "Invalid appointment or access denied."; $stmt->close(); }
    header("Location: book-appointment.php"); exit;
}

$stmt = $conn->prepare("SELECT * FROM tbl_health_appointments WHERE resident_id=? ORDER BY appointment_date DESC,appointment_time DESC LIMIT 10");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$appointments = $stmt->get_result();
$stmt->close();

include '../../includes/header.php';
?>

<style>
:root { --rs-blue:#4299e1; --rs-green:#48bb78; --rs-orange:#ed8936; --rs-purple:#9f7aea; --rs-red:#f56565; --rs-radius:12px; --rs-shadow:0 2px 8px rgba(0,0,0,.08); --rs-shadow-md:0 4px 16px rgba(0,0,0,.12); }

.rs-page { padding:1.5rem; }
.rs-page-header { margin-bottom:1.5rem; }
.rs-page-header h2 { font-size:1.4rem; font-weight:700; color:#2d3748; margin:0 0 4px; display:flex; align-items:center; gap:10px; }
.rs-page-header p  { color:#718096; margin:0; font-size:.9rem; }

.rs-alert { border:none; border-radius:var(--rs-radius); padding:1rem 1.25rem; box-shadow:var(--rs-shadow); border-left:4px solid; display:flex; align-items:center; gap:.75rem; margin-bottom:1.25rem; font-size:.9rem; }
.rs-alert--success { background:linear-gradient(135deg,#d1f4e0,#e7f9ee); border-left-color:#198754; color:#0f5132; }
.rs-alert--danger  { background:linear-gradient(135deg,#ffd6d6,#ffe5e5); border-left-color:#dc3545; color:#842029; }
.rs-alert__close   { margin-left:auto; background:none; border:none; cursor:pointer; opacity:.6; font-size:1.1rem; }
.rs-alert__close:hover { opacity:1; }

.rs-card { background:#fff; border-radius:var(--rs-radius); box-shadow:var(--rs-shadow); overflow:hidden; margin-bottom:1.5rem; transition:box-shadow .2s; }
.rs-card:hover { box-shadow:var(--rs-shadow-md); }
.rs-card__header { background:linear-gradient(135deg,#f8f9fa,#fff); border-bottom:2px solid #e9ecef; padding:1.1rem 1.5rem; display:flex; align-items:center; gap:.6rem; }
.rs-card__header h5 { font-weight:700; font-size:1rem; margin:0; color:#2d3748; }
.rs-card__header-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:.9rem; color:#fff; flex-shrink:0; }
.rs-card__body { padding:1.5rem; }

.rs-form-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:1.1rem; }
.rs-form-group { display:flex; flex-direction:column; }
.rs-form-group.full { grid-column:1/-1; }
.rs-form-label { font-weight:600; color:#495057; font-size:.875rem; margin-bottom:.4rem; }
.rs-form-control { border:2px solid #e9ecef; border-radius:8px; padding:.6rem .9rem; font-size:.95rem; transition:border-color .2s,box-shadow .2s; width:100%; }
.rs-form-control:focus { border-color:var(--rs-blue); box-shadow:0 0 0 .2rem rgba(66,153,225,.15); outline:none; }
textarea.rs-form-control { resize:vertical; }
small.rs-hint { color:#718096; font-size:.78rem; margin-top:3px; }

.rs-btn { border-radius:8px; padding:.55rem 1.25rem; font-weight:600; font-size:.9rem; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem; transition:all .2s; box-shadow:0 2px 6px rgba(0,0,0,.1); }
.rs-btn:hover { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,0,0,.15); }
.rs-btn--primary   { background:linear-gradient(135deg,#4299e1,#3182ce); color:#fff; }
.rs-btn--secondary { background:#e2e8f0; color:#4a5568; }
.rs-btn--danger    { background:linear-gradient(135deg,#f56565,#e53e3e); color:#fff; }
.rs-btn--icon      { width:32px; height:32px; padding:0; justify-content:center; border-radius:6px; }
.rs-btn--icon.view    { background:#bee3f8; color:#2b6cb0; }
.rs-btn--icon.cancel  { background:#fed7d7; color:#c53030; }

.rs-divider { border-top:2px solid #e9ecef; margin:1.1rem 0 0; padding-top:1.1rem; display:flex; gap:.6rem; }

/* Table */
.rs-table-wrap { overflow-x:auto; }
.rs-table { width:100%; border-collapse:collapse; }
.rs-table thead th { background:linear-gradient(135deg,#f8f9fa,#fff); border-bottom:2px solid #dee2e6; font-weight:700; font-size:.8rem; text-transform:uppercase; letter-spacing:.5px; color:#495057; padding:.8rem 1rem; }
.rs-table tbody tr { border-bottom:1px solid #f1f3f5; transition:background .15s; }
.rs-table tbody tr:hover { background:rgba(66,153,225,.04); }
.rs-table tbody td { padding:.8rem 1rem; vertical-align:middle; font-size:.9rem; }

.rs-badge { display:inline-block; padding:.3rem .75rem; border-radius:50px; font-size:.78rem; font-weight:600; letter-spacing:.2px; }
.rs-badge--blue    { background:#bee3f8; color:#2c5282; }
.rs-badge--green   { background:#c6f6d5; color:#276749; }
.rs-badge--yellow  { background:#fefcbf; color:#744210; }
.rs-badge--gray    { background:#e2e8f0; color:#4a5568; }
.rs-badge--purple  { background:#e9d8fd; color:#553c9a; }
.rs-badge--red     { background:#fed7d7; color:#742a2a; }

.rs-empty { text-align:center; padding:3rem; color:#a0aec0; }
.rs-empty i { font-size:3rem; margin-bottom:1rem; display:block; opacity:.4; }
.rs-empty p { font-size:.95rem; margin:0; }

/* Modals */
.rs-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(3px); z-index:9999; align-items:center; justify-content:center; }
.rs-modal-overlay.show { display:flex; }
.rs-modal { background:#fff; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,.18); width:90%; max-width:580px; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; }
.rs-modal__header { padding:1.25rem 1.5rem; border-bottom:2px solid #e9ecef; display:flex; justify-content:space-between; align-items:center; background:linear-gradient(135deg,#f8f9fa,#fff); }
.rs-modal__title  { margin:0; font-size:1.05rem; font-weight:700; color:#2d3748; display:flex; align-items:center; gap:.5rem; }
.rs-modal__close  { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#718096; width:30px; height:30px; display:flex; align-items:center; justify-content:center; border-radius:50%; transition:background .2s; }
.rs-modal__close:hover { background:#e9ecef; color:#2d3748; }
.rs-modal__body   { padding:1.5rem; overflow-y:auto; }
.rs-modal__footer { padding:1rem 1.5rem; border-top:2px solid #e9ecef; display:flex; justify-content:flex-end; gap:.6rem; background:#f8f9fa; }

.rs-detail-row { display:flex; padding:.65rem 0; border-bottom:1px solid #e9ecef; font-size:.9rem; }
.rs-detail-row:last-child { border-bottom:none; }
.rs-detail-row label { font-weight:600; color:#718096; width:170px; flex-shrink:0; font-size:.8rem; text-transform:uppercase; letter-spacing:.3px; padding-top:2px; }
.rs-detail-row span  { flex:1; color:#2d3748; }

.rs-cancel-info { background:linear-gradient(135deg,#fffbf0,#fff8e1); border-left:4px solid #ed8936; border-radius:8px; padding:1rem; margin-bottom:1rem; font-size:.9rem; }
.rs-cancel-info-row { display:flex; justify-content:space-between; padding:.35rem 0; border-bottom:1px solid rgba(0,0,0,.07); }
.rs-cancel-info-row:last-child { border-bottom:none; }
.rs-warning-box { background:linear-gradient(135deg,#fffbf0,#fff8e1); border-left:4px solid #ed8936; border-radius:8px; padding:.9rem 1rem; margin-bottom:1rem; font-size:.875rem; color:#744210; display:flex; gap:.5rem; }

@media(max-width:768px){ .rs-form-grid{ grid-template-columns:1fr; } }
</style>

<div class="rs-page">
    <div class="rs-page-header">
        <h2><i class="fas fa-calendar-plus" style="color:var(--rs-blue);"></i> Book Appointment</h2>
        <p>Schedule your health center visit</p>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="rs-alert rs-alert--success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
        <button class="rs-alert__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="rs-alert rs-alert--danger">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
        <button class="rs-alert__close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <!-- Book Form -->
    <div class="rs-card">
        <div class="rs-card__header">
            <div class="rs-card__header-icon" style="background:var(--rs-blue);"><i class="fas fa-edit"></i></div>
            <h5>Book New Appointment</h5>
        </div>
        <div class="rs-card__body">
            <form method="POST" id="appointmentForm">
                <input type="hidden" name="action" value="book_appointment">
                <div class="rs-form-grid">
                    <div class="rs-form-group">
                        <label class="rs-form-label">Appointment Type <span style="color:var(--rs-red);">*</span></label>
                        <select name="appointment_type" class="rs-form-control" required>
                            <option value="">Select Type</option>
                            <?php foreach(['General Check-up','Vaccination','Prenatal','Dental','Family Planning','Medical Consultation','Laboratory','Other'] as $t): ?>
                            <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rs-form-group">
                        <label class="rs-form-label">Preferred Date <span style="color:var(--rs-red);">*</span></label>
                        <input type="date" name="appointment_date" class="rs-form-control" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                        <small class="rs-hint">Must be booked at least 1 day in advance</small>
                    </div>
                    <div class="rs-form-group">
                        <label class="rs-form-label">Preferred Time <span style="color:var(--rs-red);">*</span></label>
                        <select name="appointment_time" class="rs-form-control" required>
                            <option value="">Select Time</option>
                            <?php foreach(['08:00:00'=>'8:00 AM','09:00:00'=>'9:00 AM','10:00:00'=>'10:00 AM','11:00:00'=>'11:00 AM','13:00:00'=>'1:00 PM','14:00:00'=>'2:00 PM','15:00:00'=>'3:00 PM','16:00:00'=>'4:00 PM'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>"><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rs-form-group">
                        <label class="rs-form-label">Contact Number <span style="color:var(--rs-red);">*</span></label>
                        <input type="text" name="contact_number" class="rs-form-control" required placeholder="09XX XXX XXXX">
                    </div>
                    <div class="rs-form-group full">
                        <label class="rs-form-label">Purpose / Reason for Visit <span style="color:var(--rs-red);">*</span></label>
                        <textarea name="purpose" class="rs-form-control" rows="3" required placeholder="Describe your reason for booking this appointment"></textarea>
                    </div>
                    <div class="rs-form-group full">
                        <label class="rs-form-label">Symptoms (if any)</label>
                        <textarea name="symptoms" class="rs-form-control" rows="2" placeholder="Describe any symptoms you're experiencing"></textarea>
                    </div>
                    <div class="rs-form-group full">
                        <label class="rs-form-label">Special Instructions</label>
                        <textarea name="special_instructions" class="rs-form-control" rows="2" placeholder="Any special requests or concerns"></textarea>
                    </div>
                </div>
                <div class="rs-divider">
                    <button type="submit" class="rs-btn rs-btn--primary"><i class="fas fa-calendar-check"></i> Book Appointment</button>
                    <button type="reset"  class="rs-btn rs-btn--secondary"><i class="fas fa-redo"></i> Reset</button>
                </div>
            </form>
        </div>
    </div>

    <!-- My Appointments -->
    <div class="rs-card">
        <div class="rs-card__header">
            <div class="rs-card__header-icon" style="background:var(--rs-purple);"><i class="fas fa-calendar-alt"></i></div>
            <h5>My Appointments</h5>
        </div>
        <div class="rs-card__body" style="padding:0;">
            <div class="rs-table-wrap">
                <table class="rs-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($appointments->num_rows > 0): ?>
                        <?php while ($apt = $appointments->fetch_assoc()):
                            $sta_class = match($apt['status']) {
                                'Confirmed'  => 'rs-badge--green',
                                'Scheduled'  => 'rs-badge--yellow',
                                'Completed'  => 'rs-badge--blue',
                                'Cancelled'  => 'rs-badge--gray',
                                default      => 'rs-badge--yellow',
                            };
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?></td>
                            <td><?php echo date('g:i A', strtotime($apt['appointment_time'])); ?></td>
                            <td><span class="rs-badge rs-badge--blue"><?php echo htmlspecialchars($apt['appointment_type']); ?></span></td>
                            <td style="color:#4a5568;"><?php echo htmlspecialchars(mb_strimwidth($apt['purpose'], 0, 50, '…')); ?></td>
                            <td><span class="rs-badge <?php echo $sta_class; ?>"><?php echo $apt['status']; ?></span></td>
                            <td>
                                <div style="display:flex;gap:5px;">
                                    <button class="rs-btn rs-btn--icon view" onclick='viewAppointment(<?php echo json_encode($apt); ?>)' title="View"><i class="fas fa-eye"></i></button>
                                    <?php if (in_array($apt['status'], ['Scheduled','Confirmed'])): ?>
                                    <button class="rs-btn rs-btn--icon cancel" onclick="openCancelModal(<?php echo intval($apt['appointment_id']); ?>, <?php echo htmlspecialchars(json_encode($apt), ENT_QUOTES); ?>)" title="Cancel"><i class="fas fa-times"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><div class="rs-empty"><i class="fas fa-calendar"></i><p>No appointments yet</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="rs-modal-overlay">
    <div class="rs-modal">
        <div class="rs-modal__header">
            <div class="rs-modal__title"><i class="fas fa-calendar-check" style="color:var(--rs-blue);"></i> Appointment Details</div>
            <button class="rs-modal__close" onclick="closeModal('viewModal')">×</button>
        </div>
        <div class="rs-modal__body" id="viewModalBody"></div>
        <div class="rs-modal__footer">
            <button class="rs-btn rs-btn--secondary" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div id="cancelModal" class="rs-modal-overlay">
    <div class="rs-modal">
        <div class="rs-modal__header">
            <div class="rs-modal__title"><i class="fas fa-exclamation-triangle" style="color:var(--rs-orange);"></i> Cancel Appointment</div>
            <button class="rs-modal__close" onclick="closeModal('cancelModal')">×</button>
        </div>
        <div class="rs-modal__body">
            <div class="rs-cancel-info" id="cancelInfo"></div>
            <div class="rs-warning-box"><i class="fas fa-info-circle" style="margin-top:2px;"></i> Are you sure you want to cancel this appointment? This action cannot be undone.</div>
            <form method="POST" id="cancelForm">
                <input type="hidden" name="action" value="cancel_appointment">
                <input type="hidden" name="appointment_id" id="cancelAptId">
                <div class="rs-form-group">
                    <label class="rs-form-label">Reason for Cancellation (Optional)</label>
                    <textarea name="cancellation_reason" class="rs-form-control" rows="3" placeholder="Please provide a reason…"></textarea>
                </div>
            </form>
        </div>
        <div class="rs-modal__footer">
            <button class="rs-btn rs-btn--secondary" onclick="closeModal('cancelModal')"><i class="fas fa-arrow-left"></i> Go Back</button>
            <button class="rs-btn rs-btn--danger" onclick="document.getElementById('cancelForm').submit()"><i class="fas fa-times-circle"></i> Yes, Cancel</button>
        </div>
    </div>
</div>

<script>
function viewAppointment(apt) {
    const statusBadge = {Confirmed:'rs-badge--green',Scheduled:'rs-badge--yellow',Completed:'rs-badge--blue',Cancelled:'rs-badge--gray'}[apt.status]||'rs-badge--gray';
    const fmt = d => new Date(d+'T00:00:00').toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
    const row = (l,v) => `<div class="rs-detail-row"><label>${l}</label><span>${v}</span></div>`;
    document.getElementById('viewModalBody').innerHTML =
        row('Type',`<span class="rs-badge rs-badge--blue">${apt.appointment_type}</span>`) +
        row('Date', fmt(apt.appointment_date)) +
        row('Time', new Date('1970-01-01T'+apt.appointment_time).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})) +
        row('Status',`<span class="rs-badge ${statusBadge}">${apt.status}</span>`) +
        row('Purpose', apt.purpose) +
        (apt.symptoms ? row('Symptoms', apt.symptoms) : '') +
        row('Contact', apt.contact_number||'Not provided') +
        (apt.special_instructions ? row('Special Instructions', apt.special_instructions) : '') +
        (apt.notes ? row('Notes', apt.notes.replace(/\n/g,'<br>')) : '');
    openModal('viewModal');
}

function openCancelModal(appointmentId, apt) {
    appointmentId = parseInt(appointmentId);
    if (isNaN(appointmentId)||appointmentId<=0) { alert('Invalid appointment ID'); return; }
    document.getElementById('cancelAptId').value = appointmentId;
    document.getElementById('cancelInfo').innerHTML =
        `<div class="rs-cancel-info-row"><strong>Appointment ID</strong><span>#${appointmentId}</span></div>
         <div class="rs-cancel-info-row"><strong>Type</strong><span>${apt.appointment_type}</span></div>
         <div class="rs-cancel-info-row"><strong>Date</strong><span>${new Date(apt.appointment_date+'T00:00:00').toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'})}</span></div>
         <div class="rs-cancel-info-row"><strong>Time</strong><span>${new Date('1970-01-01T'+apt.appointment_time).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})}</span></div>`;
    openModal('cancelModal');
}

function openModal(id)  { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
window.onclick = e => { if (e.target.classList.contains('rs-modal-overlay')) e.target.classList.remove('show'); };

document.getElementById('appointmentForm').addEventListener('submit', function(e) {
    const date = new Date(document.querySelector('[name="appointment_date"]').value);
    const today = new Date(); today.setHours(0,0,0,0);
    if (date <= today) { e.preventDefault(); alert('Please select a date at least 1 day from today.'); }
});

setTimeout(() => { document.querySelectorAll('.rs-alert').forEach(a => { a.style.transition='opacity .4s'; a.style.opacity='0'; setTimeout(()=>a.remove(),400); }); }, 5000);
</script>

<?php include '../../includes/footer.php'; ?>