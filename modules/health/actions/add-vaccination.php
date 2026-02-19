// ========================================
// FILE: modules/health/actions/add-vaccination.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_id = (int)$_POST['resident_id'];
    $vaccine_name = trim($_POST['vaccine_name']);
    $vaccine_type = trim($_POST['vaccine_type']);
    $vaccination_date = $_POST['vaccination_date'];
    $dose_number = (int)$_POST['dose_number'];
    $total_doses = (int)$_POST['total_doses'];
    $next_dose_date = !empty($_POST['next_dose_date']) ? $_POST['next_dose_date'] : null;
    $vaccine_brand = trim($_POST['vaccine_brand']);
    $batch_number = trim($_POST['batch_number']);
    $administered_by = trim($_POST['administered_by']);
    $vaccination_site = trim($_POST['vaccination_site']);
    $side_effects = trim($_POST['side_effects']);
    $remarks = trim($_POST['remarks']);
    $created_by = getCurrentUserId();
    
    $stmt = $conn->prepare("INSERT INTO tbl_vaccination_records 
        (resident_id, vaccine_name, vaccine_type, vaccination_date, dose_number, total_doses, 
         next_dose_date, vaccine_brand, batch_number, administered_by, vaccination_site, 
         side_effects, remarks, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("isssiisssssssi", 
        $resident_id, $vaccine_name, $vaccine_type, $vaccination_date, $dose_number, $total_doses,
        $next_dose_date, $vaccine_brand, $batch_number, $administered_by, $vaccination_site,
        $side_effects, $remarks, $created_by
    );
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Vaccination record added successfully!";
    } else {
        $_SESSION['error_message'] = "Error adding vaccination record.";
    }
    $stmt->close();
}

