<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();

$page_title = 'Health Dashboard';
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

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

// Dashboard statistics based on role
if ($user_role === 'Resident') {
    // Resident's personal health stats
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_health_records WHERE resident_id = ?");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $health_records = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_vaccination_records WHERE resident_id = ?");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $vaccinations = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_appointments WHERE resident_id = ? AND status = 'Pending'");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $pending_appointments = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE resident_id = ? AND status = 'Pending'");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $pending_assistance = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    
    // Recent health records
    $stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id = ? ORDER BY record_date DESC LIMIT 5");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $recent_records = $stmt->get_result();
    $stmt->close();
    
    // Upcoming appointments
    $stmt = $conn->prepare("
        SELECT * FROM tbl_appointments 
        WHERE resident_id = ? AND appointment_date >= CURDATE() AND status != 'Cancelled'
        ORDER BY appointment_date ASC, appointment_time ASC LIMIT 5
    ");
    $stmt->bind_param("i", $resident_id);
    $stmt->execute();
    $upcoming_appointments = $stmt->get_result();
    $stmt->close();
    
} else {
    // Admin/Staff statistics
    $total_residents = $conn->query("SELECT COUNT(*) as total FROM tbl_residents WHERE is_verified = 1")->fetch_assoc()['total'];
    $total_health_records = $conn->query("SELECT COUNT(*) as total FROM tbl_health_records")->fetch_assoc()['total'];
    $total_vaccinations = $conn->query("SELECT COUNT(*) as total FROM tbl_vaccination_records")->fetch_assoc()['total'];
    $pending_appointments = $conn->query("SELECT COUNT(*) as total FROM tbl_appointments WHERE status = 'Pending'")->fetch_assoc()['total'];
    $pending_assistance = $conn->query("SELECT COUNT(*) as total FROM tbl_medical_assistance WHERE status = 'Pending'")->fetch_assoc()['total'];
    
    // Recent appointments
    $recent_appointments = $conn->query("
        SELECT a.*, r.first_name, r.last_name 
        FROM tbl_appointments a
        JOIN tbl_residents r ON a.resident_id = r.resident_id
        ORDER BY a.created_at DESC LIMIT 5
    ");
    
    // Disease surveillance alerts
    $disease_alerts = $conn->query("
        SELECT * FROM tbl_disease_surveillance 
        WHERE status = 'Active' 
        ORDER BY report_date DESC LIMIT 5
    ");
}

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-heartbeat"></i> Health Dashboard</h1>
        <p class="page-subtitle">Overview of health services and records</p>
    </div>
</div>

<?php if ($user_role === 'Resident'): ?>
    <!-- RESIDENT DASHBOARD -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #4299e1;">
                <i class="fas fa-file-medical"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $health_records; ?></div>
                <div class="stat-label">Health Records</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #48bb78;">
                <i class="fas fa-syringe"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $vaccinations; ?></div>
                <div class="stat-label">Vaccinations</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #ed8936;">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $pending_appointments; ?></div>
                <div class="stat-label">Pending Appointments</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #9f7aea;">
                <i class="fas fa-hand-holding-medical"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $pending_assistance; ?></div>
                <div class="stat-label">Assistance Requests</div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="quick-actions">
        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
        <div class="action-grid">
            <a href="book-appointment.php" class="action-card">
                <i class="fas fa-calendar-plus"></i>
                <span>Book Appointment</span>
            </a>
            <a href="my-vaccinations.php" class="action-card">
                <i class="fas fa-syringe"></i>
                <span>My Vaccinations</span>
            </a>
            <a href="my-health.php" class="action-card">
                <i class="fas fa-heartbeat"></i>
                <span>Health Profile</span>
            </a>
            <a href="medical-assistance.php" class="action-card">
                <i class="fas fa-hand-holding-medical"></i>
                <span>Request Assistance</span>
            </a>
        </div>
    </div>
    
    <!-- Recent Health Records -->
    <div class="dashboard-section">
        <div class="section-header">
            <h2><i class="fas fa-file-medical"></i> Recent Health Records</h2>
            <a href="records.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="table-container">
            <table class="data-table">
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
                                <td><?php echo date('M d, Y', strtotime($record['record_date'])); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($record['record_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($record['diagnosis']); ?></td>
                                <td><?php echo htmlspecialchars($record['healthcare_provider']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="text-center">No health records yet</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Upcoming Appointments -->
    <div class="dashboard-section">
        <div class="section-header">
            <h2><i class="fas fa-calendar-alt"></i> Upcoming Appointments</h2>
            <a href="appointments.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="appointments-list">
            <?php if ($upcoming_appointments->num_rows > 0): ?>
                <?php while ($apt = $upcoming_appointments->fetch_assoc()): ?>
                    <div class="appointment-item">
                        <div class="appointment-date">
                            <div class="date-day"><?php echo date('d', strtotime($apt['appointment_date'])); ?></div>
                            <div class="date-month"><?php echo date('M', strtotime($apt['appointment_date'])); ?></div>
                        </div>
                        <div class="appointment-details">
                            <div class="appointment-title"><?php echo htmlspecialchars($apt['appointment_type']); ?></div>
                            <div class="appointment-time">
                                <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($apt['appointment_time'])); ?>
                            </div>
                            <div class="appointment-purpose"><?php echo htmlspecialchars($apt['purpose']); ?></div>
                        </div>
                        <span class="badge badge-warning"><?php echo $apt['status']; ?></span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-calendar"></i>
                    <p>No upcoming appointments</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- ADMIN/STAFF DASHBOARD -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #4299e1;">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($total_residents); ?></div>
                <div class="stat-label">Total Residents</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #48bb78;">
                <i class="fas fa-file-medical"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($total_health_records); ?></div>
                <div class="stat-label">Health Records</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #9f7aea;">
                <i class="fas fa-syringe"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo number_format($total_vaccinations); ?></div>
                <div class="stat-label">Vaccinations</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #ed8936;">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $pending_appointments; ?></div>
                <div class="stat-label">Pending Appointments</div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-icon" style="background: #f56565;">
                <i class="fas fa-hand-holding-medical"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $pending_assistance; ?></div>
                <div class="stat-label">Pending Assistance</div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions for Admin -->
    <div class="quick-actions">
        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
        <div class="action-grid">
            <a href="records.php" class="action-card">
                <i class="fas fa-file-medical"></i>
                <span>Health Records</span>
            </a>
            <a href="vaccinations.php" class="action-card">
                <i class="fas fa-syringe"></i>
                <span>Vaccinations</span>
            </a>
            <a href="appointments.php" class="action-card">
                <i class="fas fa-calendar-check"></i>
                <span>Appointments</span>
            </a>
            <a href="medical-assistance.php" class="action-card">
                <i class="fas fa-hand-holding-medical"></i>
                <span>Medical Assistance</span>
            </a>
            <a href="disease-surveillance.php" class="action-card">
                <i class="fas fa-virus"></i>
                <span>Disease Surveillance</span>
            </a>
        </div>
    </div>
    
    <!-- Recent Appointments -->
    <div class="dashboard-section">
        <div class="section-header">
            <h2><i class="fas fa-calendar-alt"></i> Recent Appointments</h2>
            <a href="appointments.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Resident</th>
                        <th>Type</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recent_appointments->num_rows > 0): ?>
                        <?php while ($apt = $recent_appointments->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($apt['appointment_date'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($apt['appointment_time'])); ?></td>
                                <td><?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></td>
                                <td><span class="badge badge-info"><?php echo htmlspecialchars($apt['appointment_type']); ?></span></td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $apt['status'] === 'Confirmed' ? 'success' : 
                                            ($apt['status'] === 'Pending' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo $apt['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center">No recent appointments</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Disease Surveillance Alerts -->
    <div class="dashboard-section">
        <div class="section-header">
            <h2><i class="fas fa-exclamation-triangle"></i> Disease Surveillance Alerts</h2>
            <a href="disease-surveillance.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="alerts-list">
            <?php if ($disease_alerts->num_rows > 0): ?>
                <?php while ($alert = $disease_alerts->fetch_assoc()): ?>
                    <div class="alert-item <?php echo strtolower($alert['severity']); ?>">
                        <div class="alert-icon">
                            <i class="fas fa-virus"></i>
                        </div>
                        <div class="alert-content">
                            <div class="alert-disease"><?php echo htmlspecialchars($alert['disease_name']); ?></div>
                            <div class="alert-details">
                                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($alert['location']); ?></span>
                                <span><i class="fas fa-users"></i> <?php echo $alert['affected_count']; ?> cases</span>
                            </div>
                            <div class="alert-date"><?php echo date('M d, Y', strtotime($alert['report_date'])); ?></div>
                        </div>
                        <span class="badge badge-danger"><?php echo $alert['severity']; ?></span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-check-circle"></i>
                    <p>No active disease alerts</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<style>
.quick-actions {
    margin: 2rem 0;
}

.quick-actions h2 {
    margin-bottom: 1rem;
    color: #2d3748;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

.action-card {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
    transition: all 0.2s;
    border: 2px solid transparent;
}

.action-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-color: #4299e1;
}

.action-card i {
    font-size: 2rem;
    color: #4299e1;
}

.action-card span {
    color: #2d3748;
    font-weight: 600;
    font-size: 0.95rem;
}

.dashboard-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e2e8f0;
}

.section-header h2 {
    margin: 0;
    color: #2d3748;
    font-size: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.appointments-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.appointment-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: #f7fafc;
    border-radius: 8px;
    align-items: center;
}

.appointment-date {
    background: #4299e1;
    color: white;
    padding: 0.75rem;
    border-radius: 8px;
    text-align: center;
    min-width: 60px;
}

.date-day {
    font-size: 1.5rem;
    font-weight: 700;
}

.date-month {
    font-size: 0.875rem;
    text-transform: uppercase;
}

.appointment-details {
    flex: 1;
}

.appointment-title {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.25rem;
}

.appointment-time {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.appointment-purpose {
    font-size: 0.875rem;
    color: #4a5568;
}

.alerts-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.alert-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    border-radius: 8px;
    align-items: center;
    border-left: 4px solid #f56565;
}

.alert-item.high {
    background: #fff5f5;
}

.alert-item.medium {
    background: #fffaf0;
    border-left-color: #ed8936;
}

.alert-item.low {
    background: #f0fff4;
    border-left-color: #48bb78;
}

.alert-icon {
    width: 50px;
    height: 50px;
    background: #fed7d7;
    color: #c53030;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.alert-content {
    flex: 1;
}

.alert-disease {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.alert-details {
    display: flex;
    gap: 1.5rem;
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.alert-details span {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.alert-date {
    font-size: 0.75rem;
    color: #a0aec0;
}
</style>

<?php include '../../includes/footer.php'; ?>