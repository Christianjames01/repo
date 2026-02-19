<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Senior Citizen Management';
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'register_senior') {
        $resident_id = intval($_POST['resident_id']);
        $pension_type = $_POST['pension_type'];
        $medical_conditions = sanitizeInput($_POST['medical_conditions'] ?? '');
        $emergency_contact = sanitizeInput($_POST['emergency_contact']);
        $emergency_contact_number = sanitizeInput($_POST['emergency_contact_number']);
        $registration_date = $_POST['registration_date'] ?? date('Y-m-d');
        
        // Check if already registered
        $check = fetchOne($conn, "SELECT senior_id FROM tbl_senior_citizens WHERE resident_id = ?", [$resident_id], 'i');
        
        if ($check) {
            $error_message = "This resident is already registered as a senior citizen.";
        } else {
            $sql = "INSERT INTO tbl_senior_citizens (resident_id, pension_type, medical_conditions, emergency_contact, emergency_contact_number, registration_date) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            if (executeQuery($conn, $sql, [$resident_id, $pension_type, $medical_conditions, $emergency_contact, $emergency_contact_number, $registration_date], 'isssss')) {
                $success_message = "Senior citizen registered successfully!";
            } else {
                $error_message = "Failed to register senior citizen.";
            }
        }
    }
    
    elseif ($action === 'manual_add_senior') {
        $first_name = sanitizeInput($_POST['first_name']);
        $middle_name = sanitizeInput($_POST['middle_name'] ?? '');
        $last_name = sanitizeInput($_POST['last_name']);
        $birth_date = $_POST['birth_date'];
        $gender = $_POST['gender'];
        $address = sanitizeInput($_POST['address']);
        $contact_number = sanitizeInput($_POST['contact_number'] ?? '');
        $pension_type = $_POST['pension_type'];
        $medical_conditions = sanitizeInput($_POST['medical_conditions'] ?? '');
        $emergency_contact = sanitizeInput($_POST['emergency_contact']);
        $emergency_contact_number = sanitizeInput($_POST['emergency_contact_number']);
        $registration_date = $_POST['registration_date'] ?? date('Y-m-d');
        
        // Calculate age
        $age = date_diff(date_create($birth_date), date_create('today'))->y;
        
        if ($age < 60) {
            $error_message = "Person must be at least 60 years old to be registered as a senior citizen.";
        } else {
            // First, create a resident record
            $resident_sql = "INSERT INTO tbl_residents (first_name, middle_name, last_name, birth_date, gender, address, contact_number, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            if (executeQuery($conn, $resident_sql, [$first_name, $middle_name, $last_name, $birth_date, $gender, $address, $contact_number], 'sssssss')) {
                $resident_id = $conn->insert_id;
                
                // Then, register as senior citizen
                $senior_sql = "INSERT INTO tbl_senior_citizens (resident_id, pension_type, medical_conditions, emergency_contact, emergency_contact_number, registration_date) 
                              VALUES (?, ?, ?, ?, ?, ?)";
                if (executeQuery($conn, $senior_sql, [$resident_id, $pension_type, $medical_conditions, $emergency_contact, $emergency_contact_number, $registration_date], 'isssss')) {
                    $success_message = "Senior citizen added successfully!";
                } else {
                    $error_message = "Failed to register as senior citizen.";
                }
            } else {
                $error_message = "Failed to create resident record.";
            }
        }
    }
    
    elseif ($action === 'add_benefit') {
        $senior_id = intval($_POST['senior_id']);
        $benefit_type = $_POST['benefit_type'];
        $amount = floatval($_POST['amount']);
        $benefit_date = $_POST['benefit_date'];
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        $user_id = $_SESSION['user_id'];
        
        $sql = "INSERT INTO tbl_senior_benefits (senior_id, benefit_type, amount, benefit_date, released_by, remarks) 
                VALUES (?, ?, ?, ?, ?, ?)";
        if (executeQuery($conn, $sql, [$senior_id, $benefit_type, $amount, $benefit_date, $user_id, $remarks], 'isdsss')) {
            $success_message = "Benefit recorded successfully!";
            
            // Create notification for the senior citizen
            $senior_data = fetchOne($conn, "SELECT resident_id FROM tbl_senior_citizens WHERE senior_id = ?", [$senior_id], 'i');
            if ($senior_data) {
                $resident_data = fetchOne($conn, "SELECT user_id FROM tbl_users WHERE resident_id = ?", [$senior_data['resident_id']], 'i');
                if ($resident_data) {
                    createNotification($conn, $resident_data['user_id'], 'Senior Benefit Released', 
                        "You have received a {$benefit_type} worth ₱" . number_format($amount, 2), 
                        'benefit', $senior_id, 'senior_benefit');
                }
            }
        } else {
            $error_message = "Failed to record benefit.";
        }
    }
    
    // Redirect to clear POST
    if ($success_message || $error_message) {
        $_SESSION['temp_success'] = $success_message;
        $_SESSION['temp_error'] = $error_message;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get messages from session
if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

// Fetch senior citizens with auto-age detection (60+)
$seniors_sql = "SELECT sc.*, r.first_name, r.last_name, r.middle_name, r.birth_date, r.contact_number, r.address,
                TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) as age,
                (SELECT SUM(amount) FROM tbl_senior_benefits WHERE senior_id = sc.senior_id) as total_benefits
                FROM tbl_senior_citizens sc
                JOIN tbl_residents r ON sc.resident_id = r.resident_id
                WHERE sc.is_active = 1
                ORDER BY r.last_name, r.first_name";
