<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
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
$page_title = 'My Health Profile';
$resident_id = $user_data['resident_id'];

// Get resident info
$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get health profile (from tbl_health_records which acts as health profile)
$stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$result = $stmt->get_result();
$health_profile = $result->num_rows > 0 ? $result->fetch_assoc() : null;
$stmt->close();

// Get vaccination count
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_vaccination_records WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$vaccinations_count = $stmt->get_result()->fetch_assoc()['total'];
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

/* Enhanced Badges - EXACT match */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Enhanced Buttons - EXACT match */
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

/* Statistics Cards - Matching view-incidents style */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
    background: white;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
    line-height: 1.2;
}

.stat-label {
    font-size: 0.875rem;
    color: #718096;
    font-weight: 500;
    margin-top: 0.25rem;
}

/* Health Profile Container */
.health-profile-container {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.profile-section {
    background: white;
    border-radius: var(--border-radius);
    padding: 1.75rem;
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
}

.profile-section:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.profile-section h2 {
    margin: 0 0 1.25rem 0;
    color: #2d3748;
    font-size: 1.1rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e9ecef;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
}

.info-item {
    display: flex;
    flex-direction: column;
}

.info-item label {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.5rem;
    font-weight: 600;
}

.info-item span {
    font-size: 1rem;
    color: #2d3748;
    font-weight: 500;
}

.blood-type {
    color: #dc3545;
    font-weight: 700;
}

.condition-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.condition-tag {
    background: #fed7d7;
    color: #742a2a;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
}

.allergy-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.allergy-item {
    background: #feebc8;
    color: #7c2d12;
    padding: 0.75rem 1rem;
    border-radius: 8px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.medications-list {
    background: #f7fafc;
    padding: 1rem;
    border-radius: 8px;
    color: #2d3748;
    line-height: 1.6;
}

.text-muted {
    color: #718096;
    font-style: italic;
}

.text-warning {
    color: #ed8936;
}

.text-success {
    color: #48bb78;
}

.text-danger {
    color: #f56565;
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
    margin-bottom: 2rem;
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
    max-width: 800px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-content.large {
    max-width: 900px;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    justify-content: between;
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

.modal-footer {
    padding: 1.25rem 1.5rem;
    border-top: 2px solid #e9ecef;
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    background: #f8f9fa;
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

.form-group label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
}

.form-control {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.625rem 1rem;
    transition: all var(--transition-speed) ease;
    font-size: 0.95rem;
}

.form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
    outline: none;
}

/* Responsive Adjustments - EXACT match */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .card-body {
        padding: 1.25rem;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        flex-direction: column;
        text-align: center;
    }
}

/* Smooth Scrolling - EXACT match */
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
                        <i class="fas fa-user-md me-2"></i>
                        My Health Profile
                    </h2>
                    <p class="text-muted mb-0 mt-1">Manage your personal health information</p>
                </div>
                <?php if ($health_profile): ?>
                    <button class="btn btn-primary" onclick="openModal('editProfileModal')">
                        <i class="fas fa-edit me-2"></i>Update Profile
                    </button>
                <?php else: ?>
                    <button class="btn btn-primary" onclick="openModal('createProfileModal')">
                        <i class="fas fa-plus me-2"></i>Create Profile
                    </button>
                <?php endif; ?>
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

    <!-- Statistics Cards - Matching view-incidents style -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #f56565;">
                    <i class="fas fa-tint"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $health_profile && $health_profile['blood_type'] ? htmlspecialchars($health_profile['blood_type']) : 'Not Set'; ?></div>
                    <div class="stat-label">Blood Type</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #48bb78;">
                    <i class="fas fa-syringe"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $vaccinations_count; ?></div>
                    <div class="stat-label">Vaccinations</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #9f7aea;">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo ($health_profile && $health_profile['last_checkup_date']) ? date('M d, Y', strtotime($health_profile['last_checkup_date'])) : 'N/A'; ?></div>
                    <div class="stat-label">Last Check-up</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($health_profile): ?>
        <!-- Health Profile Information -->
        <div class="health-profile-container">
            <!-- Basic Health Info -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2><i class="fas fa-heartbeat me-2"></i>Basic Health Information</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Blood Type</label>
                            <span class="blood-type"><?php echo htmlspecialchars($health_profile['blood_type'] ?: 'Not Set'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Height</label>
                            <span><?php echo $health_profile['height'] ? $health_profile['height'] . ' cm' : 'Not Set'; ?></span>
                        </div>
                        <div class="info-item">
                            <label>Weight</label>
                            <span><?php echo $health_profile['weight'] ? $health_profile['weight'] . ' kg' : 'Not Set'; ?></span>
                        </div>
                        <div class="info-item">
                            <label>BMI</label>
                            <span>
                                <?php 
                                if ($health_profile['height'] && $health_profile['weight']) {
                                    $bmi = $health_profile['weight'] / (($health_profile['height'] / 100) ** 2);
                                    echo number_format($bmi, 1);
                                    if ($bmi < 18.5) echo ' <span class="text-warning">(Underweight)</span>';
                                    elseif ($bmi < 25) echo ' <span class="text-success">(Normal)</span>';
                                    elseif ($bmi < 30) echo ' <span class="text-warning">(Overweight)</span>';
                                    else echo ' <span class="text-danger">(Obese)</span>';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Last Check-up</label>
                            <span><?php echo $health_profile['last_checkup_date'] ? date('M d, Y', strtotime($health_profile['last_checkup_date'])) : 'Not Set'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Medical Conditions -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2><i class="fas fa-notes-medical me-2"></i>Medical Conditions</h2>
                    <?php if ($health_profile['medical_conditions']): ?>
                        <div class="condition-tags">
                            <?php 
                            $conditions = explode(',', $health_profile['medical_conditions']);
                            foreach ($conditions as $condition): 
                            ?>
                                <span class="condition-tag"><?php echo htmlspecialchars(trim($condition)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No medical conditions recorded</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Allergies -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2><i class="fas fa-exclamation-triangle me-2"></i>Allergies</h2>
                    <?php if ($health_profile['allergies']): ?>
                        <div class="allergy-list">
                            <?php 
                            $allergies = explode(',', $health_profile['allergies']);
                            foreach ($allergies as $allergy): 
                            ?>
                                <div class="allergy-item">
                                    <i class="fas fa-allergies"></i>
                                    <?php echo htmlspecialchars(trim($allergy)); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No known allergies</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Current Medications -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2><i class="fas fa-pills me-2"></i>Current Medications</h2>
                    <?php if ($health_profile['current_medications']): ?>
                        <div class="medications-list">
                            <?php echo nl2br(htmlspecialchars($health_profile['current_medications'])); ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">No current medications</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Emergency Contact -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2><i class="fas fa-phone-alt me-2"></i>Emergency Contact</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Contact Name</label>
                            <span><?php echo htmlspecialchars($health_profile['emergency_contact_name'] ?: 'Not Set'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Contact Number</label>
                            <span><?php echo htmlspecialchars($health_profile['emergency_contact_number'] ?: 'Not Set'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Government IDs -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2><i class="fas fa-id-card me-2"></i>Government IDs</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>PhilHealth Number</label>
                            <span><?php echo htmlspecialchars($health_profile['philhealth_number'] ?: 'Not Set'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>PWD ID</label>
                            <span><?php echo htmlspecialchars($health_profile['pwd_id'] ?: 'Not Set'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Senior Citizen ID</label>
                            <span><?php echo htmlspecialchars($health_profile['senior_citizen_id'] ?: 'Not Set'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- No Profile Yet -->
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-user-md"></i>
                    <h3>No Health Profile Yet</h3>
                    <p>Create your health profile to keep track of your medical information</p>
                    <button class="btn btn-primary btn-lg" onclick="openModal('createProfileModal')">
                        <i class="fas fa-plus me-2"></i>Create Health Profile
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Profile Modal -->
<div id="<?php echo $health_profile ? 'editProfileModal' : 'createProfileModal'; ?>" class="modal">
    <div class="modal-content large">
        <div class="modal-header">
            <h2 class="modal-title"><?php echo $health_profile ? 'Update' : 'Create'; ?> Health Profile</h2>
            <button class="close-btn" onclick="closeModal('<?php echo $health_profile ? 'editProfileModal' : 'createProfileModal'; ?>')">&times;</button>
        </div>
        <form action="actions/<?php echo $health_profile ? 'update' : 'create'; ?>-health-profile.php" method="POST" class="modal-body">
            <div class="form-grid">
                <div class="form-group">
                    <label>Blood Type *</label>
                    <select name="blood_type" class="form-control" required>
                        <option value="">Select Blood Type</option>
                        <option value="A+" <?php echo ($health_profile && $health_profile['blood_type'] === 'A+') ? 'selected' : ''; ?>>A+</option>
                        <option value="A-" <?php echo ($health_profile && $health_profile['blood_type'] === 'A-') ? 'selected' : ''; ?>>A-</option>
                        <option value="B+" <?php echo ($health_profile && $health_profile['blood_type'] === 'B+') ? 'selected' : ''; ?>>B+</option>
                        <option value="B-" <?php echo ($health_profile && $health_profile['blood_type'] === 'B-') ? 'selected' : ''; ?>>B-</option>
                        <option value="AB+" <?php echo ($health_profile && $health_profile['blood_type'] === 'AB+') ? 'selected' : ''; ?>>AB+</option>
                        <option value="AB-" <?php echo ($health_profile && $health_profile['blood_type'] === 'AB-') ? 'selected' : ''; ?>>AB-</option>
                        <option value="O+" <?php echo ($health_profile && $health_profile['blood_type'] === 'O+') ? 'selected' : ''; ?>>O+</option>
                        <option value="O-" <?php echo ($health_profile && $health_profile['blood_type'] === 'O-') ? 'selected' : ''; ?>>O-</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Height (cm)</label>
                    <input type="number" name="height" class="form-control" step="0.1" value="<?php echo $health_profile['height'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Weight (kg)</label>
                    <input type="number" name="weight" class="form-control" step="0.1" value="<?php echo $health_profile['weight'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Last Check-up Date</label>
                    <input type="date" name="last_checkup_date" class="form-control" value="<?php echo $health_profile['last_checkup_date'] ?? ''; ?>">
                </div>
                
                <div class="form-group full-width">
                    <label>Medical Conditions (comma separated)</label>
                    <input type="text" name="medical_conditions" class="form-control" placeholder="e.g., Diabetes, Hypertension" value="<?php echo htmlspecialchars($health_profile['medical_conditions'] ?? ''); ?>">
                </div>
                
                <div class="form-group full-width">
                    <label>Allergies (comma separated)</label>
                    <input type="text" name="allergies" class="form-control" placeholder="e.g., Penicillin, Peanuts" value="<?php echo htmlspecialchars($health_profile['allergies'] ?? ''); ?>">
                </div>
                
                <div class="form-group full-width">
                    <label>Current Medications</label>
                    <textarea name="current_medications" class="form-control" rows="3"><?php echo htmlspecialchars($health_profile['current_medications'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo htmlspecialchars($health_profile['emergency_contact_name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>Emergency Contact Number</label>
                    <input type="text" name="emergency_contact_number" class="form-control" value="<?php echo htmlspecialchars($health_profile['emergency_contact_number'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>PhilHealth Number</label>
                    <input type="text" name="philhealth_number" class="form-control" value="<?php echo htmlspecialchars($health_profile['philhealth_number'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>PWD ID</label>
                    <input type="text" name="pwd_id" class="form-control" value="<?php echo htmlspecialchars($health_profile['pwd_id'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>Senior Citizen ID</label>
                    <input type="text" name="senior_citizen_id" class="form-control" value="<?php echo htmlspecialchars($health_profile['senior_citizen_id'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('<?php echo $health_profile ? 'editProfileModal' : 'createProfileModal'; ?>')">
                    <i class="fas fa-times me-2"></i>Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Save Profile
                </button>
            </div>
        </form>
    </div>
</div>

<script>
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

// Auto-dismiss alerts after 5 seconds
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