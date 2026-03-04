<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$resident_id = null;

if ($user_role === 'Resident') {
    $user_id = getCurrentUserId();
    $user_sql = "SELECT resident_id FROM tbl_users WHERE user_id = ?";
    $user_data = fetchOne($conn, $user_sql, [$user_id], 'i');
    $resident_id = $user_data['resident_id'] ?? null;
}

$page_title = 'My Documents';

$student_records_sql = "SELECT * FROM tbl_education_students WHERE resident_id = ? ORDER BY created_at DESC";
$student_records = $resident_id ? fetchAll($conn, $student_records_sql, [$resident_id], 'i') : [];

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $student_id    = (int)$_POST['student_id'];
    $document_type = $_POST['document_type'];
    $upload_dir    = __DIR__ . '/../../uploads/education/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

    if (isset($_FILES['document']) && $_FILES['document']['error'] === 0) {
        $file          = $_FILES['document'];
        $allowed_types = ['application/pdf','image/jpeg','image/png','image/jpg'];
        if (in_array($file['type'], $allowed_types) && $file['size'] <= 5242880) {
            $ext          = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = 'doc_' . $student_id . '_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                $sql    = "INSERT INTO tbl_education_documents (student_id, document_type, file_name, file_path, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, NOW())";
                $params = [$student_id, $document_type, $file['name'], $new_filename, getCurrentUserId()];
                $_SESSION['temp_success'] = executeQuery($conn, $sql, $params, 'isssi')
                    ? 'Document uploaded successfully'
                    : 'Failed to save document record';
            } else { $_SESSION['temp_error'] = 'Failed to upload file'; }
        } else { $_SESSION['temp_error'] = 'Invalid file type or size. Only PDF and images up to 5MB allowed.'; }
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit();
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $document_id = (int)$_POST['document_id'];
    $doc_sql     = "SELECT file_path FROM tbl_education_documents WHERE document_id = ?";
    $doc         = fetchOne($conn, $doc_sql, [$document_id], 'i');
    if ($doc) {
        $fp = __DIR__ . '/../../uploads/education/' . $doc['file_path'];
        if (file_exists($fp)) unlink($fp);
        $delete_sql = "DELETE FROM tbl_education_documents WHERE document_id = ?";
        $_SESSION['temp_success'] = executeQuery($conn, $delete_sql, [$document_id], 'i')
            ? 'Document deleted successfully'
            : 'Failed to delete document';
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit();
}

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #0ea5e9, #0284c7);">
                <i class="fas fa-folder-open" style="font-size:22px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-resident">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">My Documents</h1>
                <p class="db-hero__sub">Upload and manage your educational documents</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="student-portal.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back to Portal
            </a>
        </div>
    </div>
</div>