$seniors = fetchAll($conn, $seniors_sql);

// Fetch eligible residents (60+ years old but not yet registered)
$eligible_sql = "SELECT r.resident_id, r.first_name, r.last_name, r.middle_name, r.birth_date,
                 TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) as age
                 FROM tbl_residents r
                 LEFT JOIN tbl_senior_citizens sc ON r.resident_id = sc.resident_id
                 WHERE TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE()) >= 60
                 AND sc.senior_id IS NULL
                 ORDER BY r.last_name, r.first_name";
$eligible_residents = fetchAll($conn, $eligible_sql);

// Calculate statistics
$stats = [
    'total_seniors' => count($seniors),
    'eligible_not_registered' => count($eligible_residents),
    'total_benefits_this_month' => 0,
    'total_amount_this_month' => 0
];

$benefits_sql = "SELECT COUNT(*) as count, SUM(amount) as total 
                 FROM tbl_senior_benefits 
                 WHERE MONTH(benefit_date) = MONTH(CURRENT_DATE()) 
                 AND YEAR(benefit_date) = YEAR(CURRENT_DATE())";
$benefits_result = fetchOne($conn, $benefits_sql);
$stats['total_benefits_this_month'] = $benefits_result['count'] ?? 0;
$stats['total_amount_this_month'] = $benefits_result['total'] ?? 0;

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements */
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

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md) !important;
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
    transform: scale(1.01);
}

