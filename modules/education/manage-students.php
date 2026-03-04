<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$page_title = 'Manage Students';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $student_id = (int)$_POST['student_id'];
    switch ($_POST['action']) {
        case 'approve_scholarship':
            $sql = "UPDATE tbl_education_students SET scholarship_status='active', approved_by=?, approval_date=NOW() WHERE student_id=?";
            if (executeQuery($conn, $sql, [getCurrentUserId(), $student_id], 'ii'))
                $_SESSION['temp_success'] = 'Scholarship approved successfully';
            break;
        case 'reject_scholarship':
            $sql = "UPDATE tbl_education_students SET scholarship_status='rejected', remarks=? WHERE student_id=?";
            executeQuery($conn, $sql, [$_POST['remarks'], $student_id], 'si');
            $_SESSION['temp_success'] = 'Scholarship application rejected';
            break;
        case 'delete':
            if (executeQuery($conn, "DELETE FROM tbl_education_students WHERE student_id=?", [$student_id], 'i'))
                $_SESSION['temp_success'] = 'Student record deleted successfully';
            break;
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit();
}

$search            = isset($_GET['search'])      ? trim($_GET['search'])      : '';
$status_filter     = isset($_GET['status'])      ? $_GET['status']            : 'all';
$scholarship_filter= isset($_GET['scholarship']) ? $_GET['scholarship']       : 'all';

$sql    = "SELECT es.*, r.contact_number as resident_contact FROM tbl_education_students es LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id WHERE 1=1";
$params = []; $types = "";

if (!empty($search)) {
    $sql   .= " AND (es.first_name LIKE ? OR es.last_name LIKE ? OR es.school_name LIKE ?)";
    $sp     = "%$search%";
    $params = array_merge($params, [$sp, $sp, $sp]); $types .= "sss";
}
if ($status_filter !== 'all')      { $sql .= " AND es.status = ?";              $params[] = $status_filter;      $types .= "s"; }
if ($scholarship_filter !== 'all') { $sql .= " AND es.scholarship_status = ?";  $params[] = $scholarship_filter; $types .= "s"; }
$sql .= " ORDER BY es.created_at DESC";

$students = !empty($params) ? fetchAll($conn, $sql, $params, $types) : fetchAll($conn, $sql);

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<!-- HERO -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                <i class="fas fa-users-class" style="font-size:20px;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    Education Module
                </div>
                <h1 class="db-hero__title">Student Management</h1>
                <p class="db-hero__sub">View and manage all student records — <?php echo count($students); ?> total</p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;">
            <a href="add-student.php" class="db-btn db-btn--primary db-btn--sm">
                <i class="fas fa-user-plus"></i> Add Student
            </a>
            <a href="export-students.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-file-excel"></i> Export
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


<!-- FILTERS -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-filter"></i></span>
            <h2>Filter Students</h2>
        </div>
    </div>
    <div style="padding:18px 22px;">
        <form method="GET" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;">
            <div class="db-field" style="margin:0;">
                <label>Search</label>
                <input type="text" name="search" class="db-input" placeholder="Name or school…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="db-field" style="margin:0;">
                <label>Status</label>
                <select name="status" class="db-input">
                    <option value="all">All Status</option>
                    <option value="active"    <?php echo $status_filter==='active'    ? 'selected':'' ?>>Active</option>
                    <option value="inactive"  <?php echo $status_filter==='inactive'  ? 'selected':'' ?>>Inactive</option>
                    <option value="graduated" <?php echo $status_filter==='graduated' ? 'selected':'' ?>>Graduated</option>
                </select>
            </div>
            <div class="db-field" style="margin:0;">
                <label>Scholarship</label>
                <select name="scholarship" class="db-input">
                    <option value="all">All Scholarships</option>
                    <option value="active"   <?php echo $scholarship_filter==='active'   ? 'selected':'' ?>>Active Scholars</option>
                    <option value="pending"  <?php echo $scholarship_filter==='pending'  ? 'selected':'' ?>>Pending</option>
                    <option value="rejected" <?php echo $scholarship_filter==='rejected' ? 'selected':'' ?>>Rejected</option>
                </select>
            </div>
            <button type="submit" class="db-btn db-btn--primary" style="flex-shrink:0;">
                <i class="fas fa-search"></i> Filter
            </button>
        </form>
    </div>
</div>


<!-- STUDENT CARDS GRID -->
<?php if (empty($students)): ?>
<div class="db-panel">
    <div class="db-empty">
        <i class="fas fa-user-graduate"></i>
        <p>No students found matching your filters.</p>
        <a href="add-student.php" class="db-btn db-btn--primary db-btn--sm">Add Your First Student</a>
    </div>
</div>

