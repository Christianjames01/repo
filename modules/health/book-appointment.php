    <?php
    require_once '../../config/config.php';
    require_once '../../includes/auth_functions.php';
    require_once '../../includes/functions.php'; 
    requireLogin();
    requireRole(['Resident']);

    $user_id = $_SESSION['user_id'];

    // Check verification status FIRST
    $stmt = $conn->prepare("
        SELECT r.is_verified, r.id_photo, r.first_name, r.last_name, r.resident_id
        FROM tbl_users u
        JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $stmt->close();

    // If not verified, redirect to health verification page
    if (!$user_data || $user_data['is_verified'] != 1) {
        header("Location: health-verification.php");
        exit();
    }

    // Continue with normal page - user is verified
    $page_title = 'Book Appointment';
    $resident_id = $user_data['resident_id'];

    // HANDLE FORM SUBMISSION FOR BOOKING
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_appointment') {
        $appointment_type = trim($_POST['appointment_type']);
        $appointment_date = $_POST['appointment_date'];
        $appointment_time = $_POST['appointment_time'];
        $contact_number = trim($_POST['contact_number']);
        $purpose = trim($_POST['purpose']);
        $symptoms = isset($_POST['symptoms']) ? trim($_POST['symptoms']) : '';
        $special_instructions = isset($_POST['special_instructions']) ? trim($_POST['special_instructions']) : '';
        
        // Validate date is at least 1 day in advance
        $selected_date = strtotime($appointment_date);
        $tomorrow = strtotime('+1 day', strtotime(date('Y-m-d')));
        
        if ($selected_date < $tomorrow) {
            $_SESSION['error_message'] = "Appointments must be booked at least 1 day in advance.";
        } else {
            // Insert appointment
            $stmt = $conn->prepare("
                INSERT INTO tbl_health_appointments 
                (resident_id, appointment_type, appointment_date, appointment_time, 
                contact_number, purpose, symptoms, special_instructions, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled', ?)
            ");
            $stmt->bind_param("isssssssi", $resident_id, $appointment_type, $appointment_date, 
                $appointment_time, $contact_number, $purpose, $symptoms, $special_instructions, $user_id);
            
        if ($stmt->execute()) {
    $appointment_id = $stmt->insert_id; // ← safer: read before close
    $stmt->close();
                
                if ($appointment_id > 0) {
                    $resident_name = $user_data['first_name'] . ' ' . $user_data['last_name'];
                    $notification_title = "New Health Appointment Booked";
                    $notification_message = "$resident_name has booked a $appointment_type appointment for " . 
                                        date('F j, Y', strtotime($appointment_date)) . " at " . 
                                        date('g:i A', strtotime($appointment_time)) . ". Purpose: $purpose";

                    $admin_users = $conn->query("
                        SELECT user_id FROM tbl_users 
                        WHERE role IN ('Admin', 'Staff', 'Super Admin', 'Super Administrator')
                    ");

                    if ($admin_users) {
                        while ($admin = $admin_users->fetch_assoc()) {
                            createNotification(
                                $conn,
                                $admin['user_id'],
                                $notification_title,
                                $notification_message,
                                'appointment_booked',
                                $appointment_id,
                                'appointment'
                            );
                        }
                    }

                    $_SESSION['success_message'] = "Appointment booked successfully! You will be notified once it's confirmed.";
                } else {
                    error_log("ERROR: Failed to get appointment_id after insert. insert_id = " . $appointment_id);
                    $_SESSION['error_message'] = "Appointment was created but there was an issue. Please contact admin.";
                }
            } else {
                error_log("ERROR: Failed to insert appointment: " . $stmt->error);
                $_SESSION['error_message'] = "Error booking appointment. Please try again.";
                $stmt->close();
            }
        }
        
        header("Location: book-appointment.php");
        exit;
    }

    // HANDLE APPOINTMENT CANCELLATION
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_appointment') {
        $appointment_id = (int)$_POST['appointment_id'];
        $cancellation_reason = isset($_POST['cancellation_reason']) ? trim($_POST['cancellation_reason']) : '';
        
        // Verify appointment_id is valid
        if ($appointment_id <= 0) {
            $_SESSION['error_message'] = "Invalid appointment ID.";
            header("Location: book-appointment.php");
            exit;
        }
        
        // Verify appointment belongs to this resident
        $stmt = $conn->prepare("
            SELECT a.*, r.first_name, r.last_name 
            FROM tbl_health_appointments a
            JOIN tbl_residents r ON a.resident_id = r.resident_id
            WHERE a.appointment_id = ? AND a.resident_id = ?
        ");
        $stmt->bind_param("ii", $appointment_id, $resident_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $appointment = $result->fetch_assoc();
            $stmt->close();
            
            // Check if appointment can be cancelled
            if (in_array($appointment['status'], ['Scheduled', 'Confirmed'])) {
                // Update appointment status
                $notes = "\n[" . date('Y-m-d H:i') . "] Cancelled by resident" . 
                        ($cancellation_reason ? ": " . $cancellation_reason : '');
                
                $stmt = $conn->prepare("
                    UPDATE tbl_health_appointments 
                    SET status = 'Cancelled', 
                        notes = CONCAT(IFNULL(notes, ''), ?)
                    WHERE appointment_id = ?
                ");
                $stmt->bind_param("si", $notes, $appointment_id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    
                    $resident_name = $appointment['first_name'] . ' ' . $appointment['last_name'];
                    
                    // Create notifications for Admin and Staff
                    $notification_title = "Appointment Cancelled";
                    $notification_message = "$resident_name has cancelled their " . $appointment['appointment_type'] . 
                                        " appointment scheduled for " . date('F j, Y', strtotime($appointment['appointment_date'])) .
                                        " at " . date('g:i A', strtotime($appointment['appointment_time'])) .
                                        ($cancellation_reason ? ". Reason: $cancellation_reason" : ".");
                    
                    // Get all Admin and Staff users
                    $admin_users = $conn->query("
                        SELECT user_id FROM tbl_users 
                        WHERE role IN ('Admin', 'Staff', 'Super Admin', 'Super Administrator')
                    ");
                    
                    if ($admin_users) {
                        while ($admin = $admin_users->fetch_assoc()) {
                            createNotification(
                                $conn,
                                $admin['user_id'],
                                $notification_title,
                                $notification_message,
                                'appointment_cancelled',
                                $appointment_id,  // Now this will be the actual ID
                                'appointment'
                            );
                        }
                    }
                    
                    $_SESSION['success_message'] = "Appointment cancelled successfully.";
                } else {
                    $_SESSION['error_message'] = "Error cancelling appointment.";
                    $stmt->close();
                }
            } else {
                $_SESSION['error_message'] = "This appointment cannot be cancelled.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid appointment or access denied.";
            $stmt->close();
        }
        
        header("Location: book-appointment.php");
        exit;
    }

    // Get my appointments from tbl_health_appointments
    $stmt = $conn->prepare("
        SELECT * FROM tbl_health_appointments 
        WHERE resident_id = ? 
        ORDER BY appointment_date DESC, appointment_time DESC
        LIMIT 10
    ");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $appointments = $stmt->get_result();
    $stmt->close();

    include '../../includes/header.php';
    ?>

    <style>
    /* Enhanced Modern Styles - EXACT MATCH from view-incidents.php */
    :root {
        --transition-speed: 0.3s;
        --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
        --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
        --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
        --border-radius: 12px;
        --border-radius-lg: 16px;
    }

    /* Card Enhancements - EXACT match */
    .card {
        border: none;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-sm);
        transition: all var(--transition-speed) ease;
        overflow: hidden;
    }

    .card:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-4px);
    }

    .card-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-bottom: 2px solid #e9ecef;
        padding: 1.25rem 1.5rem;
        border-radius: var(--border-radius) var(--border-radius) 0 0 !important;
    }

    .card-header h5 {
        font-weight: 700;
        font-size: 1.1rem;
        margin: 0;
        display: flex;
        align-items: center;
    }

    .card-body {
        padding: 1.75rem;
    }

    /* Alert Enhancements - EXACT match */
    .alert {
        border: none;
        border-radius: var(--border-radius);
        padding: 1.25rem 1.5rem;
        box-shadow: var(--shadow-sm);
        border-left: 4px solid;
    }

    .alert-success {
        background: linear-gradient(135deg, #d1f4e0 0%, #e7f9ee 100%);
        border-left-color: #198754;
        color: #0f5132;
    }

    .alert-danger, .alert-error {
        background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
        border-left-color: #dc3545;
        color: #842029;
    }

    .alert i {
        font-size: 1.1rem;
    }

    /* Enhanced Badges */
    .badge {
        font-weight: 600;
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-size: 0.85rem;
        letter-spacing: 0.3px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    /* Enhanced Buttons */
    .btn {
        border-radius: 8px;
        padding: 0.625rem 1.5rem;
        font-weight: 600;
        transition: all var(--transition-speed) ease;
        border: none;
        box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn-icon {
        width: 32px;
        height: 32px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        margin: 0 2px;
    }

    /* Form Enhancements */
    .form-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .form-control, .form-select {
        border: 2px solid #e9ecef;
        border-radius: 8px;
        padding: 0.625rem 1rem;
        transition: all var(--transition-speed) ease;
        font-size: 0.95rem;
    }

    .form-control:focus, .form-select:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
        outline: none;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    /* Table Enhancements */
    .table {
        margin-bottom: 0;
    }

    .table thead th {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-bottom: 2px solid #dee2e6;
        font-weight: 700;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #495057;
        padding: 1rem;
    }

    .table tbody tr {
        transition: all var(--transition-speed) ease;
        border-bottom: 1px solid #f1f3f5;
    }

    .table tbody tr:hover {
        background: linear-gradient(135deg, rgba(13, 110, 253, 0.03) 0%, rgba(13, 110, 253, 0.05) 100%);
    }

    .table tbody td {
        padding: 1rem;
        vertical-align: middle;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1.5rem;
        opacity: 0.3;
    }

    .empty-state h3 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    .empty-state p {
        font-size: 1.1rem;
        margin-bottom: 0;
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
    }

    .modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-content {
        background: white;
        border-radius: var(--border-radius-lg);
        box-shadow: var(--shadow-lg);
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    }

    .modal-title {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        color: #2d3748;
    }

    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #718096;
        transition: all var(--transition-speed) ease;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }

    .close-btn:hover {
        background: #e9ecef;
        color: #2d3748;
    }

    .modal-body {
        padding: 1.75rem;
        overflow-y: auto;
    }

    .appointment-detail-row {
        display: flex;
        padding: 0.75rem 0;
        border-bottom: 1px solid #e9ecef;
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

    /* Cancel Modal */
    .cancel-info {
        background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        border-left: 4px solid #ffc107;
    }

    .cancel-info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .cancel-info-row:last-child {
        border-bottom: none;
    }

    .warning-text {
        background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%);
        border-left: 4px solid #ffc107;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        color: #856404;
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .modal-actions {
        display: flex;
        gap: 1rem;
        margin-top: 1.5rem;
        justify-content: flex-end;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Smooth Scrolling */
    html {
        scroll-behavior: smooth;
    }
    </style>

    <div class="container-fluid py-4">
        <!-- Header - EXACT MATCH from view-incidents.php -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0 fw-bold">
                            <i class="fas fa-calendar-plus me-2"></i>
                            Book Appointment
                        </h2>
                        <p class="text-muted mb-0 mt-1">Schedule your health center visit</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo $_SESSION['success_message']; 
                unset($_SESSION['success_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php 
                echo $_SESSION['error_message']; 
                unset($_SESSION['error_message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Appointment Form -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header">
                <h5>
                    <i class="fas fa-edit me-2"></i>Book New Appointment
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="appointmentForm">
                    <input type="hidden" name="action" value="book_appointment">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Appointment Type *</label>
                            <select name="appointment_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="General Check-up">General Check-up</option>
                                <option value="Vaccination">Vaccination</option>
                                <option value="Prenatal">Prenatal</option>
                                <option value="Dental">Dental</option>
                                <option value="Family Planning">Family Planning</option>
                                <option value="Medical Consultation">Medical Consultation</option>
                                <option value="Laboratory">Laboratory</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Preferred Date *</label>
                            <input type="date" name="appointment_date" class="form-control" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                            <small class="text-muted">Appointments must be booked at least 1 day in advance</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Preferred Time *</label>
                            <select name="appointment_time" class="form-control" required>
                                <option value="">Select Time</option>
                                <option value="08:00:00">8:00 AM</option>
                                <option value="09:00:00">9:00 AM</option>
                                <option value="10:00:00">10:00 AM</option>
                                <option value="11:00:00">11:00 AM</option>
                                <option value="13:00:00">1:00 PM</option>
                                <option value="14:00:00">2:00 PM</option>
                                <option value="15:00:00">3:00 PM</option>
                                <option value="16:00:00">4:00 PM</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Contact Number *</label>
                            <input type="text" name="contact_number" class="form-control" required placeholder="09XX XXX XXXX">
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Purpose/Reason for Visit *</label>
                            <textarea name="purpose" class="form-control" rows="3" required placeholder="Please describe your reason for booking this appointment"></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Symptoms (if any)</label>
                            <textarea name="symptoms" class="form-control" rows="2" placeholder="Describe any symptoms you're experiencing"></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="form-label">Special Instructions</label>
                            <textarea name="special_instructions" class="form-control" rows="2" placeholder="Any special requests or concerns"></textarea>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 mt-3 pt-3" style="border-top: 2px solid #e9ecef;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calendar-check me-2"></i>Book Appointment
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-redo me-2"></i>Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- My Appointments -->
        <div class="card border-0 shadow-sm">
            <div class="card-header">
                <h5>
                    <i class="fas fa-calendar-alt me-2"></i>My Appointments
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
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
                                <?php while ($apt = $appointments->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($apt['appointment_type']); ?></span></td>
                                        <td><?php echo htmlspecialchars(substr($apt['purpose'], 0, 50)) . (strlen($apt['purpose']) > 50 ? '...' : ''); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $apt['status'] === 'Confirmed' ? 'success' : 
                                                    ($apt['status'] === 'Scheduled' ? 'warning' : 
                                                    ($apt['status'] === 'Completed' ? 'info' : 
                                                    ($apt['status'] === 'Cancelled' ? 'secondary' : 'warning'))); 
                                            ?>">
                                                <?php echo $apt['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-icon btn-info" onclick='viewAppointment(<?php echo json_encode($apt); ?>)' title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (in_array($apt['status'], ['Scheduled', 'Confirmed'])): ?>
                                                    <button class="btn-icon btn-danger" 
                                                            onclick="openCancelModal(<?php echo intval($apt['appointment_id']); ?>, <?php echo htmlspecialchars(json_encode($apt), ENT_QUOTES, 'UTF-8'); ?>)" 
                                                            title="Cancel">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <i class="fas fa-calendar"></i>
                                            <p>No appointments yet</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
            <div class="modal-body" id="appointmentDetails">
                <!-- Details will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div id="cancelAppointmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Cancel Appointment</h2>
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
                    <input type="hidden" name="action" value="cancel_appointment">
                    <input type="hidden" name="appointment_id" id="cancelAppointmentId">
                    
                    <div class="form-group">
                        <label class="form-label">Reason for Cancellation (Optional)</label>
                        <textarea name="cancellation_reason" class="form-control" rows="3" placeholder="Please provide a reason for cancelling..."></textarea>
                    </div>
                    
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('cancelAppointmentModal')">
                            <i class="fas fa-arrow-left me-2"></i>Go Back
                        </button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times-circle me-2"></i>Yes, Cancel Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function viewAppointment(appointment) {
        const modal = document.getElementById('viewAppointmentModal');
        const details = document.getElementById('appointmentDetails');
        
        const statusClass = appointment.status === 'Confirmed' ? 'bg-success' : 
                        (appointment.status === 'Scheduled' ? 'bg-warning' : 
                        (appointment.status === 'Completed' ? 'bg-info' : 'bg-secondary'));
        
        details.innerHTML = `
    <div class="appointment-detail-row">
        <label>Appointment Type:</label>
        <span><span class="badge bg-info">${appointment.appointment_type}</span></span>
    </div>
    <div class="appointment-detail-row">
        <label>Date:</label>
        <span>${new Date(appointment.appointment_date + 'T00:00:00').toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
    </div>
    <div class="appointment-detail-row">
        <label>Time:</label>
       <span>${new Date(appointment.appointment_date + 'T00:00:00').toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
    </div>
    <div class="appointment-detail-row">
        <label>Status:</label>
        <span><span class="badge ${statusClass}">${appointment.status}</span></span>
    </div>
    <div class="appointment-detail-row">
        <label>Purpose:</label>
        <span>${appointment.purpose}</span>
    </div>
    ${appointment.symptoms ? `
    <div class="appointment-detail-row">
        <label>Symptoms:</label>
        <span>${appointment.symptoms}</span>
    </div>
    ` : ''}
    <div class="appointment-detail-row">
        <label>Contact Number:</label>
        <span>${appointment.contact_number || 'Not provided'}</span>
    </div>
    ${appointment.special_instructions ? `
    <div class="appointment-detail-row">
        <label>Special Instructions:</label>
        <span>${appointment.special_instructions}</span>
    </div>
    ` : ''}
    ${appointment.notes ? `
    <div class="appointment-detail-row">
        <label>Notes:</label>
        <span>${appointment.notes}</span>
    </div>
    ` : ''}
`;
        
        modal.classList.add('show');
    }

    function openCancelModal(appointmentId, appointment) {
        const modal = document.getElementById('cancelAppointmentModal');
        const cancelInfo = document.getElementById('cancelInfo');
        const appointmentIdInput = document.getElementById('cancelAppointmentId');
        
        appointmentId = parseInt(appointmentId);
        
        if (isNaN(appointmentId) || appointmentId <= 0) {
            alert('Invalid appointment ID');
            return;
        }
        
        appointmentIdInput.value = appointmentId;
        
        cancelInfo.innerHTML = `
            <div class="cancel-info-row">
                <strong>Appointment ID:</strong>
                <span>#${appointmentId}</span>
            </div>
            <div class="cancel-info-row">
                <strong>Type:</strong>
                <span>${appointment.appointment_type}</span>
            </div>
            <div class="cancel-info-row">
                <strong>Date:</strong>
                <span>${new Date(appointment.appointment_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'})}</span>
            </div>
            <div class="cancel-info-row">
                <strong>Time:</strong>
                <span>${new Date('1970-01-01T' + appointment.appointment_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'})}</span>
            </div>
        `;
        
        modal.classList.add('show');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.classList.remove('show');
        }
    }

    document.getElementById('appointmentForm').addEventListener('submit', function(e) {
        const date = new Date(document.querySelector('[name="appointment_date"]').value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (date <= today) {
            e.preventDefault();
            alert('Please select a date at least 1 day from today.');
        }
    });

    document.getElementById('cancelForm').addEventListener('submit', function(e) {
        const appointmentId = document.getElementById('cancelAppointmentId').value;
        
        if (!appointmentId || appointmentId == '' || appointmentId == '0') {
            e.preventDefault();
            alert('Error: Invalid appointment ID. Please try again.');
            return false;
        }
    });

    // Auto-dismiss alerts
    document.addEventListener('DOMContentLoaded', function() {
        var alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });
    </script>

    <?php include '../../includes/footer.php'; ?>