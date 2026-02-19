<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Resident'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Apply for Business Permit";
$current_user_id = getCurrentUserId();

// Get resident_id and info
$stmt = $conn->prepare("SELECT u.resident_id, r.first_name, r.last_name, r.contact_number, r.email 
                        FROM tbl_users u 
                        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id 
                        WHERE u.user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$resident_data = $stmt->get_result()->fetch_assoc();
$resident_id = $resident_data['resident_id'] ?? null;

if (!$resident_id) {
    $_SESSION['error_message'] = "Resident profile not found";
    redirect('/modules/auth/login.php');
}

// Get business types
$business_types = $conn->query("SELECT * FROM tbl_business_types ORDER BY type_name");

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    
    // Validate required fields
    $required_fields = ['business_name', 'business_type', 'business_address', 'owner_name'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
        }
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Get business type ID from the type name
            $type_stmt = $conn->prepare("SELECT type_id FROM tbl_business_types WHERE type_name = ?");
            $type_stmt->bind_param("s", $_POST['business_type']);
            $type_stmt->execute();
            $type_result = $type_stmt->get_result()->fetch_assoc();
            $business_type_id = $type_result['type_id'] ?? null;
            $type_stmt->close();
            
            if (!$business_type_id) {
                throw new Exception("Invalid business type selected");
            }
            
            // Prepare values - matching exact database column names
            $tin_number = !empty($_POST['tin']) ? $_POST['tin'] : null;
            $dti_registration = !empty($_POST['dti_number']) ? $_POST['dti_number'] : null;
            $business_area_sqm = !empty($_POST['floor_area']) ? (float)$_POST['floor_area'] : null;
            $num_employees = !empty($_POST['employees']) ? (int)$_POST['employees'] : 0;
            $capital_investment = !empty($_POST['capital_investment']) ? (float)$_POST['capital_investment'] : null;
            $owner_contact = $resident_data['contact_number'] ?? '';
            $owner_email = $resident_data['email'] ?? null;
            
            // Insert permit application - using EXACT database column names
            $stmt = $conn->prepare("
                INSERT INTO tbl_business_permits (
                    resident_id, 
                    business_name, 
                    business_type,
                    business_type_id, 
                    business_address, 
                    owner_name,
                    owner_contact,
                    owner_email,
                    tin_number, 
                    dti_registration, 
                    business_area_sqm, 
                    num_employees,
                    capital_investment,
                    permit_type,
                    application_date, 
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'New', NOW(), 'Pending')
            ");
            
            $stmt->bind_param("isisssssssdid",
                $resident_id,
                $_POST['business_name'],
                $_POST['business_type'],
                $business_type_id,
                $_POST['business_address'],
                $_POST['owner_name'],
                $owner_contact,
                $owner_email,
                $tin_number,
                $dti_registration,
                $business_area_sqm,
                $num_employees,
                $capital_investment
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert permit: " . $stmt->error);
            }
            
            $permit_id = $conn->insert_id;
            
            if (!$permit_id || $permit_id == 0) {
                throw new Exception("Failed to generate permit ID. Please contact administrator.");
            }
            
            $stmt->close();
            
            // Generate permit number
            $year = date('Y');
            $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM tbl_business_permits WHERE YEAR(application_date) = ?");
            $count_stmt->bind_param("i", $year);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result()->fetch_assoc();
            $sequence = str_pad($count_result['count'], 5, '0', STR_PAD_LEFT);
            $permit_number = "BP-{$year}-{$sequence}";
            $count_stmt->close();
            
            // Update permit number
            $update_permit = $conn->prepare("UPDATE tbl_business_permits SET permit_number = ? WHERE permit_id = ?");
            $update_permit->bind_param("si", $permit_number, $permit_id);
            $update_permit->execute();
            $update_permit->close();
            
            // Handle file uploads - store as JSON in documents column
            $upload_dir = '../../../uploads/business/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $document_fields = [
                'dti_certificate' => 'DTI Certificate',
                'bir_certificate' => 'BIR Certificate',
                'barangay_clearance' => 'Barangay Clearance',
                'cedula' => 'Cedula'
            ];
            
            $uploaded_documents = [];
            
            foreach ($document_fields as $field => $label) {
                if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                    // Check file size (5MB max)
                    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) {
                        continue; // Skip files over 5MB
                    }
                    
                    $file_ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
                    
                    if (in_array($file_ext, $allowed_ext)) {
                        $new_filename = $permit_id . '_' . $field . '_' . time() . '.' . $file_ext;
                        $target_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES[$field]['tmp_name'], $target_path)) {
                            $uploaded_documents[$field] = [
                                'label' => $label,
                                'filename' => $new_filename,
                                'uploaded_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                }
            }
            
            // Update documents field with JSON if any files were uploaded
            if (!empty($uploaded_documents)) {
                $documents_json = json_encode($uploaded_documents);
                $update_docs = $conn->prepare("UPDATE tbl_business_permits SET documents = ? WHERE permit_id = ?");
                $update_docs->bind_param("si", $documents_json, $permit_id);
                $update_docs->execute();
                $update_docs->close();
            }
            
            $conn->commit();
            
            $_SESSION['success_message'] = "Business permit application submitted successfully! Your application is now pending review.";
            
            // Fixed redirect - use header() with proper path
            header('Location: my-permits.php');
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error submitting application: " . $e->getMessage();
            
            // Log the detailed error for debugging
            error_log("Business Permit Application Error - User ID: $current_user_id, Resident ID: $resident_id, Error: " . $e->getMessage());
        }
    }
}