.table tbody td {
    padding: 1rem;
    vertical-align: middle;
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

.bg-warning { background: #ffc107; color: #000; }
.bg-info { background: #0dcaf0; color: #000; }
.bg-success { background: #198754; color: #fff; }

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

.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

.btn-primary {
    background: #0d6efd;
    color: white;
}

.btn-primary:hover {
    background: #0b5ed7;
}

.btn-success {
    background: #198754;
    color: white;
}

.btn-success:hover {
    background: #157347;
}

/* Alert Enhancements */
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
}

.alert-danger {
    background: linear-gradient(135deg, #ffd6d6 0%, #ffe5e5 100%);
    border-left-color: #dc3545;
}

.alert-info {
    background: linear-gradient(135deg, #cfe2ff 0%, #e7f1ff 100%);
    border-left-color: #0d6efd;
    color: #084298;
}

.alert i {
    font-size: 1.1rem;
}

/* Modal Enhancements */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: var(--border-radius-lg);
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 2px solid #e9ecef;
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
}

.modal-header h2 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.modal-body {
    padding: 2rem;
}

.modal-footer {
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
    padding: 1.25rem 2rem;
    border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
}

/* Form Enhancements */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-weight: 700;
    font-size: 0.9rem;
    color: #1a1a1a;
    margin-bottom: 0.75rem;
}

.form-control {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus {
    outline: none;
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
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

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stat-card {
        margin-bottom: 1rem;
    }
    
    .table thead th {
        font-size: 0.8rem;
        padding: 0.75rem;
    }
    
    .table tbody td {
        font-size: 0.875rem;
        padding: 0.75rem;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #64748b;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all var(--transition-speed) ease;
}

.close-btn:hover {
    background: #f1f5f9;
    color: #1e293b;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1 fw-bold">
                        <i class="fas fa-user-friends me-2 text-primary"></i>
                        Senior Citizen Management
                    </h2>
                    <p class="text-muted mb-0">Auto-detection for residents 60 years and older</p>
                </div>
                <button class="btn btn-primary" onclick="openModal('manualAddModal')">
                    <i class="fas fa-user-plus me-2"></i>Manual Add Senior
                </button>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Registered Seniors</p>
                            <h3 class="mb-0"><?php echo $stats['total_seniors']; ?></h3>
                        </div>
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3">
                            <i class="fas fa-users fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Eligible (Not Registered)</p>
                            <h3 class="mb-0"><?php echo $stats['eligible_not_registered']; ?></h3>
                        </div>
                        <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                            <i class="fas fa-user-plus fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Benefits This Month</p>
                            <h3 class="mb-0"><?php echo $stats['total_benefits_this_month']; ?></h3>
                        </div>
                        <div class="bg-info bg-opacity-10 text-info rounded-circle p-3">
                            <i class="fas fa-gift fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Total Amount Released</p>
                            <h3 class="mb-0">₱<?php echo number_format($stats['total_amount_this_month'], 2); ?></h3>
                        </div>
                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                            <i class="fas fa-peso-sign fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Eligible Residents -->
    <?php if (!empty($eligible_residents)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header">
            <h5>
                <i class="fas fa-exclamation-circle me-2 text-warning"></i>
                Eligible Residents (Auto-Detected - 60+ Years Old)
                <span class="badge bg-warning ms-2"><?php echo count($eligible_residents); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                <strong><?php echo count($eligible_residents); ?> resident(s)</strong> are eligible for senior citizen registration based on age (60+).
                Click "Register" to add them to the senior citizen program.
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Age</th>
                            <th>Birth Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eligible_residents as $resident): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['middle_name'] . ' ' . $resident['last_name']); ?></strong>
                            </td>
                            <td><span class="badge bg-warning"><?php echo $resident['age']; ?> years old</span></td>
                            <td>
                                <i class="fas fa-calendar-alt text-muted me-1"></i>
                                <?php echo date('F d, Y', strtotime($resident['birth_date'])); ?>
                            </td>
                            <td>
                                <button class="btn btn-primary btn-sm" onclick='registerSenior(<?php echo json_encode($resident); ?>)'>
                                    <i class="fas fa-user-plus me-1"></i> Register
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Registered Senior Citizens -->
    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5>
                <i class="fas fa-list me-2"></i>
                Registered Senior Citizens
                <span class="badge bg-primary ms-2"><?php echo count($seniors); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (empty($seniors)): ?>
            <div class="empty-state">
                <i class="fas fa-user-friends"></i>
                <p>No registered senior citizens yet</p>
                <p class="text-muted mt-2">Start by registering eligible residents</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Age</th>
                            <th>Contact</th>
                            <th>Pension Type</th>
                            <th>Total Benefits</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($seniors as $senior): ?>
                        <tr>
                            <td>
                                <i class="fas fa-user text-muted me-1"></i>
                                <strong><?php echo htmlspecialchars($senior['first_name'] . ' ' . $senior['last_name']); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($senior['address']); ?></small>
                            </td>
                            <td><?php echo $senior['age']; ?> years</td>
                            <td>
                                <i class="fas fa-phone text-muted me-1"></i>
                                <?php echo htmlspecialchars($senior['contact_number'] ?? '-'); ?>
                            </td>
                            <td><span class="badge bg-info"><?php echo $senior['pension_type']; ?></span></td>
                            <td><strong class="text-success">₱<?php echo number_format($senior['total_benefits'] ?? 0, 2); ?></strong></td>
                            <td>
                                <button class="btn btn-success btn-sm" onclick='addBenefit(<?php echo json_encode($senior); ?>)'>
                                    <i class="fas fa-gift me-1"></i> Add Benefit
                                </button>
                                <button class="btn btn-primary btn-sm" onclick='viewSenior(<?php echo json_encode($senior); ?>)'>
                                    <i class="fas fa-eye me-1"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Register Senior Modal -->
<div id="registerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus me-2"></i>Register Senior Citizen</h2>
            <button class="close-btn" onclick="closeModal('registerModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="register_senior">
                <input type="hidden" name="resident_id" id="register_resident_id">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="register_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Age</label>
                    <input type="text" id="register_age" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Pension Type *</label>
                    <select name="pension_type" class="form-control" required>
                        <option value="None">None</option>
                        <option value="SSS">SSS</option>
                        <option value="GSIS">GSIS</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Medical Conditions</label>
                    <textarea name="medical_conditions" class="form-control" rows="3" placeholder="List any known medical conditions..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Emergency Contact Name *</label>
                    <input type="text" name="emergency_contact" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Emergency Contact Number *</label>
                    <input type="text" name="emergency_contact" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Registration Date *</label>
                    <input type="date" name="registration_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('registerModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Register Senior</button>
            </div>
        </form>
    </div>
</div>

<!-- Manual Add Senior Modal -->
<div id="manualAddModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-user-plus me-2"></i>Manually Add Senior Citizen</h2>
            <button class="close-btn" onclick="closeModal('manualAddModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <p class="text-muted mb-3">For seniors who don't have an account in the system</p>
                <input type="hidden" name="action" value="manual_add_senior">
                
                <div class="form-group">
                    <label>First Name *</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Birth Date *</label>
                    <input type="date" name="birth_date" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" class="form-control" required>
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Address *</label>
                    <textarea name="address" class="form-control" rows="2" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX">
                </div>
                
                <div class="form-group">
                    <label>Pension Type *</label>
                    <select name="pension_type" class="form-control" required>
                        <option value="None">None</option>
                        <option value="SSS">SSS</option>
                        <option value="GSIS">GSIS</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Medical Conditions</label>
                    <textarea name="medical_conditions" class="form-control" rows="2" placeholder="List any known medical conditions..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Emergency Contact Name *</label>
                    <input type="text" name="emergency_contact" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Emergency Contact Number *</label>
                    <input type="text" name="emergency_contact_number" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Registration Date *</label>
                    <input type="date" name="registration_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('manualAddModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Senior Citizen</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Benefit Modal -->
<div id="benefitModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-gift me-2"></i>Add Benefit / Allowance</h2>
            <button class="close-btn" onclick="closeModal('benefitModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_benefit">
                <input type="hidden" name="senior_id" id="benefit_senior_id">
                
                <div class="form-group">
                    <label>Senior Name</label>
                    <input type="text" id="benefit_name" class="form-control" readonly>
                </div>
                
                <div class="form-group">
                    <label>Benefit Type *</label>
                    <select name="benefit_type" class="form-control" required>
                        <option value="Monthly Allowance">Monthly Allowance</option>
                        <option value="Medical Assistance">Medical Assistance</option>
                        <option value="Food Subsidy">Food Subsidy</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Amount (₱) *</label>
                    <input type="number" step="0.01" name="amount" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Benefit Date *</label>
                    <input type="date" name="benefit_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('benefitModal')">Cancel</button>
                <button type="submit" class="btn btn-success">Release Benefit</button>
            </div>
        </form>
    </div>
</div>

<!-- View Senior Details Modal -->
<div id="viewSeniorModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h2><i class="fas fa-user me-2"></i>Senior Citizen Details</h2>
            <button class="close-btn" onclick="closeModal('viewSeniorModal')">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Personal Information -->
            <div style="margin-bottom: 2rem;">
                <h3 style="font-size: 1.1rem; color: #495057; margin-bottom: 1rem; border-bottom: 2px solid #e0e0e0; padding-bottom: 0.5rem;">
                    <i class="fas fa-user"></i> Personal Information
                </h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Full Name</label>
                        <p id="view_full_name" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Age</label>
                       <p id="view_age" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Birth Date</label>
                        <p id="view_birth_date" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Contact Number</label>
                        <p id="view_contact" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Address</label>
                        <p id="view_address" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                </div>
            </div>

            <!-- Senior Citizen Information -->
            <div style="margin-bottom: 2rem;">
                <h3 style="font-size: 1.1rem; color: #495057; margin-bottom: 1rem; border-bottom: 2px solid #e0e0e0; padding-bottom: 0.5rem;">
                    <i class="fas fa-id-card"></i> Senior Citizen Information
                </h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Pension Type</label>
                        <p id="view_pension" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Registration Date</label>
                        <p id="view_reg_date" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Medical Conditions</label>
                        <p id="view_medical" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                </div>
            </div>

            <!-- Emergency Contact -->
            <div style="margin-bottom: 2rem;">
                <h3 style="font-size: 1.1rem; color: #495057; margin-bottom: 1rem; border-bottom: 2px solid #e0e0e0; padding-bottom: 0.5rem;">
                    <i class="fas fa-phone-alt"></i> Emergency Contact
                </h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Contact Name</label>
                        <p id="view_emergency_name" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                    <div>
                        <label style="font-weight: 600; color: #6c757d; font-size: 0.85rem;">Contact Number</label>
                        <p id="view_emergency_number" style="margin: 0.25rem 0 0 0; font-size: 1rem;">-</p>
                    </div>
                </div>
            </div>

            <!-- Benefits Summary -->
            <div style="margin-bottom: 1.5rem;">
                <h3 style="font-size: 1.1rem; color: #495057; margin-bottom: 1rem; border-bottom: 2px solid #e0e0e0; padding-bottom: 0.5rem;">
                    <i class="fas fa-gift"></i> Benefits Summary
                </h3>
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 600; color: #6c757d;">Total Benefits Received:</span>
                        <span id="view_total_benefits" style="font-size: 1.5rem; font-weight: 600; color: #495057;">₱0.00</span>
                    </div>
                </div>
            </div>

            <!-- Recent Benefits -->
            <div>
                <h3 style="font-size: 1.1rem; color: #495057; margin-bottom: 1rem; border-bottom: 2px solid #e0e0e0; padding-bottom: 0.5rem;">
                    <i class="fas fa-history"></i> Recent Benefits
                </h3>
                <div id="view_benefits_list" style="max-height: 200px; overflow-y: auto;">
                    <!-- Benefits will be loaded here -->
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-success" onclick="openAddBenefitFromView()">
                <i class="fas fa-gift me-2"></i>Add Benefit
            </button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('viewSeniorModal')">Close</button>
        </div>
    </div>
</div>

<script>
let currentViewSenior = null;

function viewSenior(senior) {
    currentViewSenior = senior;
    
    // Populate personal information
    document.getElementById('view_full_name').textContent = senior.first_name + ' ' + (senior.middle_name || '') + ' ' + senior.last_name;
    document.getElementById('view_age').textContent = senior.age + ' years old';
    document.getElementById('view_birth_date').textContent = formatDate(senior.birth_date);
    document.getElementById('view_contact').textContent = senior.contact_number || 'N/A';
    document.getElementById('view_address').textContent = senior.address || 'N/A';
    
    // Populate senior citizen information
    document.getElementById('view_pension').textContent = senior.pension_type || 'None';
    document.getElementById('view_reg_date').textContent = formatDate(senior.registration_date);
    document.getElementById('view_medical').textContent = senior.medical_conditions || 'None reported';
    
    // Populate emergency contact
    document.getElementById('view_emergency_name').textContent = senior.emergency_contact || 'N/A';
    document.getElementById('view_emergency_number').textContent = senior.emergency_contact_number || 'N/A';
    
    // Populate benefits summary
    const totalBenefits = senior.total_benefits || 0;
    document.getElementById('view_total_benefits').textContent = '₱' + parseFloat(totalBenefits).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Load recent benefits
    loadRecentBenefits(senior.senior_id);
    
    openModal('viewSeniorModal');
}

function loadRecentBenefits(seniorId) {
    // Fetch recent benefits via AJAX
    fetch('get_senior_benefits.php?senior_id=' + seniorId)
        .then(response => response.json())
        .then(data => {
            const benefitsList = document.getElementById('view_benefits_list');
            
            if (data.benefits && data.benefits.length > 0) {
                let html = '<table class="table" style="margin: 0;">';
                html += '<thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Remarks</th></tr></thead>';
                html += '<tbody>';
                
                data.benefits.forEach(benefit => {
                    html += '<tr>';
                    html += '<td>' + formatDate(benefit.benefit_date) + '</td>';
                    html += '<td>' + benefit.benefit_type + '</td>';
                    html += '<td>₱' + parseFloat(benefit.amount).toLocaleString('en-US', {minimumFractionDigits: 2}) + '</td>';
                    html += '<td>' + (benefit.remarks || '-') + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                benefitsList.innerHTML = html;
            } else {
                benefitsList.innerHTML = '<p style="text-align: center; color: #6c757d; padding: 1rem;">No benefits recorded yet.</p>';
            }
        })
        .catch(error => {
            console.error('Error loading benefits:', error);
            document.getElementById('view_benefits_list').innerHTML = '<p style="text-align: center; color: #dc3545; padding: 1rem;">Error loading benefits.</p>';
        });
}

function openAddBenefitFromView() {
    if (currentViewSenior) {
        closeModal('viewSeniorModal');
        addBenefit(currentViewSenior);
    }
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return date.toLocaleDateString('en-US', options);
}

function registerSenior(resident) {
    document.getElementById('register_resident_id').value = resident.resident_id;
    document.getElementById('register_name').value = resident.first_name + ' ' + resident.middle_name + ' ' + resident.last_name;
    document.getElementById('register_age').value = resident.age + ' years old';
    openModal('registerModal');
}

function addBenefit(senior) {
    document.getElementById('benefit_senior_id').value = senior.senior_id;
    document.getElementById('benefit_name').value = senior.first_name + ' ' + senior.last_name;
    openModal('benefitModal');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            if (modal.classList.contains('show')) {
                closeModal(modal.id);
            }
        });
    }
});

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.style.display = 'none';
        }, 300);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>