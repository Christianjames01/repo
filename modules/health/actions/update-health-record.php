<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Update Health Record';

// Get record ID from URL
$record_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$record_id) {
    header('Location: records.php');
    exit();
}

// Get the health record
$stmt = $conn->prepare("
    SELECT hr.*, r.first_name, r.last_name, r.date_of_birth, r.gender 
    FROM tbl_health_records hr
    JOIN tbl_residents r ON hr.resident_id = r.resident_id
    WHERE hr.record_id = ?
");
$stmt->bind_param("i", $record_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: records.php');
    exit();
}

$record = $result->fetch_assoc();
$stmt->close();

// Calculate age
$age = floor((time() - strtotime($record['date_of_birth'])) / 31556926);

include '../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/health-records.css">

<div class="page-header">
    <div>
        <h1><i class="fas fa-edit"></i> Update Health Record</h1>
        <p class="page-subtitle">Edit health record information</p>
    </div>
    <a href="records.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Records
    </a>
</div>

<!-- Patient Info Card -->
<div class="patient-info-card">
    <div class="patient-avatar">
        <i class="fas fa-user"></i>
    </div>
    <div class="patient-details">
        <h2><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></h2>
        <div class="patient-meta">
            <span><i class="fas fa-calendar"></i> <?php echo $age; ?> years old</span>
            <span><i class="fas fa-venus-mars"></i> <?php echo $record['gender']; ?></span>
            <span><i class="fas fa-hashtag"></i> Record ID: <?php echo $record['record_id']; ?></span>
        </div>
    </div>
</div>

<!-- Update Form -->
<div class="form-container">
    <form action="actions/update-health-record.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="record_id" value="<?php echo $record['record_id']; ?>">
        
        <h3 class="form-section-title">
            <i class="fas fa-info-circle"></i> Basic Information
        </h3>
        
        <div class="form-grid">
            <div class="form-group">
                <label>Record Date *</label>
                <input type="date" name="record_date" class="form-control" required 
                    value="<?php echo $record['record_date']; ?>">
            </div>
            
            <div class="form-group">
                <label>Record Type *</label>
                <select name="record_type" class="form-control" required>
                    <option value="">Select Type</option>
                    <option value="Check-up" <?php echo $record['record_type'] === 'Check-up' ? 'selected' : ''; ?>>Check-up</option>
                    <option value="Treatment" <?php echo $record['record_type'] === 'Treatment' ? 'selected' : ''; ?>>Treatment</option>
                    <option value="Emergency" <?php echo $record['record_type'] === 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                    <option value="Follow-up" <?php echo $record['record_type'] === 'Follow-up' ? 'selected' : ''; ?>>Follow-up</option>
                    <option value="Laboratory" <?php echo $record['record_type'] === 'Laboratory' ? 'selected' : ''; ?>>Laboratory</option>
                    <option value="Prenatal" <?php echo $record['record_type'] === 'Prenatal' ? 'selected' : ''; ?>>Prenatal</option>
                    <option value="Immunization" <?php echo $record['record_type'] === 'Immunization' ? 'selected' : ''; ?>>Immunization</option>
                    <option value="Other" <?php echo $record['record_type'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Healthcare Provider *</label>
                <input type="text" name="healthcare_provider" class="form-control" required 
                    value="<?php echo htmlspecialchars($record['healthcare_provider']); ?>" 
                    placeholder="Doctor/Nurse name">
            </div>
            
            <div class="form-group">
                <label>Healthcare Facility</label>
                <input type="text" name="healthcare_facility" class="form-control" 
                    value="<?php echo htmlspecialchars($record['healthcare_facility']); ?>" 
                    placeholder="Hospital/Clinic name">
            </div>
        </div>
        
        <h3 class="form-section-title">
            <i class="fas fa-stethoscope"></i> Medical Details
        </h3>
        
        <div class="form-grid">
            <div class="form-group full-width">
                <label>Chief Complaint *</label>
                <textarea name="chief_complaint" class="form-control" rows="2" required 
                    placeholder="Main reason for visit"><?php echo htmlspecialchars($record['chief_complaint']); ?></textarea>
            </div>
            
            <div class="form-group full-width">
                <label>Diagnosis *</label>
                <textarea name="diagnosis" class="form-control" rows="3" required 
                    placeholder="Medical diagnosis"><?php echo htmlspecialchars($record['diagnosis']); ?></textarea>
            </div>
            
            <div class="form-group full-width">
                <label>Treatment/Prescription</label>
                <textarea name="treatment" class="form-control" rows="3" 
                    placeholder="Treatment plan or prescriptions"><?php echo htmlspecialchars($record['treatment']); ?></textarea>
            </div>
        </div>
        
        <h3 class="form-section-title">
            <i class="fas fa-heartbeat"></i> Vital Signs
        </h3>
        
        <div class="form-grid">
            <div class="form-group">
                <label>Blood Pressure</label>
                <input type="text" name="blood_pressure" class="form-control" 
                    value="<?php echo htmlspecialchars($record['blood_pressure']); ?>" 
                    placeholder="e.g., 120/80">
            </div>
            
            <div class="form-group">
                <label>Heart Rate (bpm)</label>
                <input type="number" name="heart_rate" class="form-control" 
                    value="<?php echo $record['heart_rate']; ?>" 
                    placeholder="e.g., 72">
            </div>
            
            <div class="form-group">
                <label>Temperature (°C)</label>
                <input type="number" step="0.1" name="temperature" class="form-control" 
                    value="<?php echo $record['temperature']; ?>" 
                    placeholder="e.g., 36.5">
            </div>
            
            <div class="form-group">
                <label>Respiratory Rate</label>
                <input type="number" name="respiratory_rate" class="form-control" 
                    value="<?php echo $record['respiratory_rate']; ?>" 
                    placeholder="e.g., 16">
            </div>
            
            <div class="form-group">
                <label>Weight (kg)</label>
                <input type="number" step="0.1" name="weight" class="form-control" 
                    value="<?php echo $record['weight']; ?>" 
                    placeholder="e.g., 65.5">
            </div>
            
            <div class="form-group">
                <label>Height (cm)</label>
                <input type="number" step="0.1" name="height" class="form-control" 
                    value="<?php echo $record['height']; ?>" 
                    placeholder="e.g., 165.0">
            </div>
        </div>
        
        <h3 class="form-section-title">
            <i class="fas fa-notes-medical"></i> Additional Information
        </h3>
        
        <div class="form-grid">
            <div class="form-group full-width">
                <label>Laboratory Results</label>
                <textarea name="laboratory_results" class="form-control" rows="3" 
                    placeholder="Lab test results if any"><?php echo htmlspecialchars($record['laboratory_results']); ?></textarea>
            </div>
            
            <div class="form-group full-width">
                <label>Follow-up Instructions</label>
                <textarea name="follow_up_instructions" class="form-control" rows="2" 
                    placeholder="Instructions for follow-up care"><?php echo htmlspecialchars($record['follow_up_instructions']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label>Follow-up Date</label>
                <input type="date" name="follow_up_date" class="form-control" 
                    value="<?php echo $record['follow_up_date']; ?>">
            </div>
            
            <div class="form-group full-width">
                <label>Remarks/Notes</label>
                <textarea name="remarks" class="form-control" rows="2" 
                    placeholder="Additional notes"><?php echo htmlspecialchars($record['remarks']); ?></textarea>
            </div>
            
            <div class="form-group full-width">
                <label>Attach Document (Optional)</label>
                <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                <small class="text-muted">Current file: 
                    <?php echo $record['document_path'] ? basename($record['document_path']) : 'No file attached'; ?>
                </small>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Record
            </button>
            <a href="records.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                <i class="fas fa-trash"></i> Delete Record
            </button>
        </div>
    </form>
</div>

<!-- Record History -->
<div class="dashboard-section" style="margin-top: 2rem;">
    <div class="section-header">
        <h2><i class="fas fa-history"></i> Update History</h2>
    </div>
    <div class="history-timeline">
        <div class="timeline-item">
            <div class="timeline-marker"></div>
            <div class="timeline-content">
                <div class="timeline-date">
                    <?php echo date('M d, Y h:i A', strtotime($record['created_at'])); ?>
                </div>
                <div class="timeline-text">
                    Record created
                </div>
            </div>
        </div>
        
        <?php if ($record['updated_at']): ?>
        <div class="timeline-item">
            <div class="timeline-marker active"></div>
            <div class="timeline-content">
                <div class="timeline-date">
                    <?php echo date('M d, Y h:i A', strtotime($record['updated_at'])); ?>
                </div>
                <div class="timeline-text">
                    Record last updated
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.patient-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 2rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.patient-avatar {
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    flex-shrink: 0;
}

.patient-details h2 {
    margin: 0 0 0.5rem 0;
    font-size: 1.75rem;
}

.patient-meta {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    font-size: 0.95rem;
    opacity: 0.95;
}

.patient-meta span {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section-title {
    margin: 2rem 0 1rem 0;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #e2e8f0;
    color: #2d3748;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section-title:first-of-type {
    margin-top: 0;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 2px solid #e2e8f0;
    flex-wrap: wrap;
}

.history-timeline {
    padding: 1rem 0;
}

.timeline-item {
    display: flex;
    gap: 1rem;
    padding-bottom: 1.5rem;
    position: relative;
}

.timeline-item:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 9px;
    top: 30px;
    bottom: -10px;
    width: 2px;
    background: #e2e8f0;
}

.timeline-marker {
    width: 20px;
    height: 20px;
    background: white;
    border: 3px solid #cbd5e0;
    border-radius: 50%;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.timeline-marker.active {
    border-color: #4299e1;
    background: #4299e1;
}

.timeline-content {
    flex: 1;
}

.timeline-date {
    font-size: 0.875rem;
    color: #718096;
    margin-bottom: 0.25rem;
}

.timeline-text {
    color: #2d3748;
    font-weight: 500;
}
</style>

<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this health record? This action cannot be undone.')) {
        window.location.href = 'actions/delete-health-record.php?id=<?php echo $record_id; ?>';
    }
}

// Auto-calculate BMI if weight and height are provided
document.querySelector('[name="weight"]').addEventListener('input', calculateBMI);
document.querySelector('[name="height"]').addEventListener('input', calculateBMI);

function calculateBMI() {
    const weight = parseFloat(document.querySelector('[name="weight"]').value);
    const height = parseFloat(document.querySelector('[name="height"]').value);
    
    if (weight && height) {
        const heightInMeters = height / 100;
        const bmi = weight / (heightInMeters * heightInMeters);
        console.log('BMI:', bmi.toFixed(1));
    }
}
</script>

<?php include '../../includes/footer.php'; ?>