<?php if ($success_message): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($success_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>


<?php if (empty($student_records)): ?>
<div class="db-panel">
    <div class="db-empty" style="padding:64px 24px;">
        <i class="fas fa-folder-open"></i>
        <p style="font-size:15px;font-weight:700;color:var(--db-text);margin-bottom:4px;">No Student Records Found</p>
        <p>Submit a scholarship application first before uploading documents.</p>
        <a href="apply-scholarship.php" class="db-btn db-btn--primary" style="margin-top:8px;">
            <i class="fas fa-plus"></i> Apply for Scholarship
        </a>
    </div>
</div>

<?php else: ?>

<!-- Tab buttons -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;">
    <?php foreach ($student_records as $idx => $record): ?>
    <button class="db-btn <?php echo $idx === 0 ? 'db-btn--primary' : 'db-btn--ghost'; ?> db-btn--sm db-student-tab"
            data-target="student-panel-<?php echo $record['student_id']; ?>"
            onclick="switchStudentTab(this, 'student-panel-<?php echo $record['student_id']; ?>')">
        <?php echo htmlspecialchars($record['school_name']); ?>
        <span class="db-badge <?php echo $idx === 0 ? 'db-badge--dark' : 'db-badge--muted'; ?>" style="margin-left:4px;">
            <?php echo htmlspecialchars($record['grade_level']); ?>
        </span>
    </button>
    <?php endforeach; ?>
</div>


<?php foreach ($student_records as $idx => $record): ?>
    <?php
    $docs_sql  = "SELECT * FROM tbl_education_documents WHERE student_id = ? ORDER BY uploaded_at DESC";
    $documents = fetchAll($conn, $docs_sql, [$record['student_id']], 'i');
    $sstatus   = $record['scholarship_status'];
    $sbadge    = ['pending'=>'db-badge--warning','active'=>'db-badge--success','rejected'=>'db-badge--danger','expired'=>'db-badge--muted'][$sstatus] ?? 'db-badge--muted';
    $slabel    = ['pending'=>'Pending','active'=>'Active Scholar','rejected'=>'Rejected','expired'=>'Expired'][$sstatus] ?? ucfirst($sstatus);
    ?>
<div id="student-panel-<?php echo $record['student_id']; ?>"
     class="db-student-panel" style="<?php echo $idx > 0 ? 'display:none;' : ''; ?>">

    <!-- Student info strip -->
    <div class="db-panel" style="margin-bottom:0;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;border-bottom:none;">
        <div style="padding:14px 22px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:var(--db-surf2);">
            <div style="display:flex;gap:6px;align-items:center;">
                <span class="db-panel__icon db-panel__icon--blue" style="width:28px;height:28px;font-size:12px;flex-shrink:0;"><i class="fas fa-school"></i></span>
                <strong style="font-size:13px;"><?php echo htmlspecialchars($record['school_name']); ?></strong>
            </div>
            <span class="db-badge db-badge--primary"><?php echo htmlspecialchars($record['grade_level']); ?></span>
            <span class="db-badge <?php echo $sbadge; ?>"><?php echo $slabel; ?></span>
            <button class="db-btn db-btn--primary db-btn--sm" style="margin-left:auto;"
                    onclick="openModal('uploadModal<?php echo $record['student_id']; ?>')">
                <i class="fas fa-cloud-upload-alt"></i> Upload Document
            </button>
        </div>
    </div>

    <!-- Documents grid -->
    <div class="db-panel" style="border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);">
        <?php if (empty($documents)): ?>
        <div class="db-empty">
            <i class="fas fa-file-upload"></i>
            <p>No documents uploaded yet for this student record.</p>
            <button class="db-btn db-btn--primary db-btn--sm"
                    onclick="openModal('uploadModal<?php echo $record['student_id']; ?>')">
                <i class="fas fa-cloud-upload-alt"></i> Upload First Document
            </button>
        </div>
        <?php else: ?>
        <div class="db-table-wrap">
            <table class="db-table">
                <thead>
                    <tr><th>File</th><th>Type</th><th>Uploaded</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $doc):
                    $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                    $is_pdf = $ext === 'pdf';
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="db-panel__icon <?php echo $is_pdf ? 'db-panel__icon--rose' : 'db-panel__icon--indigo'; ?>"
                                  style="width:32px;height:32px;font-size:13px;flex-shrink:0;">
                                <i class="fas <?php echo $is_pdf ? 'fa-file-pdf' : 'fa-file-image'; ?>"></i>
                            </span>
                            <span style="font-size:12.5px;font-weight:600;"><?php echo htmlspecialchars($doc['file_name']); ?></span>
                        </div>
                    </td>
                    <td><span class="db-badge db-badge--primary"><?php echo htmlspecialchars($doc['document_type']); ?></span></td>
                    <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></span></td>
                    <td>
                        <div class="db-btn-group">
                            <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>"
                               target="_blank" class="db-icon-btn db-icon-btn--info" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>"
                               download class="db-icon-btn" title="Download" style="">
                                <i class="fas fa-download"></i>
                            </a>
                            <button onclick="confirmDeleteDoc(<?php echo $doc['document_id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['file_name'])); ?>')"
                                    class="db-icon-btn db-icon-btn--danger" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /student-panel -->


