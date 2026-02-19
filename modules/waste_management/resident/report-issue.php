<?php
require_once('../../../config/config.php');

// Check if user is logged in
requireLogin();

$page_title = "Report Waste Issue";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report') {
    
    // Validate required fields
    $errors = [];
    
    if (empty($_POST['issue_type'])) {
        $errors[] = "Issue type is required";
    }
    if (empty($_POST['description'])) {
        $errors[] = "Description is required";
    }
    if (empty($_POST['location'])) {
        $errors[] = "Location is required";
    }
    if (empty($_POST['urgency'])) {
        $errors[] = "Urgency level is required";
    }
    
    if (!empty($errors)) {
        setMessage(implode(', ', $errors), 'danger');
    } else {
        // Sanitize inputs
        $issue_type = sanitize($_POST['issue_type']);
        $description = sanitize($_POST['description']);
        $location = sanitize($_POST['location']);
        $urgency = sanitize($_POST['urgency']);
        $reporter_id = $_SESSION['user_id'];
        
        // Get reporter name and contact from user/resident data - FIXED: removed r.contact
        $user_data = fetchOne($conn, 
            "SELECT u.username, r.first_name, r.last_name, r.contact_number 
             FROM tbl_users u 
             LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id 
             WHERE u.user_id = ?", 
            [$reporter_id], 'i'
        );
        
        // Build reporter name
        if ($user_data && !empty($user_data['first_name'])) {
            $reporter_name = trim($user_data['first_name'] . ' ' . ($user_data['last_name'] ?? ''));
        } else {
            $reporter_name = $user_data['username'] ?? 'User #' . $reporter_id;
        }
        
        // Get contact number - FIXED: only use contact_number
        $reporter_contact = $user_data['contact_number'] ?? 'N/A';
        
        // Handle file upload if present
        $photo_path = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $upload_result = uploadFile(
                $_FILES['photo'],
                '../../../uploads/waste_issues/',
                $allowed_types,
                5242880 // 5MB
            );
            
            if ($upload_result['success']) {
                $photo_path = 'uploads/waste_issues/' . $upload_result['filename'];
            } else {
                setMessage('Photo upload failed: ' . $upload_result['message'], 'warning');
            }
        }
        
        // Prepare INSERT query matching your exact table structure
        $sql = "INSERT INTO tbl_waste_issues 
                (reporter_id, reporter_name, reporter_contact, issue_type, location, 
                 description, urgency, photo_path, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $params = [
            $reporter_id,
            $reporter_name,
            $reporter_contact,
            $issue_type,
            $location,
            $description,
            $urgency,
            $photo_path
        ];
        
        $types = 'isssssss';
        if ($photo_path === null) {
            // If no photo, adjust the query
            $sql = "INSERT INTO tbl_waste_issues 
                    (reporter_id, reporter_name, reporter_contact, issue_type, location, 
                     description, urgency, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            $params = [
                $reporter_id,
                $reporter_name,
                $reporter_contact,
                $issue_type,
                $location,
                $description,
                $urgency
            ];
            $types = 'issssss';
        }
        
        // Execute INSERT
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            setMessage('Failed to submit report. Database error.', 'danger');
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Prepare failed: " . $conn->error);
            }
        } else {
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $issue_id = $conn->insert_id;
                $stmt->close();
                
                // Log activity with the proper issue_id
                if ($issue_id > 0 && function_exists('logActivity')) {
                    logActivity($conn, $reporter_id, 'Reported waste issue: ' . $issue_type, 'tbl_waste_issues', $issue_id);
                }
                
                setMessage('Waste issue reported successfully! Reference ID: #' . $issue_id, 'success');
                header('Location: my-reports.php');
                exit();
            } else {
                setMessage('Failed to submit report. Please try again.', 'danger');
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log("Execute failed: " . $stmt->error);
                }
                $stmt->close();
            }
        }
    }
}