header("Location: ../vaccinations.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/update-health-record.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resident_id = (int)$_POST['resident_id'];
    $blood_type = trim($_POST['blood_type']);
    $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
    $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
    $allergies = trim($_POST['allergies']);
    $medical_conditions = trim($_POST['medical_conditions']);
    $current_medications = trim($_POST['current_medications']);
    $emergency_contact_name = trim($_POST['emergency_contact_name']);
    $emergency_contact_number = trim($_POST['emergency_contact_number']);
    $philhealth_number = trim($_POST['philhealth_number']);
    $pwd_id = trim($_POST['pwd_id']);
    $senior_citizen_id = trim($_POST['senior_citizen_id']);
    
    // Check if record exists
    $check = $conn->prepare("SELECT record_id FROM tbl_health_records WHERE resident_id = ?");
    $check->bind_param("i", $resident_id);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    
    if ($exists) {
        // Update existing record
        $stmt = $conn->prepare("UPDATE tbl_health_records SET 
            blood_type = ?, height = ?, weight = ?, allergies = ?, medical_conditions = ?,
            current_medications = ?, emergency_contact_name = ?, emergency_contact_number = ?,
            philhealth_number = ?, pwd_id = ?, senior_citizen_id = ?
            WHERE resident_id = ?");
        $stmt->bind_param("sddsssssssssi", 
            $blood_type, $height, $weight, $allergies, $medical_conditions, $current_medications,
            $emergency_contact_name, $emergency_contact_number, $philhealth_number,
            $pwd_id, $senior_citizen_id, $resident_id
        );
    } else {
        // Insert new record
        $stmt = $conn->prepare("INSERT INTO tbl_health_records 
            (resident_id, blood_type, height, weight, allergies, medical_conditions, current_medications,
             emergency_contact_name, emergency_contact_number, philhealth_number, pwd_id, senior_citizen_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isddssssssss",
            $resident_id, $blood_type, $height, $weight, $allergies, $medical_conditions, $current_medications,
            $emergency_contact_name, $emergency_contact_number, $philhealth_number, $pwd_id, $senior_citizen_id
        );
    }
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Health record updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating health record.";
    }
    $stmt->close();
}

header("Location: ../records.php");
exit;
?>

// ========================================
// FILE: modules/health/actions/get-health-record.php
// ========================================
<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();

$resident_id = (int)$_GET['resident_id'];

// Get resident info
$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get health record
$stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$health = $stmt->get_result()->fetch_assoc();
$stmt->close();

$age = $resident['date_of_birth'] ? floor((time() - strtotime($resident['date_of_birth'])) / 31556926) : 'N/A';
?>

<form action="actions/update-health-record.php" method="POST">
    <input type="hidden" name="resident_id" value="<?php echo $resident_id; ?>">
    
    <div class="patient-header">
        <h3><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></h3>
        <p><?php echo $age; ?> years old • <?php echo $resident['gender']; ?></p>
    </div>
    
    <div class="form-grid">
        <div class="form-group">
            <label>Blood Type</label>
            <select name="blood_type" class="form-control">
                <option value="">Select</option>
                <?php foreach(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                    <option value="<?php echo $bt; ?>" <?php echo ($health['blood_type'] ?? '') === $bt ? 'selected' : ''; ?>><?php echo $bt; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>Height (cm)</label>
            <input type="number" name="height" class="form-control" step="0.01" value="<?php echo $health['height'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>Weight (kg)</label>
            <input type="number" name="weight" class="form-control" step="0.01" value="<?php echo $health['weight'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>PhilHealth Number</label>
            <input type="text" name="philhealth_number" class="form-control" value="<?php echo $health['philhealth_number'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>PWD ID</label>
            <input type="text" name="pwd_id" class="form-control" value="<?php echo $health['pwd_id'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>Senior Citizen ID</label>
            <input type="text" name="senior_citizen_id" class="form-control" value="<?php echo $health['senior_citizen_id'] ?? ''; ?>">
        </div>
        
        <div class="form-group full-width">
            <label>Allergies</label>
            <textarea name="allergies" class="form-control" rows="2"><?php echo $health['allergies'] ?? ''; ?></textarea>
        </div>
        
        <div class="form-group full-width">
            <label>Medical Conditions</label>
            <textarea name="medical_conditions" class="form-control" rows="2"><?php echo $health['medical_conditions'] ?? ''; ?></textarea>
        </div>
        
        <div class="form-group full-width">
            <label>Current Medications</label>
            <textarea name="current_medications" class="form-control" rows="2"><?php echo $health['current_medications'] ?? ''; ?></textarea>
        </div>
        
        <div class="form-group">
            <label>Emergency Contact Name</label>
            <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo $health['emergency_contact_name'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label>Emergency Contact Number</label>
            <input type="text" name="emergency_contact_number" class="form-control" value="<?php echo $health['emergency_contact_number'] ?? ''; ?>">
        </div>
    </div>
    
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('healthRecordModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Health Record
        </button>
    </div>
</form>

// ========================================
// FILE: modules/health/disease-surveillance.php
// ========================================
<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Disease Surveillance';

// Get filter parameters
$disease_filter = isset($_GET['disease']) ? $_GET['disease'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Build query
$where_clauses = ["1=1"];
$params = [];
$types = "";

if ($disease_filter) {
    $where_clauses[] = "ds.disease_name = ?";
    $params[] = $disease_filter;
    $types .= "s";
}

if ($status_filter) {
    $where_clauses[] = "ds.outcome = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($date_from) {
    $where_clauses[] = "ds.onset_date >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if ($date_to) {
    $where_clauses[] = "ds.onset_date <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Get cases
$sql = "SELECT 
            ds.*,
            r.first_name,
            r.last_name,
            r.date_of_birth,
            r.contact_number,
            r.address,
            reporter.username as reporter_name
        FROM tbl_disease_surveillance ds
        LEFT JOIN tbl_residents r ON ds.resident_id = r.resident_id
        LEFT JOIN tbl_users reporter ON ds.reported_by = reporter.user_id
        WHERE $where_sql
        ORDER BY ds.onset_date DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$cases = $stmt->get_result();
$stmt->close();

// Get statistics
$stats = [];
$stats['total_cases'] = $conn->query("SELECT COUNT(*) as count FROM tbl_disease_surveillance WHERE onset_date >= '$date_from' AND onset_date <= '$date_to'")->fetch_assoc()['count'];
$stats['active_cases'] = $conn->query("SELECT COUNT(*) as count FROM tbl_disease_surveillance WHERE outcome IN ('Recovering', 'Unknown') OR outcome IS NULL")->fetch_assoc()['count'];
$stats['recovered'] = $conn->query("SELECT COUNT(*) as count FROM tbl_disease_surveillance WHERE outcome = 'Recovered'")->fetch_assoc()['count'];
$stats['deaths'] = $conn->query("SELECT COUNT(*) as count FROM tbl_disease_surveillance WHERE outcome = 'Died'")->fetch_assoc()['count'];

// Get disease list for filter
$diseases = $conn->query("SELECT DISTINCT disease_name FROM tbl_disease_surveillance ORDER BY disease_name");

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-virus"></i> Disease Surveillance</h1>
        <p class="page-subtitle">Monitor and track disease outbreaks and cases</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('addCaseModal')">
        <i class="fas fa-plus"></i> Report Case
    </button>
</div>

<!-- Statistics -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #4299e1;">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['total_cases']; ?></div>
            <div class="stat-label">Total Cases (Period)</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #ed8936;">
            <i class="fas fa-hospital-user"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['active_cases']; ?></div>
            <div class="stat-label">Active Cases</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #48bb78;">
            <i class="fas fa-heartbeat"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['recovered']; ?></div>
            <div class="stat-label">Recovered</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: #f56565;">
            <i class="fas fa-skull-crossbones"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $stats['deaths']; ?></div>
            <div class="stat-label">Deaths</div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" class="search-form">
        <select name="disease" class="filter-select">
            <option value="">All Diseases</option>
            <?php while ($d = $diseases->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($d['disease_name']); ?>" <?php echo $disease_filter === $d['disease_name'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($d['disease_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>
        
        <select name="status" class="filter-select">
            <option value="">All Statuses</option>
            <option value="Recovering" <?php echo $status_filter === 'Recovering' ? 'selected' : ''; ?>>Recovering</option>
            <option value="Recovered" <?php echo $status_filter === 'Recovered' ? 'selected' : ''; ?>>Recovered</option>
            <option value="Died" <?php echo $status_filter === 'Died' ? 'selected' : ''; ?>>Died</option>
        </select>
        
        <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
        <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
        </button>
    </form>
</div>

<!-- Cases Table -->
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Case Date</th>
                <th>Patient</th>
                <th>Disease</th>
                <th>Severity</th>
                <th>Status</th>
                <th>Quarantine</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($cases->num_rows > 0): ?>
                <?php while ($case = $cases->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($case['onset_date'])); ?></td>
                        <td>
                            <?php if ($case['first_name']): ?>
                                <?php echo htmlspecialchars($case['first_name'] . ' ' . $case['last_name']); ?>
                            <?php else: ?>
                                <span class="text-muted">Anonymous</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($case['disease_name']); ?></strong>
                            <?php if ($case['confirmed']): ?>
                                <span class="badge badge-danger">Confirmed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $case['severity'] === 'Critical' ? 'danger' : ($case['severity'] === 'Severe' ? 'warning' : 'info'); ?>">
                                <?php echo $case['severity'] ?: 'Mild'; ?>
                            </span>
                        </td>
                        <td><?php echo $case['outcome'] ?: 'Under Observation'; ?></td>
                        <td><?php echo $case['quarantine_status'] ?: 'Not Required'; ?></td>
                        <td>
                            <button class="btn-icon btn-info" onclick='viewCase(<?php echo json_encode($case); ?>)'>
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center">
                        <div class="no-data">
                            <i class="fas fa-viruses"></i>
                            <p>No disease cases found</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function viewCase(caseData) {
    alert('View case details - to be implemented');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}
</script>

<?php include '../../includes/footer.php'; ?>

// ========================================
// RESIDENT FILES BELOW
// ========================================

// FILE: modules/health/my-health.php
<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();

$page_title = 'My Health Record';
$resident_id = getCurrentResidentId();

// Get health record
$stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$health = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <h1><i class="fas fa-heartbeat"></i> My Health Record</h1>
</div>

<div class="health-record-view">
    <?php if ($health): ?>
        <div class="info-grid">
            <div class="info-card">
                <label>Blood Type</label>
                <strong><?php echo $health['blood_type'] ?: 'Not set'; ?></strong>
            </div>
            <div class="info-card">
                <label>Height</label>
                <strong><?php echo $health['height'] ? $health['height'] . ' cm' : 'Not set'; ?></strong>
            </div>
            <div class="info-card">
                <label>Weight</label>
                <strong><?php echo $health['weight'] ? $health['weight'] . ' kg' : 'Not set'; ?></strong>
            </div>
            <div class="info-card">
                <label>PhilHealth</label>
                <strong><?php echo $health['philhealth_number'] ?: 'Not registered'; ?></strong>
            </div>
            <?php if ($health['allergies']): ?>
                <div class="info-card full-width">
                    <label>Allergies</label>
                    <p><?php echo nl2br(htmlspecialchars($health['allergies'])); ?></p>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-notes-medical"></i>
            <p>No health record found. Please contact barangay health center.</p>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>

// FILE: modules/health/book-appointment.php  
<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();

$page_title = 'Book Appointment';
$resident_id = getCurrentResidentId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_type = trim($_POST['appointment_type']);
    $appointment_date = $_POST['appointment_date'];
    $appointment_time = $_POST['appointment_time'];
    $purpose = trim($_POST['purpose']);
    $symptoms = trim($_POST['symptoms']);
    $user_id = getCurrentUserId();
    
    $stmt = $conn->prepare("INSERT INTO tbl_health_appointments 
        (resident_id, appointment_type, appointment_date, appointment_time, purpose, symptoms, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssi", $resident_id, $appointment_type, $appointment_date, $appointment_time, $purpose, $symptoms, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Appointment booked successfully!";
        header("Location: book-appointment.php");
        exit;
    }
    $stmt->close();
}

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <h1><i class="fas fa-calendar-plus"></i> Book Health Appointment</h1>
</div>

<form method="POST" class="form-container">
    <div class="form-grid">
        <div class="form-group">
            <label>Appointment Type *</label>
            <select name="appointment_type" class="form-control" required>
                <option value="General Checkup">General Checkup</option>
                <option value="Consultation">Consultation</option>
                <option value="Vaccination">Vaccination</option>
                <option value="Follow-up">Follow-up</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Preferred Date *</label>
            <input type="date" name="appointment_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
        </div>
        
        <div class="form-group">
            <label>Preferred Time *</label>
            <input type="time" name="appointment_time" class="form-control" required>
        </div>
        
        <div class="form-group full-width">
            <label>Purpose *</label>
            <textarea name="purpose" class="form-control" rows="3" required></textarea>
        </div>
        
        <div class="form-group full-width">
            <label>Symptoms (if any)</label>
            <textarea name="symptoms" class="form-control" rows="2"></textarea>
        </div>
    </div>
    
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-calendar-check"></i> Book Appointment
    </button>
</form>

<?php include '../../includes/footer.php'; ?>