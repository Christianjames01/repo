<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = null;

// Get resident ID
if ($user_role === 'Resident') {
    $user_id = getCurrentUserId();
    $user_sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
    $user_data = fetchOne($conn, $user_sql, [$user_id], 'i');
    $resident_id = $user_data['resident_id'] ?? null;
}

$page_title = 'My Documents';

// Get student records for this resident
$student_records_sql = "SELECT * FROM tbl_education_students WHERE resident_id = ? ORDER BY created_at DESC";
$student_records = $resident_id ? fetchAll($conn, $student_records_sql, [$resident_id], 'i') : [];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $student_id = (int)$_POST['student_id'];
    $document_type = $_POST['document_type'];
    $upload_dir = __DIR__ . '/../../uploads/education/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    if (isset($_FILES['document']) && $_FILES['document']['error'] === 0) {
        $file = $_FILES['document'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        
        if (in_array($file['type'], $allowed_types) && $file['size'] <= 5242880) { // 5MB
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'doc_' . $student_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $target_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $sql = "INSERT INTO tbl_education_documents (student_id, document_type, file_name, file_path, uploaded_by, uploaded_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())";
                $params = [$student_id, $document_type, $file['name'], $new_filename, getCurrentUserId()];
                
                if (executeQuery($conn, $sql, $params, 'isssi')) {
                    setMessage('Document uploaded successfully', 'success');
                } else {
                    setMessage('Failed to save document record', 'error');
                }
            } else {
                setMessage('Failed to upload file', 'error');
            }
        } else {
            setMessage('Invalid file type or size. Only PDF and images up to 5MB allowed.', 'error');
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $document_id = (int)$_POST['document_id'];
    
    // Get document info
    $doc_sql = "SELECT file_path FROM tbl_education_documents WHERE document_id = ?";
    $doc = fetchOne($conn, $doc_sql, [$document_id], 'i');
    
    if ($doc) {
        // Delete file
        $file_path = __DIR__ . '/../../uploads/education/' . $doc['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        
        // Delete record
        $delete_sql = "DELETE FROM tbl_education_documents WHERE document_id = ?";
        if (executeQuery($conn, $delete_sql, [$document_id], 'i')) {
            setMessage('Document deleted successfully', 'success');
        }
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

include '../../../includes/header.php';
?>

<style>
.document-card {
    transition: all 0.3s;
    border-radius: 10px;
    border: 2px solid #e9ecef;
}
.document-card:hover {
    border-color: #007bff;
    box-shadow: 0 4px 12px rgba(0,123,255,0.15);
}
.document-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.8rem;
}
.upload-zone {
    border: 2px dashed #ced4da;
    border-radius: 10px;
    padding: 2rem;
    text-align: center;
    transition: all 0.3s;
    cursor: pointer;
}
.upload-zone:hover {
    border-color: #007bff;
    background: #f8f9fa;
}
.badge-document-type {
    font-size: 0.75rem;
    padding: 0.35rem 0.65rem;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-folder-open me-2 text-primary"></i>My Documents
                    </h2>
                    <p class="text-muted mb-0">Upload and manage your educational documents</p>
                </div>
                <a href="student-portal.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Portal
                </a>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <?php if (empty($student_records)): ?>
        <!-- No Student Records -->
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-folder-open fa-4x text-muted mb-3"></i>
                <h4 class="text-muted">No Student Records Found</h4>
                <p class="text-muted">You need to submit a scholarship application first before uploading documents.</p>
                <a href="apply-scholarship.php" class="btn btn-primary mt-3">
                    <i class="fas fa-plus me-1"></i>Apply for Scholarship
                </a>
            </div>
        </div>
    <?php else: ?>
        
        <!-- Student Records Tabs -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <ul class="nav nav-pills mb-3" id="studentTabs" role="tablist">
                    <?php foreach ($student_records as $index => $record): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo $index === 0 ? 'active' : ''; ?>" 
                                    id="student-<?php echo $record['student_id']; ?>-tab" 
                                    data-bs-toggle="pill" 
                                    data-bs-target="#student-<?php echo $record['student_id']; ?>" 
                                    type="button" 
                                    role="tab">
                                <?php echo htmlspecialchars($record['school_name']); ?> 
                                <small class="text-muted">(<?php echo htmlspecialchars($record['grade_level']); ?>)</small>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <div class="tab-content" id="studentTabsContent">
                    <?php foreach ($student_records as $index => $record): ?>
                        <div class="tab-pane fade <?php echo $index === 0 ? 'show active' : ''; ?>" 
                             id="student-<?php echo $record['student_id']; ?>" 
                             role="tabpanel">
                            
                            <!-- Student Info -->
                            <div class="alert alert-info">
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>School:</strong> <?php echo htmlspecialchars($record['school_name']); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Grade Level:</strong> <?php echo htmlspecialchars($record['grade_level']); ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Status:</strong> 
                                        <?php 
                                        $status = $record['scholarship_status'];
                                        if ($status == 'active') {
                                            echo '<span class="badge bg-success">Active Scholar</span>';
                                        } elseif ($status == 'pending') {
                                            echo '<span class="badge bg-warning">Pending</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Upload Document Button -->
                            <div class="mb-4">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal<?php echo $record['student_id']; ?>">
                                    <i class="fas fa-cloud-upload-alt me-2"></i>Upload New Document
                                </button>
                            </div>

                            <!-- Documents List -->
                            <?php
                            $docs_sql = "SELECT * FROM tbl_education_documents WHERE student_id = ? ORDER BY uploaded_at DESC";
                            $documents = fetchAll($conn, $docs_sql, [$record['student_id']], 'i');
                            ?>

                            <?php if (empty($documents)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-file-upload fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No documents uploaded yet</p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($documents as $doc): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card document-card">
                                                <div class="card-body">
                                                    <div class="d-flex align-items-start mb-3">
                                                        <div class="document-icon me-3">
                                                            <?php
                                                            $extension = pathinfo($doc['file_name'], PATHINFO_EXTENSION);
                                                            if ($extension === 'pdf') {
                                                                echo '<i class="fas fa-file-pdf"></i>';
                                                            } else {
                                                                echo '<i class="fas fa-file-image"></i>';
                                                            }
                                                            ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-1"><?php echo htmlspecialchars($doc['file_name']); ?></h6>
                                                            <span class="badge badge-document-type bg-primary">
                                                                <?php echo htmlspecialchars($doc['document_type']); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="small text-muted mb-3">
                                                        <i class="fas fa-calendar me-1"></i>
                                                        <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?>
                                                    </div>
                                                    <div class="d-flex gap-2">
                                                        <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                           target="_blank" 
                                                           class="btn btn-sm btn-outline-primary flex-fill">
                                                            <i class="fas fa-eye me-1"></i>View
                                                        </a>
                                                        <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>" 
                                                           download 
                                                           class="btn btn-sm btn-outline-success flex-fill">
                                                            <i class="fas fa-download me-1"></i>Download
                                                        </a>
                                                        <button onclick="deleteDocument(<?php echo $doc['document_id']; ?>, '<?php echo htmlspecialchars($doc['file_name']); ?>')" 
                                                                class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Upload Modal -->
                            <div class="modal fade" id="uploadModal<?php echo $record['student_id']; ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST" enctype="multipart/form-data">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Upload Document</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="upload">
                                                <input type="hidden" name="student_id" value="<?php echo $record['student_id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Document Type</label>
                                                    <select name="document_type" class="form-select" required>
                                                        <option value="">Select Type</option>
                                                        <option value="Report Card">Report Card</option>
                                                        <option value="Certificate of Enrollment">Certificate of Enrollment</option>
                                                        <option value="Good Moral">Good Moral Certificate</option>
                                                        <option value="Birth Certificate">Birth Certificate</option>
                                                        <option value="Barangay Clearance">Barangay Clearance</option>
                                                        <option value="Income Certificate">Income Certificate</option>
                                                        <option value="ID Picture">ID Picture</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">Select File</label>
                                                    <input type="file" name="document" class="form-control" 
                                                           accept=".pdf,.jpg,.jpeg,.png" required>
                                                    <small class="text-muted">Accepted: PDF, JPG, PNG (Max 5MB)</small>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fas fa-upload me-1"></i>Upload
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Document Guidelines -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0">
                    <i class="fas fa-info-circle me-2 text-info"></i>Document Guidelines
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary">Required Documents:</h6>
                        <ul class="small">
                            <li>Latest Report Card or Grades</li>
                            <li>Certificate of Enrollment</li>
                            <li>Good Moral Certificate</li>
                            <li>Birth Certificate (Photocopy)</li>
                            <li>Barangay Clearance</li>
                            <li>2x2 ID Picture</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary">Upload Guidelines:</h6>
                        <ul class="small">
                            <li>File formats: PDF, JPG, or PNG only</li>
                            <li>Maximum file size: 5MB per document</li>
                            <li>Ensure documents are clear and readable</li>
                            <li>Label documents correctly by type</li>
                            <li>Keep originals for verification</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="document_id" id="deleteDocumentId">
</form>

<script>
function deleteDocument(id, filename) {
    if (confirm('Are you sure you want to delete "' + filename + '"? This action cannot be undone.')) {
        document.getElementById('deleteDocumentId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php
$conn->close();
include '../../../includes/footer.php';
?>