require_once '../../../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2"><i class="fas fa-exclamation-triangle me-2"></i><?php echo $page_title; ?></h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="my-reports.php" class="btn btn-secondary">
                <i class="fas fa-list me-1"></i>My Reports
            </a>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-file-alt me-2"></i>Report a Waste Issue
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="report-issue.php" enctype="multipart/form-data" id="reportForm">
                        <input type="hidden" name="action" value="report">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="issue_type" class="form-label">Issue Type *</label>
                                <select class="form-select" id="issue_type" name="issue_type" required>
                                    <option value="">Select Issue Type</option>
                                    <option value="Missed Collection">Missed Collection</option>
                                    <option value="Illegal Dumping">Illegal Dumping</option>
                                    <option value="Overflowing Bin">Overflowing Bin</option>
                                    <option value="Littering">Littering</option>
                                    <option value="Hazardous Waste">Hazardous Waste</option>
                                    <option value="Damaged Bin">Damaged Bin</option>
                                    <option value="Blocked Access">Blocked Access</option>
                                    <option value="Broken Collection Equipment">Broken Collection Equipment</option>
                                    <option value="Unscheduled Collection">Unscheduled Collection</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="urgency" class="form-label">Urgency Level *</label>
                                <select class="form-select" id="urgency" name="urgency" required>
                                    <option value="">Select Urgency</option>
                                    <option value="low">Low - Can wait a few days</option>
                                    <option value="medium" selected>Medium - Needs attention soon</option>
                                    <option value="high">High - Urgent attention required</option>
                                    <option value="critical">Critical - Immediate action needed</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="location" class="form-label">Location *</label>
                            <input type="text" class="form-control" id="location" name="location" 
                                   placeholder="Street address, landmark, or specific area" required>
                            <small class="text-muted">Be as specific as possible to help us locate the issue</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="4" 
                                      placeholder="Please describe the waste issue in detail..." required></textarea>
                            <small class="text-muted">Include any relevant details that might help resolve the issue</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="photo" class="form-label">Photo (Optional)</label>
                            <input type="file" class="form-control" id="photo" name="photo" 
                                   accept="image/jpeg,image/png,image/jpg,image/gif">
                            <small class="text-muted">Upload a photo of the issue (Max 5MB, JPG/PNG/GIF)</small>
                            <div id="imagePreview" class="mt-2"></div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>What happens next?</strong>
                            <ul class="mb-0 mt-2">
                                <li>Your report will be reviewed by our waste management team</li>
                                <li>You'll receive updates on the status of your report</li>
                                <li>We aim to respond to all reports within 24-48 hours</li>
                                <li>You can track your report status in "My Reports"</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-redo me-1"></i>Reset
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-1"></i>Submit Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Guidelines Card -->
            <div class="card shadow mt-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-success">
                        <i class="fas fa-lightbulb me-2"></i>Reporting Guidelines
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><i class="fas fa-check-circle text-success me-2"></i>DO:</h6>
                            <ul>
                                <li>Provide specific location details</li>
                                <li>Include photos when possible</li>
                                <li>Describe the issue clearly and concisely</li>
                                <li>Select the appropriate urgency level</li>
                                <li>Report genuine concerns only</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><i class="fas fa-times-circle text-danger me-2"></i>DON'T:</h6>
                            <ul>
                                <li>Submit duplicate reports for the same issue</li>
                                <li>Provide false or misleading information</li>
                                <li>Use offensive or inappropriate language</li>
                                <li>Report non-waste related issues here</li>
                                <li>Mark low-priority issues as critical</li>
                            </ul>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="mt-3">
                        <h6><i class="fas fa-question-circle text-primary me-2"></i>Need Help?</h6>
                        <p class="mb-0">If you're unsure about the urgency level or issue type, contact the barangay office at:</p>
                        <p class="mb-0"><i class="fas fa-phone me-2"></i>(123) 456-7890</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Image preview
document.getElementById('photo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('imagePreview');
    
    if (file) {
        // Check file size (5MB)
        if (file.size > 5242880) {
            alert('File size must be less than 5MB');
            this.value = '';
            preview.innerHTML = '';
            return;
        }
        
        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert('Only JPG, PNG, and GIF images are allowed');
            this.value = '';
            preview.innerHTML = '';
            return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <div class="position-relative d-inline-block">
                    <img src="${e.target.result}" class="img-thumbnail" style="max-width: 300px; max-height: 300px;">
                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-2" 
                            onclick="document.getElementById('photo').value=''; document.getElementById('imagePreview').innerHTML='';">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = '';
    }
});

// Form validation
document.getElementById('reportForm').addEventListener('submit', function(e) {
    const issueType = document.getElementById('issue_type').value;
    const location = document.getElementById('location').value;
    const description = document.getElementById('description').value;
    const urgency = document.getElementById('urgency').value;
    
    if (!issueType || !location || !description || !urgency) {
        e.preventDefault();
        alert('Please fill in all required fields');
        return false;
    }
    
    if (description.length < 10) {
        e.preventDefault();
        alert('Please provide a more detailed description (at least 10 characters)');
        return false;
    }
    
    if (location.length < 5) {
        e.preventDefault();
        alert('Please provide a more specific location (at least 5 characters)');
        return false;
    }
    
    return true;
});

// Auto-save draft (optional enhancement)
const form = document.getElementById('reportForm');
const formFields = ['issue_type', 'location', 'description', 'urgency'];

// Load saved draft
window.addEventListener('load', function() {
    formFields.forEach(field => {
        const saved = localStorage.getItem('waste_issue_' + field);
        if (saved) {
            const element = document.getElementById(field);
            if (element) {
                element.value = saved;
            }
        }
    });
});

// Save draft on change
formFields.forEach(field => {
    const element = document.getElementById(field);
    if (element) {
        element.addEventListener('change', function() {
            localStorage.setItem('waste_issue_' + field, this.value);
        });
    }
});

// Clear draft on successful submit
form.addEventListener('submit', function() {
    formFields.forEach(field => {
        localStorage.removeItem('waste_issue_' + field);
    });
});

// Clear draft on reset
form.addEventListener('reset', function() {
    formFields.forEach(field => {
        localStorage.removeItem('waste_issue_' + field);
    });
    document.getElementById('imagePreview').innerHTML = '';
});
</script>

<style>
.card {
    transition: box-shadow 0.3s ease-in-out;
}

.card:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

#imagePreview img {
    object-fit: cover;
}
</style>

<?php require_once '../../../includes/footer.php'; ?>