include_once '../../../includes/header.php';
?>

<div class="container-fluid px-4 py-3">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Header -->
            <div class="mb-4">
                <h1 class="h3">Apply for Business Permit</h1>
                <p class="text-muted">Complete the form below to apply for a new business permit</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <strong>Please correct the following errors:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Application Form -->
            <form method="POST" enctype="multipart/form-data" id="permitForm">
                <!-- Business Information -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-building me-2"></i>Business Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Business Name <span class="text-danger">*</span></label>
                                <input type="text" name="business_name" class="form-control" required
                                       value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Business Type <span class="text-danger">*</span></label>
                                <select name="business_type" class="form-select" required id="businessType">
                                    <option value="">Select Business Type</option>
                                    <?php 
                                    $business_types->data_seek(0);
                                    while ($type = $business_types->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($type['type_name']); ?>" 
                                                data-fee="<?php echo $type['base_fee'] ?? 0; ?>"
                                                <?php echo (isset($_POST['business_type']) && $_POST['business_type'] == $type['type_name']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['type_name']); ?> 
                                            (Base Fee: ₱<?php echo number_format($type['base_fee'] ?? 0, 2); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Business Address <span class="text-danger">*</span></label>
                                <textarea name="business_address" class="form-control" rows="2" required><?php echo htmlspecialchars($_POST['business_address'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Capital Investment</label>
                                <input type="number" name="capital_investment" class="form-control" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($_POST['capital_investment'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Number of Employees</label>
                                <input type="number" name="employees" class="form-control" min="0"
                                       value="<?php echo htmlspecialchars($_POST['employees'] ?? '0'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Floor Area (sq.m)</label>
                                <input type="number" name="floor_area" class="form-control" step="0.01" min="0"
                                       value="<?php echo htmlspecialchars($_POST['floor_area'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Owner Information -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>Owner Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Owner Name <span class="text-danger">*</span></label>
                                <input type="text" name="owner_name" class="form-control" required
                                       value="<?php echo htmlspecialchars($_POST['owner_name'] ?? ($resident_data['first_name'] ?? '') . ' ' . ($resident_data['last_name'] ?? '')); ?>">
                                <small class="text-muted">Contact: <?php echo htmlspecialchars($resident_data['contact_number'] ?? 'Not set'); ?> | Email: <?php echo htmlspecialchars($resident_data['email'] ?? 'Not set'); ?></small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">TIN (Tax Identification Number)</label>
                                <input type="text" name="tin" class="form-control"
                                       value="<?php echo htmlspecialchars($_POST['tin'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">DTI Registration Number</label>
                                <input type="text" name="dti_number" class="form-control"
                                       value="<?php echo htmlspecialchars($_POST['dti_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Required Documents -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="fas fa-file-upload me-2"></i>Required Documents</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Please upload clear copies of the following documents (PDF, JPG, or PNG format):</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">DTI Business Registration</label>
                                <input type="file" name="dti_certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Max size: 5MB</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">BIR Certificate of Registration</label>
                                <input type="file" name="bir_certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Max size: 5MB</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Barangay Clearance</label>
                                <input type="file" name="barangay_clearance" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Max size: 5MB</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Community Tax Certificate (Cedula)</label>
                                <input type="file" name="cedula" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Max size: 5MB</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Terms and Conditions -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                            <label class="form-check-label" for="agreeTerms">
                                I hereby certify that the information provided is true and correct to the best of my knowledge. 
                                I understand that any false information may result in the denial of my application.
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between">
                    <a href="my-permits.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-2"></i>Submit Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Calculate estimated fee based on business type
document.getElementById('businessType').addEventListener('change', function() {
    const baseFee = this.options[this.selectedIndex].getAttribute('data-fee');
    if (baseFee) {
        console.log('Base fee: ₱' + parseFloat(baseFee).toLocaleString());
    }
});

// Form validation
document.getElementById('permitForm').addEventListener('submit', function(e) {
    const agreeTerms = document.getElementById('agreeTerms');
    if (!agreeTerms.checked) {
        e.preventDefault();
        alert('Please agree to the terms and conditions');
        agreeTerms.focus();
    }
});
</script>

<?php include_once '../../../includes/footer.php'; ?>