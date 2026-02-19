<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$resident_id = isset($_GET['resident_id']) ? (int)$_GET['resident_id'] : 0;

if (!$resident_id) {
    echo '<div class="error">Invalid resident ID</div>';
    exit;
}

// Get resident information
$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resident) {
    echo '<div class="error">Resident not found</div>';
    exit;
}

// Get health record
$stmt = $conn->prepare("SELECT * FROM tbl_health_records WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$health_record = $stmt->get_result()->fetch_assoc();
$stmt->close();

$age = $resident['date_of_birth'] ? floor((time() - strtotime($resident['date_of_birth'])) / 31556926) : 'N/A';
?>

<div class="health-record-form">
    <div class="resident-header">
        <div>
            <h3><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></h3>
            <p class="text-muted">
                <?php echo $age; ?> years old • <?php echo $resident['gender'] ?: 'N/A'; ?> • 
                <?php echo $resident['email'] ?: 'No email'; ?>
            </p>
        </div>
        <?php if ($resident['is_verified']): ?>
            <span class="badge badge-success">Verified</span>
        <?php else: ?>
            <span class="badge badge-secondary">Unverified</span>
        <?php endif; ?>
    </div>

    <form id="healthRecordForm" onsubmit="saveHealthRecord(event, <?php echo $resident_id; ?>)">
        <div class="form-grid">
            <!-- Basic Health Information -->
            <div class="form-section">
                <h4><i class="fas fa-heartbeat"></i> Basic Health Information</h4>
                
                <div class="form-group">
                    <label for="blood_type">Blood Type</label>
                    <select id="blood_type" name="blood_type" class="form-control">
                        <option value="">Select Blood Type</option>
                        <option value="A+" <?php echo ($health_record['blood_type'] ?? '') == 'A+' ? 'selected' : ''; ?>>A+</option>
                        <option value="A-" <?php echo ($health_record['blood_type'] ?? '') == 'A-' ? 'selected' : ''; ?>>A-</option>
                        <option value="B+" <?php echo ($health_record['blood_type'] ?? '') == 'B+' ? 'selected' : ''; ?>>B+</option>
                        <option value="B-" <?php echo ($health_record['blood_type'] ?? '') == 'B-' ? 'selected' : ''; ?>>B-</option>
                        <option value="AB+" <?php echo ($health_record['blood_type'] ?? '') == 'AB+' ? 'selected' : ''; ?>>AB+</option>
                        <option value="AB-" <?php echo ($health_record['blood_type'] ?? '') == 'AB-' ? 'selected' : ''; ?>>AB-</option>
                        <option value="O+" <?php echo ($health_record['blood_type'] ?? '') == 'O+' ? 'selected' : ''; ?>>O+</option>
                        <option value="O-" <?php echo ($health_record['blood_type'] ?? '') == 'O-' ? 'selected' : ''; ?>>O-</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="height">Height (cm)</label>
                        <input type="number" id="height" name="height" class="form-control" 
                               value="<?php echo $health_record['height'] ?? ''; ?>" 
                               step="0.01" min="0" max="300">
                    </div>
                    <div class="form-group">
                        <label for="weight">Weight (kg)</label>
                        <input type="number" id="weight" name="weight" class="form-control" 
                               value="<?php echo $health_record['weight'] ?? ''; ?>" 
                               step="0.01" min="0" max="500">
                    </div>
                </div>

                <div class="form-group">
                    <label for="last_checkup_date">Last Checkup Date</label>
                    <input type="date" id="last_checkup_date" name="last_checkup_date" class="form-control" 
                           value="<?php echo $health_record['last_checkup_date'] ?? ''; ?>">
                </div>
            </div>

            <!-- Government IDs -->
            <div class="form-section">
                <h4><i class="fas fa-id-card"></i> Government IDs</h4>
                
                <div class="form-group">
                    <label for="philhealth_number">PhilHealth Number</label>
                    <input type="text" id="philhealth_number" name="philhealth_number" class="form-control" 
                           value="<?php echo $health_record['philhealth_number'] ?? ''; ?>" 
                           placeholder="0000-0000-0000">
                </div>

                <div class="form-group">
                    <label for="pwd_id">PWD ID Number</label>
                    <input type="text" id="pwd_id" name="pwd_id" class="form-control" 
                           value="<?php echo $health_record['pwd_id'] ?? ''; ?>" 
                           placeholder="PWD ID (if applicable)">
                </div>

                <div class="form-group">
                    <label for="senior_citizen_id">Senior Citizen ID</label>
                    <input type="text" id="senior_citizen_id" name="senior_citizen_id" class="form-control" 
                           value="<?php echo $health_record['senior_citizen_id'] ?? ''; ?>" 
                           placeholder="Senior ID (if applicable)">
                </div>
            </div>

            <!-- Medical History -->
            <div class="form-section full-width">
                <h4><i class="fas fa-file-medical"></i> Medical History</h4>
                
                <div class="form-group">
                    <label for="allergies">Allergies</label>
                    <textarea id="allergies" name="allergies" class="form-control" rows="3" 
                              placeholder="List any known allergies (medications, food, etc.)"><?php echo $health_record['allergies'] ?? ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="medical_conditions">Medical Conditions</label>
                    <textarea id="medical_conditions" name="medical_conditions" class="form-control" rows="3" 
                              placeholder="List any chronic conditions or diseases"><?php echo $health_record['medical_conditions'] ?? ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="current_medications">Current Medications</label>
                    <textarea id="current_medications" name="current_medications" class="form-control" rows="3" 
                              placeholder="List current medications and dosages"><?php echo $health_record['current_medications'] ?? ''; ?></textarea>
                </div>

                <div class="form-group">
                    <label for="notes">Additional Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3" 
                              placeholder="Any additional health information or notes"><?php echo $health_record['notes'] ?? ''; ?></textarea>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('healthRecordModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Health Record
            </button>
        </div>
    </form>
</div>

<script>
function saveHealthRecord(event, residentId) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    formData.append('resident_id', residentId);
    
    const submitBtn = event.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('actions/save-health-record.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Health record saved successfully', 'success');
            setTimeout(() => {
                closeModal('healthRecordModal');
                location.reload();
            }, 1000);
        } else {
            showNotification(data.message || 'Error saving health record', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        showNotification('Error saving health record', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        ${message}
    `;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}
</script>

<style>
.health-record-form {
    padding: 10px;
}

.resident-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e0e0e0;
}

.resident-header h3 {
    margin: 0 0 5px 0;
    color: #2c3e50;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
}

.form-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
}

.form-section.full-width {
    grid-column: 1 / -1;
}

.form-section h4 {
    margin: 0 0 20px 0;
    color: #2c3e50;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section h4 i {
    color: #3498db;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #555;
    font-size: 14px;
}

.form-control {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-control:focus {
    outline: none;
    border-color: #3498db;
}

textarea.form-control {
    resize: vertical;
    font-family: inherit;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}

.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background: white;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    display: flex;
    align-items: center;
    gap: 10px;
    opacity: 0;
    transform: translateX(400px);
    transition: all 0.3s ease;
    z-index: 10000;
}

.notification.show {
    opacity: 1;
    transform: translateX(0);
}

.notification.success {
    border-left: 4px solid #27ae60;
}

.notification.error {
    border-left: 4px solid #e74c3c;
}

.notification i {
    font-size: 20px;
}

.notification.success i {
    color: #27ae60;
}

.notification.error i {
    color: #e74c3c;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style><?php $conn->close(); ?>