<!-- Upload Modal -->
<div id="uploadModal<?php echo $record['student_id']; ?>" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-cloud-upload-alt"></i> Upload Document</h3>
            <button class="db-modal__close" onclick="closeModal('uploadModal<?php echo $record['student_id']; ?>')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="db-modal__body">
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="student_id" value="<?php echo $record['student_id']; ?>">

            <div class="db-field">
                <label>Document Type <span class="req">*</span></label>
                <select name="document_type" class="db-input" required>
                    <option value="">Select Type</option>
                    <?php foreach (['Report Card','Certificate of Enrollment','Good Moral','Birth Certificate','Barangay Clearance','Income Certificate','ID Picture','Other'] as $dt): ?>
                    <option value="<?php echo $dt; ?>"><?php echo $dt; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="db-field">
                <label>Select File <span class="req">*</span></label>
                <input type="file" name="document" class="db-input" accept=".pdf,.jpg,.jpeg,.png" required>
                <small style="color:var(--db-muted);font-size:11.5px;">Accepted: PDF, JPG, PNG — Max 5MB</small>
            </div>

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" class="db-btn db-btn--primary db-btn--full">
                    <i class="fas fa-upload"></i> Upload
                </button>
                <button type="button" class="db-btn db-btn--ghost"
                        onclick="closeModal('uploadModal<?php echo $record['student_id']; ?>')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php endforeach; ?>


<!-- Delete Confirm Modal -->
<div id="deleteDocModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteDocModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete:</p>
            <div class="db-delete-target" id="deleteDocName"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST" id="deleteDocForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="document_id" id="deleteDocId">
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteDocModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Document Guidelines -->
<div class="db-panel" style="margin-top:18px;">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-info-circle"></i></span>
            <h2>Document Guidelines</h2>
        </div>
    </div>
    <div style="padding:18px 22px;display:grid;grid-template-columns:1fr 1fr;gap:24px;">
        <div>
            <div style="font-size:12px;font-family:'DM Mono',monospace;text-transform:uppercase;letter-spacing:.6px;color:var(--db-muted);margin-bottom:10px;">Required Documents</div>
            <?php foreach (['Latest Report Card or Grades','Certificate of Enrollment','Good Moral Certificate','Birth Certificate (Photocopy)','Barangay Clearance','2x2 ID Picture'] as $item): ?>
            <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--db-border);">
                <i class="fas fa-check-circle" style="color:var(--db-success);font-size:12px;flex-shrink:0;"></i>
                <span style="font-size:12.5px;"><?php echo $item; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div>
            <div style="font-size:12px;font-family:'DM Mono',monospace;text-transform:uppercase;letter-spacing:.6px;color:var(--db-muted);margin-bottom:10px;">Upload Guidelines</div>
            <?php foreach (['File formats: PDF, JPG, or PNG only','Maximum file size: 5MB per document','Ensure documents are clear and readable','Label documents correctly by type','Keep originals for verification'] as $item): ?>
            <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--db-border);">
                <i class="fas fa-dot-circle" style="color:var(--db-sky);font-size:10px;flex-shrink:0;"></i>
                <span style="font-size:12.5px;"><?php echo $item; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php endif; ?>


<script>
function switchStudentTab(btn, targetId) {
    document.querySelectorAll('.db-student-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.db-student-tab').forEach(b => {
        b.classList.remove('db-btn--primary');
        b.classList.add('db-btn--ghost');
        b.querySelector('.db-badge')?.classList.replace('db-badge--dark','db-badge--muted');
    });
    document.getElementById(targetId).style.display = '';
    btn.classList.add('db-btn--primary');
    btn.classList.remove('db-btn--ghost');
    btn.querySelector('.db-badge')?.classList.replace('db-badge--muted','db-badge--dark');
}

function confirmDeleteDoc(id, name) {
    document.getElementById('deleteDocId').value   = id;
    document.getElementById('deleteDocName').textContent = name;
    openModal('deleteDocModal');
}

function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow = ''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.db-modal--open').forEach(m => closeModal(m.id)); });

setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../../includes/footer.php'; ?>