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
        
        // Build update query based on status
        if ($status === 'Completed') {
            $attended_by = isset($_POST['attended_by']) ? trim($_POST['attended_by']) : null;
            $diagnosis = isset($_POST['diagnosis']) ? trim($_POST['diagnosis']) : null;
            $prescription = isset($_POST['prescription']) ? trim($_POST['prescription']) : null;
            $follow_up_date = isset($_POST['follow_up_date']) && $_POST['follow_up_date'] ? $_POST['follow_up_date'] : null;
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            
            $stmt = $conn->prepare("
                UPDATE tbl_health_appointments 
                SET status = ?, 
                    attended_by = ?,
                    diagnosis = ?,
                    prescription = ?,
                    follow_up_date = ?,
                    notes = CONCAT(IFNULL(notes, ''), ?)
                WHERE appointment_id = ?
            ");
            $note_text = $notes ? "\n[" . date('Y-m-d H:i') . "] " . $notes : '';
            $stmt->bind_param("ssssssi", $status, $attended_by, $diagnosis, $prescription, $follow_up_date, $note_text, $appointment_id);
        } else if ($status === 'Cancelled') {
            $cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';
            $stmt = $conn->prepare("
                UPDATE tbl_health_appointments 
                SET status = ?, 
                    notes = CONCAT(IFNULL(notes, ''), ?) 
                WHERE appointment_id = ?
            ");
            $note_text = "\n[" . date('Y-m-d H:i') . "] Cancelled by staff" . ($cancellation_reason ? ": " . $cancellation_reason : '');
            $stmt->bind_param("ssi", $status, $note_text, $appointment_id);
        } else {
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
            $stmt = $conn->prepare("
                UPDATE tbl_health_appointments 
                SET status = ?, 
                    notes = CONCAT(IFNULL(notes, ''), ?) 
                WHERE appointment_id = ?
            ");
            $note_text = $notes ? "\n[" . date('Y-m-d H:i') . "] " . $notes : '';
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

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($status_filter) {
    $where_clauses[] = "a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($date_filter) {
    $where_clauses[] = "a.appointment_date = ?";
    $params[] = $date_filter;
    $types .= "s";
} else {
    // Default to show upcoming appointments
    $where_clauses[] = "a.appointment_date >= CURDATE()";
}

if ($type_filter) {
    $where_clauses[] = "a.appointment_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

if ($search) {
    $where_clauses[] = "(r.first_name LIKE ? OR r.last_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

$where_sql = implode(" AND ", $where_clauses);

// Get appointments
$sql = "SELECT 
            a.*,
            r.first_name,
            r.last_name,
            r.contact_number,
            r.date_of_birth,
            u.username as created_by_name
        FROM tbl_health_appointments a
        JOIN tbl_residents r ON a.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON a.created_by = u.user_id
        WHERE $where_sql
        ORDER BY a.appointment_date, a.appointment_time";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$appointments = $stmt->get_result();
$stmt->close();

// Get statistics
$today_count = $conn->query("SELECT COUNT(*) as count FROM tbl_health_appointments WHERE appointment_date = CURDATE() AND status IN ('Scheduled', 'Confirmed')")->fetch_assoc()['count'];
$upcoming_count = $conn->query("SELECT COUNT(*) as count FROM tbl_health_appointments WHERE appointment_date > CURDATE() AND status IN ('Scheduled', 'Confirmed')")->fetch_assoc()['count'];
$pending_count = $conn->query("SELECT COUNT(*) as count FROM tbl_health_appointments WHERE status = 'Scheduled'")->fetch_assoc()['count'];

// Get appointment types for filter
$appointment_types = $conn->query("SELECT DISTINCT appointment_type FROM tbl_health_appointments ORDER BY appointment_type");

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-calendar-check"></i> Health Appointments</h1>
        <p class="page-subtitle">Manage health center appointments and consultations</p>
    </div>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
    </div>
<?php endif; ?>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #4299e1;">
            <i class="fas fa-calendar-day"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $today_count; ?></div>
            <div class="stat-label">Today's Appointments</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #48bb78;">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $upcoming_count; ?></div>
            <div class="stat-label">Upcoming</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #ed8936;">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $pending_count; ?></div>
            <div class="stat-label">Pending Confirmation</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <div class="search-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Search by resident name..." value="<?php echo htmlspecialchars($search); ?>">
        </div>
        
        <select name="status" class="filter-select">
            <option value="">All Statuses</option>
            <option value="Scheduled" <?php echo $status_filter === 'Scheduled' ? 'selected' : ''; ?>>Scheduled</option>
            <option value="Confirmed" <?php echo $status_filter === 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
            <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
            <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            <option value="No-Show" <?php echo $status_filter === 'No-Show' ? 'selected' : ''; ?>>No-Show</option>
        </select>
        
        <select name="type" class="filter-select">
            <option value="">All Types</option>
            <?php while ($type = $appointment_types->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($type['appointment_type']); ?>"
                    <?php echo $type_filter === $type['appointment_type'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($type['appointment_type']); ?>
                </option>
            <?php endwhile; ?>
        </select>
        
        <input type="date" name="date" class="filter-input" value="<?php echo $date_filter; ?>">
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
        
        <a href="appointments.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Clear
        </a>
    </form>
</div>

<!-- Appointments List -->
<div class="appointments-container">
    <?php if ($appointments->num_rows > 0): ?>
        <?php 
        $current_date = null;
        while ($apt = $appointments->fetch_assoc()): 
            $appointment_date = date('Y-m-d', strtotime($apt['appointment_date']));
            
            // Show date header
            if ($current_date !== $appointment_date):
                if ($current_date !== null) echo '</div>'; // Close previous day's appointments
                $current_date = $appointment_date;
                $is_today = $appointment_date === date('Y-m-d');
                $is_past = $appointment_date < date('Y-m-d');
        ?>
                <div class="date-group">
                    <div class="date-header <?php echo $is_today ? 'today' : ($is_past ? 'past' : ''); ?>">
                        <i class="fas fa-calendar-day"></i>
                        <?php echo date('l, F j, Y', strtotime($appointment_date)); ?>
                        <?php if ($is_today): ?>
                            <span class="badge badge-primary">Today</span>
                        <?php endif; ?>
                    </div>
                    <div class="appointments-day-list">
            <?php endif; ?>
            
            <?php
            $age = $apt['date_of_birth'] ? floor((time() - strtotime($apt['date_of_birth'])) / 31556926) : 'N/A';
            $status_class = strtolower(str_replace('-', '', $apt['status']));
            ?>
            
            <div class="appointment-card status-<?php echo $status_class; ?>">
                <div class="appointment-time">
                    <i class="fas fa-clock"></i>
                    <?php echo date('g:i A', strtotime($apt['appointment_time'])); ?>
                </div>
                
                <div class="appointment-details">
                    <div class="appointment-patient">
                        <strong><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></strong>
                        <span class="patient-info"><?php echo $age; ?> years old</span>
                        <span class="patient-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($apt['contact_number']); ?></span>
                    </div>
                    
                    <div class="appointment-info">
                        <span class="appointment-type">
                            <i class="fas fa-stethoscope"></i>
                            <?php echo htmlspecialchars($apt['appointment_type']); ?>
                        </span>
                        <p class="appointment-purpose"><?php echo htmlspecialchars($apt['purpose']); ?></p>
                        <?php if ($apt['symptoms']): ?>
                            <p class="appointment-symptoms">
                                <strong>Symptoms:</strong> <?php echo htmlspecialchars($apt['symptoms']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="appointment-status">
                        <span class="badge badge-<?php echo $status_class; ?>">
                            <?php echo $apt['status']; ?>
                        </span>
                        <?php if ($apt['attended_by']): ?>
                            <small><i class="fas fa-user-md"></i> <?php echo htmlspecialchars($apt['attended_by']); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="appointment-actions">
                    <?php if ($apt['status'] === 'Scheduled'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="appointment_id" value="<?php echo $apt['appointment_id']; ?>">
                            <input type="hidden" name="status" value="Confirmed">
                            <button type="submit" class="btn btn-sm btn-success" title="Confirm">
                                <i class="fas fa-check"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (in_array($apt['status'], ['Scheduled', 'Confirmed'])): ?>
                        <button class="btn btn-sm btn-primary" onclick='completeAppointment(<?php echo json_encode($apt); ?>)' title="Complete">
                            <i class="fas fa-check-double"></i>
                        </button>
                        <button class="btn btn-sm btn-warning" onclick='openCancelModal(<?php echo $apt["appointment_id"]; ?>, <?php echo json_encode($apt); ?>)' title="Cancel">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                    
                    <button class="btn btn-sm btn-info" onclick='viewAppointment(<?php echo json_encode($apt); ?>)' title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
        <?php endwhile; ?>
        </div></div> <!-- Close last day's appointments -->
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-calendar-times"></i>
            <p>No appointments found</p>
        </div>
    <?php endif; ?>
</div>

<!-- Complete Appointment Modal -->
<div id="completeAppointmentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Complete Appointment</h2>
            <button class="close-btn" onclick="closeModal('completeAppointmentModal')">&times;</button>
        </div>
        <form method="POST" class="modal-body">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="appointment_id" id="complete_appointment_id">
            <input type="hidden" name="status" value="Completed">
            
            <div class="form-group">
                <label>Attended By</label>
                <input type="text" name="attended_by" class="form-control" placeholder="Doctor/Nurse name">
            </div>
            
            <div class="form-group">
                <label>Diagnosis</label>
                <textarea name="diagnosis" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label>Prescription</label>
                <textarea name="prescription" class="form-control" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label>Follow-up Date</label>
                <input type="date" name="follow_up_date" class="form-control">
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('completeAppointmentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Complete Appointment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Appointment Modal -->
<div id="cancelAppointmentModal" class="modal">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h2 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Cancel Appointment</h2>
            <button class="close-btn" onclick="closeModal('cancelAppointmentModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="cancel-info" id="cancelInfo">
                <!-- Info will be populated by JavaScript -->
            </div>
            <p class="warning-text">
                <i class="fas fa-info-circle"></i> 
                Are you sure you want to cancel this appointment? This action cannot be undone.
            </p>
            <form method="POST" id="cancelForm">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="appointment_id" id="cancel_appointment_id">
                <input type="hidden" name="status" value="Cancelled">
                
                <div class="form-group">
                    <label>Reason for Cancellation (Optional)</label>
                    <textarea name="cancellation_reason" class="form-control" rows="3" placeholder="Please provide a reason for cancelling..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('cancelAppointmentModal')">
                        <i class="fas fa-arrow-left"></i> Go Back
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times-circle"></i> Yes, Cancel Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Appointment Modal -->
<div id="viewAppointmentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Appointment Details</h2>
            <button class="close-btn" onclick="closeModal('viewAppointmentModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewAppointmentContent"></div>
    </div>
</div>

<script>
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function completeAppointment(apt) {
    document.getElementById('complete_appointment_id').value = apt.appointment_id;
    openModal('completeAppointmentModal');
}

function openCancelModal(appointmentId, apt) {
    const modal = document.getElementById('cancelAppointmentModal');
    const cancelInfo = document.getElementById('cancelInfo');
    const appointmentIdInput = document.getElementById('cancel_appointment_id');
    
    // Set appointment ID
    appointmentIdInput.value = appointmentId;
    
    // Populate appointment info
    cancelInfo.innerHTML = `
        <div class="cancel-info-row">
            <strong>Patient:</strong>
            <span>${apt.first_name} ${apt.last_name}</span>
        </div>
        <div class="cancel-info-row">
            <strong>Type:</strong>
            <span>${apt.appointment_type}</span>
        </div>
        <div class="cancel-info-row">
            <strong>Date:</strong>
            <span>${new Date(apt.appointment_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
        </div>
        <div class="cancel-info-row">
            <strong>Time:</strong>
            <span>${new Date('1970-01-01T' + apt.appointment_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'})}</span>
        </div>
    `;
    
    openModal('cancelAppointmentModal');
}

function viewAppointment(apt) {
    const content = document.getElementById('viewAppointmentContent');
    
    const statusClass = apt.status === 'Confirmed' ? 'badge-success' : 
                       (apt.status === 'Scheduled' ? 'badge-warning' : 
                       (apt.status === 'Completed' ? 'badge-info' : 'badge-secondary'));
    
    content.innerHTML = `
        <div class="appointment-detail-row">
            <label>Patient:</label>
            <span><strong>${apt.first_name} ${apt.last_name}</strong></span>
        </div>
        <div class="appointment-detail-row">
            <label>Type:</label>
            <span><span class="badge badge-info">${apt.appointment_type}</span></span>
        </div>
        <div class="appointment-detail-row">
            <label>Date & Time:</label>
            <span>${new Date(apt.appointment_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})} at ${apt.appointment_time}</span>
        </div>
        <div class="appointment-detail-row">
            <label>Status:</label>
            <span><span class="badge ${statusClass}">${apt.status}</span></span>
        </div>
        <div class="appointment-detail-row">
            <label>Purpose:</label>
            <span>${apt.purpose}</span>
        </div>
        ${apt.symptoms ? `
        <div class="appointment-detail-row">
            <label>Symptoms:</label>
            <span>${apt.symptoms}</span>
        </div>` : ''}
        ${apt.special_instructions ? `
        <div class="appointment-detail-row">
            <label>Special Instructions:</label>
            <span>${apt.special_instructions}</span>
        </div>` : ''}
        ${apt.attended_by ? `
        <div class="appointment-detail-row">
            <label>Attended By:</label>
            <span>${apt.attended_by}</span>
        </div>` : ''}
        ${apt.diagnosis ? `
        <div class="appointment-detail-row">
            <label>Diagnosis:</label>
            <span>${apt.diagnosis}</span>
        </div>` : ''}
        ${apt.prescription ? `
        <div class="appointment-detail-row">
            <label>Prescription:</label>
            <span>${apt.prescription}</span>
        </div>` : ''}
        ${apt.follow_up_date ? `
        <div class="appointment-detail-row">
            <label>Follow-up Date:</label>
            <span>${new Date(apt.follow_up_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
        </div>` : ''}
        ${apt.notes ? `
        <div class="appointment-detail-row">
            <label>Notes:</label>
            <span style="white-space: pre-wrap;">${apt.notes}</span>
        </div>` : ''}
    `;
    
    openModal('viewAppointmentModal');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<style>
.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.alert-success {
    background: #d4edda;
    border-left: 4px solid #28a745;
    color: #155724;
}

.alert-error {
    background: #f8d7da;
    border-left: 4px solid #dc3545;
    color: #721c24;
}

.appointments-container {
    margin-top: 1.5rem;
}

.date-group {
    margin-bottom: 2rem;
}

.date-header {
    background: #f7fafc;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.date-header.today {
    background: #bee3f8;
    color: #2c5282;
}

.date-header.past {
    opacity: 0.7;
}

.appointments-day-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.appointment-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: grid;
    grid-template-columns: 100px 1fr auto;
    gap: 1.5rem;
    align-items: start;
    border-left: 4px solid #e2e8f0;
    transition: all 0.2s;
}

.appointment-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.appointment-card.status-confirmed {
    border-left-color: #48bb78;
}

.appointment-card.status-completed {
    border-left-color: #4299e1;
    opacity: 0.8;
}

.appointment-card.status-cancelled {
    border-left-color: #f56565;
    opacity: 0.6;
}

.appointment-time {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 8px;
    font-weight: 600;
    color: #2d3748;
}

.appointment-details {
    flex: 1;
}

.appointment-patient strong {
    font-size: 1.1rem;
    color: #2d3748;
    display: block;
}

.patient-info, .patient-contact {
    display: inline-block;
    margin-right: 1rem;
    color: #718096;
    font-size: 0.875rem;
}

.appointment-type {
    display: inline-block;
    margin: 0.5rem 0;
    padding: 0.25rem 0.75rem;
    background: #edf2f7;
    border-radius: 4px;
    font-size: 0.875rem;
}

.appointment-purpose {
    margin: 0.5rem 0;
    color: #4a5568;
}

.appointment-symptoms {
    margin: 0.5rem 0;
    color: #718096;
    font-size: 0.875rem;
}

.appointment-actions {
    display: flex;
    gap: 0.5rem;
    flex-direction: column;
}

.appointment-detail-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #e2e8f0;
}

.appointment-detail-row:last-child {
    border-bottom: none;
}

.appointment-detail-row label {
    font-weight: 600;
    color: #718096;
    width: 180px;
    flex-shrink: 0;
}

.appointment-detail-row span {
    color: #2d3748;
    flex: 1;
}

/* Cancel Modal Styles */
.modal-sm {
    max-width: 500px;
}

.cancel-info {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
    border-left: 4px solid #ffc107;
}

.cancel-info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #dee2e6;
}

.cancel-info-row:last-child {
    border-bottom: none;
}

.cancel-info-row strong {
    color: #495057;
}

.cancel-info-row span {
    color: #212529;
    font-weight: 500;
}

.warning-text {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 1rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    color: #856404;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.warning-text i {
    margin-top: 2px;
}

.modal-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
    justify-content: flex-end;
}

.btn-danger {
    background: #dc3545;
    color: white;
    border: none;
}

.btn-danger:hover {
    background: #c82333;
}

<?php include '../../includes/footer.php'; ?>