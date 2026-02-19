<?php
/**
 * Certificate Generator Interface
 * Barangay Management System
 * 
 * Provides interface for generating various print-ready certificates
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_functions.php';

// Require admin or staff access
requireAnyRole(['Admin', 'Super Admin', 'Staff']);

$page_title = "Generate Certificates";

/**
 * Generate certificate using Python script
 */
function generateCertificate($type, $data) {
    // Prepare data
    $json_data = json_encode($data);
    
    // Path to Python script
    $script_path = __DIR__ . '/certificate_generator.py';
    
    // Execute Python script
    $command = "python3 " . escapeshellarg($script_path) . " " . 
               escapeshellarg($type) . " " . 
               escapeshellarg($json_data) . " 2>&1";
    
    $output = shell_exec($command);
    $result = json_decode($output, true);
    
    if ($result === null) {
        return [
            'success' => false,
            'message' => 'Error executing certificate generator: ' . $output
        ];
    }
    
    return $result;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $certificate_type = $_POST['certificate_type'] ?? '';
    
    // Common data for all certificates
    $certificate_data = [
        'name' => sanitizeInput($_POST['resident_name']),
        'age' => sanitizeInput($_POST['age']),
        'civil_status' => sanitizeInput($_POST['civil_status']),
        'address' => sanitizeInput($_POST['address']),
        'purpose' => sanitizeInput($_POST['purpose']),
        'barangay' => BARANGAY_NAME,
        'municipality' => MUNICIPALITY,
        'province' => PROVINCE,
        'issue_date' => date('F d, Y'),
        'control_number' => 'BRGY-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'prepared_by' => sanitizeInput($_POST['prepared_by'] ?? 'Barangay Secretary'),
        'certified_by' => sanitizeInput($_POST['certified_by'] ?? 'PUNONG BARANGAY')
    ];
    
    // Add specific fields based on certificate type
    if ($certificate_type === 'clearance') {
        $certificate_data['or_number'] = 'OR-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $certificate_data['validity'] = 'Valid for six (6) months from date of issue.';
    }
    
    if ($certificate_type === 'residency') {
        $certificate_data['residency_since'] = sanitizeInput($_POST['residency_since'] ?? 'birth');
    }
    
    // Generate certificate
    $result = generateCertificate($certificate_type, $certificate_data);
    
    if ($result['success']) {
        // Log activity
        logActivity($conn, getCurrentUserId(), 
                   "Generated {$certificate_type} certificate for {$certificate_data['name']}",
                   'tbl_certificates', null);
        
        // Record in database
        $sql = "INSERT INTO tbl_certificates (certificate_type, resident_name, purpose, file_path, control_number, issued_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", 
            $certificate_type,
            $certificate_data['name'],
            $certificate_data['purpose'],
            $result['file'],
            $certificate_data['control_number'],
            getCurrentUserId()
        );
        $stmt->execute();
        $stmt->close();
        
        setSuccessMessage('Certificate generated successfully!');
        header('Location: view_certificate.php?file=' . urlencode(basename($result['file'])));
        exit;
    } else {
        setErrorMessage('Error generating certificate: ' . $result['message']);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">
    <?php echo displayMessage(); ?>
    
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-certificate me-2"></i>Generate Certificate</h4>
                </div>
                <div class="card-body">
                    <form method="POST" id="certificateForm">
                        <div class="row">
                            <!-- Certificate Type -->
                            <div class="col-md-6 mb-3">
                                <label for="certificate_type" class="form-label">Certificate Type *</label>
                                <select class="form-select" id="certificate_type" name="certificate_type" required>
                                    <option value="">Select Certificate Type</option>
                                    <option value="residency">Certificate of Residency</option>
                                    <option value="indigency">Certificate of Indigency</option>
                                    <option value="clearance">Barangay Clearance</option>
                                    <option value="good_moral">Certificate of Good Moral Character</option>
                                </select>
                            </div>
                            
                            <!-- Resident Selection -->
                            <div class="col-md-6 mb-3">
                                <label for="resident_id" class="form-label">Select Resident *</label>
                                <select class="form-select" id="resident_id" name="resident_id" required>
                                    <option value="">Select Resident</option>
                                    <?php
                                    $residents = fetchAll($conn, 
                                        "SELECT resident_id, 
                                                CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name) as full_name,
                                                address
                                         FROM tbl_residents 
                                         WHERE status = 'active' 
                                         ORDER BY last_name, first_name"
                                    );
                                    foreach ($residents as $resident) {
                                        echo '<option value="' . $resident['resident_id'] . '" 
                                              data-name="' . htmlspecialchars($resident['full_name']) . '"
                                              data-address="' . htmlspecialchars($resident['address']) . '">' . 
                                              htmlspecialchars($resident['full_name']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            
                            <!-- Hidden fields populated by JavaScript -->
                            <input type="hidden" id="resident_name" name="resident_name">
                            <input type="hidden" id="address" name="address">
                            
                            <!-- Age -->
                            <div class="col-md-4 mb-3">
                                <label for="age" class="form-label">Age *</label>
                                <input type="text" class="form-control" id="age" name="age" placeholder="e.g., 25 years old" required>
                            </div>
                            
                            <!-- Civil Status -->
                            <div class="col-md-4 mb-3">
                                <label for="civil_status" class="form-label">Civil Status *</label>
                                <select class="form-select" id="civil_status" name="civil_status" required>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                            
                            <!-- Residency Since (for Residency Certificate) -->
                            <div class="col-md-4 mb-3" id="residency_since_group" style="display: none;">
                                <label for="residency_since" class="form-label">Resident Since</label>
                                <input type="text" class="form-control" id="residency_since" name="residency_since" placeholder="e.g., birth or 2010">
                            </div>
                            
                            <!-- Purpose -->
                            <div class="col-md-12 mb-3">
                                <label for="purpose" class="form-label">Purpose *</label>
                                <textarea class="form-control" id="purpose" name="purpose" rows="2" required placeholder="e.g., Employment purposes"></textarea>
                            </div>
                            
                            <!-- Prepared By -->
                            <div class="col-md-6 mb-3">
                                <label for="prepared_by" class="form-label">Prepared By</label>
                                <input type="text" class="form-control" id="prepared_by" name="prepared_by" value="Barangay Secretary">
                            </div>
                            
                            <!-- Certified By -->
                            <div class="col-md-6 mb-3">
                                <label for="certified_by" class="form-label">Certified By</label>
                                <input type="text" class="form-control" id="certified_by" name="certified_by" value="PUNONG BARANGAY">
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-file-pdf me-2"></i>Generate Certificate
                            </button>
                            <a href="certificate_list.php" class="btn btn-secondary">
                                <i class="fas fa-list me-2"></i>View All Certificates
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Certificate Preview Card -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Certificate Information</h5>
                </div>
                <div class="card-body">
                    <div id="certificateInfo">
                        <p class="text-muted">Select a certificate type to see information about it.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const certificateType = document.getElementById('certificate_type');
    const residentSelect = document.getElementById('resident_id');
    const residentNameInput = document.getElementById('resident_name');
    const addressInput = document.getElementById('address');
    const residencySinceGroup = document.getElementById('residency_since_group');
    const certificateInfo = document.getElementById('certificateInfo');
    
    // Certificate descriptions
    const certificateDescriptions = {
        'residency': '<h6>Certificate of Residency</h6><p>Certifies that a person is a bonafide resident of the barangay. Commonly used for employment, scholarship, and other legal purposes.</p>',
        'indigency': '<h6>Certificate of Indigency</h6><p>Certifies that a person belongs to an indigent family. Usually required for financial assistance, medical assistance, or legal aid programs.</p>',
        'clearance': '<h6>Barangay Clearance</h6><p>Certifies that a person has no derogatory or pending case in the barangay and is of good moral character. Required for employment, business permits, and other transactions.</p>',
        'good_moral': '<h6>Certificate of Good Moral Character</h6><p>Certifies that a person is known to be of good moral character with no adverse records. Typically required for employment or school requirements.</p>'
    };
    
    // Update certificate info when type changes
    certificateType.addEventListener('change', function() {
        const selectedType = this.value;
        
        if (selectedType) {
            certificateInfo.innerHTML = certificateDescriptions[selectedType] || '';
            
            // Show/hide residency since field
            if (selectedType === 'residency') {
                residencySinceGroup.style.display = 'block';
            } else {
                residencySinceGroup.style.display = 'none';
            }
        } else {
            certificateInfo.innerHTML = '<p class="text-muted">Select a certificate type to see information about it.</p>';
        }
    });
    
    // Populate hidden fields when resident is selected
    residentSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            residentNameInput.value = selectedOption.getAttribute('data-name');
            addressInput.value = selectedOption.getAttribute('data-address');
        } else {
            residentNameInput.value = '';
            addressInput.value = '';
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>