<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px;">
    <?php foreach ($students as $s):
        $ss = $s['scholarship_status'] ?? 'pending';
        $sbadge = ['pending'=>'db-badge--warning','active'=>'db-badge--success','rejected'=>'db-badge--danger','expired'=>'db-badge--muted'][$ss] ?? 'db-badge--muted';
        $slabel = ['pending'=>'Scholarship Pending','active'=>'Active Scholar','rejected'=>'Scholarship Rejected','expired'=>'Scholarship Expired'][$ss] ?? ucfirst($ss);
        $sicon  = ['pending'=>'fa-clock','active'=>'fa-check-circle','rejected'=>'fa-times-circle','expired'=>'fa-hourglass-end'][$ss] ?? 'fa-circle';
        $stbadge= ['active'=>'db-badge--success','inactive'=>'db-badge--muted','graduated'=>'db-badge--info'][$s['status'] ?? ''] ?? 'db-badge--muted';
        $stlabel= ['active'=>'Active','inactive'=>'Inactive','graduated'=>'Graduated'][$s['status'] ?? ''] ?? ucfirst($s['status'] ?? '');
    ?>
    <div style="background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);overflow:hidden;transition:transform .2s,box-shadow .2s;"
         onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='var(--db-shadow-lg)'"
         onmouseout="this.style.transform='';this.style.boxShadow='var(--db-shadow)'">

        <!-- Card header strip -->
        <div style="background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));padding:14px 18px;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px;">
                    <?php echo strtoupper(substr($s['first_name'], 0, 1)); ?>
                </div>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:13.5px;"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></div>
                    <div style="color:rgba(255,255,255,.55);font-family:'DM Mono',monospace;font-size:10px;">#<?php echo str_pad($s['student_id'], 5, '0', STR_PAD_LEFT); ?></div>
                </div>
            </div>
            <?php if ($ss === 'active'): ?>
            <span class="db-badge db-badge--success"><i class="fas fa-award"></i> Scholar</span>
            <?php endif; ?>
        </div>

        <!-- Card body -->
        <div style="padding:16px 18px;">
            <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--db-muted);">
                    <i class="fas fa-school" style="width:14px;color:var(--db-sky);"></i>
                    <?php echo htmlspecialchars($s['school_name']); ?>
                </div>
                <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--db-muted);">
                    <i class="fas fa-graduation-cap" style="width:14px;color:var(--db-indigo);"></i>
                    <?php echo htmlspecialchars($s['grade_level']); ?>
                    <?php if (!empty($s['course'])): ?> — <?php echo htmlspecialchars($s['course']); ?><?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--db-muted);">
                    <i class="fas fa-phone" style="width:14px;color:var(--db-teal);"></i>
                    <?php echo htmlspecialchars($s['contact_number']); ?>
                </div>
                <?php if ($s['gwa_grade']): ?>
                <div style="display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-star" style="width:14px;color:var(--db-amber);font-size:12px;"></i>
                    <span class="db-badge db-badge--primary" style="font-size:10px;">GWA: <?php echo number_format($s['gwa_grade'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
                <span class="db-badge <?php echo $stbadge; ?>"><?php echo $stlabel; ?></span>
                <span class="db-badge <?php echo $sbadge; ?>"><i class="fas <?php echo $sicon; ?>"></i> <?php echo $slabel; ?></span>
            </div>

            <div class="db-btn-group">
                <a href="view-student.php?id=<?php echo $s['student_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View">
                    <i class="fas fa-eye"></i>
                </a>
                <a href="edit-student.php?id=<?php echo $s['student_id']; ?>" class="db-icon-btn db-icon-btn--primary" title="Edit">
                    <i class="fas fa-edit"></i>
                </a>
                <?php if ($ss === 'pending'): ?>
                <button type="button" class="db-icon-btn" style="background:var(--db-success-light);color:var(--db-success);border-color:var(--db-success);"
                        onclick="openApproveModal(<?php echo $s['student_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['first_name'] . ' ' . $s['last_name'])); ?>')"
                        title="Approve Scholarship">
                    <i class="fas fa-check"></i>
                </button>
                <?php endif; ?>
                <button type="button" class="db-icon-btn db-icon-btn--danger"
                        onclick="openDeleteModal(<?php echo $s['student_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['first_name'] . ' ' . $s['last_name'])); ?>')"
                        title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- Approve Modal -->
<div id="approveModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-check-circle"></i> Approve Scholarship</h3>
            <button class="db-modal__close" onclick="closeModal('approveModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="approve_scholarship">
            <input type="hidden" name="student_id" id="approveStudentId">
            <p>Are you sure you want to approve the scholarship for <strong id="approveStudentName"></strong>?</p>
            <div style="display:flex;gap:10px;margin-top:20px;">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full" style="background:var(--db-success);border-color:var(--db-success);">
                    <i class="fas fa-check"></i> Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteStudentModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteStudentModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete:</p>
            <div class="db-delete-target" id="deleteStudentName"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="student_id" id="deleteStudentId">
                <div style="display:flex;gap:10px;margin-top:20px;">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteStudentModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
function openApproveModal(id, name) {
    document.getElementById('approveStudentId').value = id;
    document.getElementById('approveStudentName').textContent = name;
    openModal('approveModal');
}
function openDeleteModal(id, name) {
    document.getElementById('deleteStudentId').value = id;
    document.getElementById('deleteStudentName').textContent = name;
    openModal('deleteStudentModal');
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

<?php $conn->close(); include '../../includes/footer.php'; ?>