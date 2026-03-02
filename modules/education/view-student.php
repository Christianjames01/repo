<?php
require_once '../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();

if ($user_role === 'Resident') {
    header('Location: student-portal.php');
    exit();
}

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'approve_scholarship':
            $sql = "UPDATE tbl_education_students SET
                    scholarship_status='active', approved_by=?, approval_date=NOW(),
                    scholarship_start_date=?, scholarship_end_date=?, scholarship_amount=?
                    WHERE student_id=?";
            if (executeQuery($conn, $sql, [getCurrentUserId(), $_POST['start_date'], $_POST['end_date'] ?? null, $_POST['scholarship_amount'], $student_id], 'issdi'))
                $_SESSION['temp_success'] = 'Scholarship approved successfully';
            break;

        case 'reject_scholarship':
            executeQuery($conn, "UPDATE tbl_education_students SET scholarship_status='rejected', remarks=? WHERE student_id=?",
                [$_POST['remarks'], $student_id], 'si');
            $_SESSION['temp_success'] = 'Scholarship application rejected';
            break;

        case 'update_status':
            executeQuery($conn, "UPDATE tbl_education_students SET status=? WHERE student_id=?",
                [$_POST['status'], $student_id], 'si');
            $_SESSION['temp_success'] = 'Student status updated';
            break;

        case 'add_note':
            $current   = $_POST['current_remarks'] ?? '';
            $new_note  = date('Y-m-d H:i') . ' — ' . getCurrentUserRole() . ': ' . $_POST['new_note'];
            $updated   = trim($current . "\n" . $new_note);
            executeQuery($conn, "UPDATE tbl_education_students SET remarks=? WHERE student_id=?", [$updated, $student_id], 'si');
            $_SESSION['temp_success'] = 'Note added successfully';
            break;
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $student_id); exit();
}

// Fetch student
$student_sql = "SELECT es.*, r.first_name as res_first_name, r.last_name as res_last_name,
                u.username as approved_by_username
                FROM tbl_education_students es
                LEFT JOIN tbl_residents r ON es.resident_id = r.resident_id
                LEFT JOIN tbl_users u ON es.approved_by = u.user_id
                WHERE es.student_id=?";
$student = fetchOne($conn, $student_sql, [$student_id], 'i');

if (!$student) {
    $_SESSION['temp_error'] = 'Student not found';
    header('Location: manage-students.php'); exit();
}

$documents          = fetchAll($conn, "SELECT * FROM tbl_education_documents WHERE student_id=? ORDER BY uploaded_at DESC", [$student_id], 'i');
$assistance_requests= fetchAll($conn, "SELECT * FROM tbl_education_assistance_requests WHERE student_id=? ORDER BY request_date DESC", [$student_id], 'i');

$page_title = 'Student Details';

$success_message = '';
$error_message   = '';
if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']); }

// Compute age
$age = (new DateTime($student['birth_date']))->diff(new DateTime())->y;

// Status maps
$ss        = $student['scholarship_status'];
$sbadge    = ['pending'=>'db-badge--warning','active'=>'db-badge--success','rejected'=>'db-badge--danger','expired'=>'db-badge--muted'][$ss] ?? 'db-badge--muted';
$slabel    = ['pending'=>'Pending Review','active'=>'Active Scholar','rejected'=>'Rejected','expired'=>'Expired'][$ss] ?? ucfirst($ss);
$stbadge   = ['active'=>'db-badge--success','inactive'=>'db-badge--muted','graduated'=>'db-badge--info'][$student['status']] ?? 'db-badge--muted';
$stlabel   = ['active'=>'Active','inactive'=>'Inactive','graduated'=>'Graduated'][$student['status']] ?? ucfirst($student['status']);

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
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#6366f1,#4f46e5);font-size:22px;font-weight:800;">
                <?php echo strtoupper(substr($student['first_name'], 0, 1)); ?>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    Student Details · #<?php echo str_pad($student['student_id'], 5, '0', STR_PAD_LEFT); ?>
                </div>
                <h1 class="db-hero__title"><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?></h1>
                <p class="db-hero__sub"><?php echo htmlspecialchars($student['school_name']); ?> · <?php echo htmlspecialchars($student['grade_level']); ?></p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;">
            <button onclick="window.print()" class="db-btn db-btn--ghost db-btn--sm"
                    style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="manage-students.php" class="db-btn db-btn--ghost db-btn--sm"
               style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.08);">
                <i class="fas fa-arrow-left"></i> Back
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


<!-- STATUS STRIP -->
<div class="db-panel" style="margin-bottom:18px;">
    <div style="padding:18px 22px;display:flex;align-items:center;flex-wrap:wrap;gap:16px;background:var(--db-surf2);border-radius:var(--db-radius-lg);">
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="db-panel__icon db-panel__icon--indigo" style="width:32px;height:32px;font-size:12px;">
                <i class="fas fa-graduation-cap"></i>
            </span>
            <div>
                <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;">Scholarship</div>
                <span class="db-badge <?php echo $sbadge; ?>"><?php echo $slabel; ?></span>
            </div>
        </div>
        <div style="width:1px;height:36px;background:var(--db-border);"></div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="db-panel__icon db-panel__icon--teal" style="width:32px;height:32px;font-size:12px;">
                <i class="fas fa-user-check"></i>
            </span>
            <div>
                <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;">Student Status</div>
                <span class="db-badge <?php echo $stbadge; ?>"><?php echo $stlabel; ?></span>
            </div>
        </div>
        <?php if ($student['gwa_grade']): ?>
        <div style="width:1px;height:36px;background:var(--db-border);"></div>
        <div style="display:flex;align-items:center;gap:10px;">
            <span class="db-panel__icon db-panel__icon--amber" style="width:32px;height:32px;font-size:12px;">
                <i class="fas fa-star"></i>
            </span>
            <div>
                <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;">GWA</div>
                <strong style="font-size:15px;"><?php echo number_format($student['gwa_grade'], 2); ?></strong>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($ss === 'pending'): ?>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <button onclick="openModal('approveModal')"
                    class="db-btn db-btn--sm" style="background:var(--db-success);color:#fff;border:none;gap:6px;">
                <i class="fas fa-check-circle"></i> Approve
            </button>
            <button onclick="openModal('rejectModal')"
                    class="db-btn db-btn--sm" style="background:var(--db-danger);color:#fff;border:none;gap:6px;">
                <i class="fas fa-times-circle"></i> Reject
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- MAIN GRID -->
<div class="db-grid">
    <div class="db-grid__main">

        <!-- Personal Information -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-user"></i></span>
                    <h2>Personal Information</h2>
                </div>
            </div>
            <div style="padding:18px 22px;">
                <?php
                $info_rows = [
                    ['Full Name',      $student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'], true],
                    ['Birth Date',     date('F d, Y', strtotime($student['birth_date'])) . ' (' . $age . ' yrs)', false],
                    ['Gender',         $student['gender'], false],
                    ['Contact Number', $student['contact_number'], false],
                    ['Email',          $student['email'] ?: 'Not provided', false],
                    ['Address',        $student['address'], false],
                ];
                foreach ($info_rows as [$label, $value, $large]):
                ?>
                <div style="display:flex;align-items:flex-start;gap:16px;padding:10px 0;border-bottom:1px solid var(--db-border);">
                    <span style="min-width:140px;font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;padding-top:2px;"><?php echo $label; ?></span>
                    <?php if ($large): ?>
                    <strong style="font-size:16px;"><?php echo htmlspecialchars($value); ?></strong>
                    <?php else: ?>
                    <span style="font-size:13.5px;"><?php echo htmlspecialchars($value); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Educational Information -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-school"></i></span>
                    <h2>Educational Information</h2>
                </div>
            </div>
            <div style="padding:18px 22px;">
                <?php
                $edu_rows = [
                    ['School Name',     $student['school_name']],
                    ['School Year',     $student['school_year']],
                    ['Grade Level',     $student['grade_level']],
                    ['Course',          $student['course'] ?: 'N/A'],
                ];
                if ($student['school_address']) $edu_rows[] = ['School Address', $student['school_address']];
                foreach ($edu_rows as [$label, $value]):
                ?>
                <div style="display:flex;align-items:flex-start;gap:16px;padding:10px 0;border-bottom:1px solid var(--db-border);">
                    <span style="min-width:140px;font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;padding-top:2px;"><?php echo $label; ?></span>
                    <span style="font-size:13.5px;"><?php echo htmlspecialchars($value); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Parent / Guardian -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-users"></i></span>
                    <h2>Parent / Guardian</h2>
                </div>
            </div>
            <div style="padding:18px 22px;">
                <?php
                $par_rows = [
                    ['Name',            $student['parent_guardian_name']],
                    ['Contact',         $student['parent_contact']],
                    ['Occupation',      $student['parent_occupation'] ?: 'N/A'],
                    ['Monthly Income',  $student['monthly_income'] ? '₱' . number_format($student['monthly_income'], 2) : 'N/A'],
                ];
                foreach ($par_rows as [$label, $value]):
                ?>
                <div style="display:flex;align-items:flex-start;gap:16px;padding:10px 0;border-bottom:1px solid var(--db-border);">
                    <span style="min-width:140px;font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;padding-top:2px;"><?php echo $label; ?></span>
                    <span style="font-size:13.5px;"><?php echo htmlspecialchars($value); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Documents -->
        <?php if (!empty($documents)): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-folder-open"></i></span>
                    <h2>Submitted Documents</h2>
                </div>
                <span class="db-badge db-badge--warning"><?php echo count($documents); ?></span>
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead><tr><th>File</th><th>Type</th><th>Uploaded</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($documents as $doc):
                        $ext    = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                        $is_pdf = $ext === 'pdf';
                    ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="db-panel__icon <?php echo $is_pdf ? 'db-panel__icon--rose' : 'db-panel__icon--indigo'; ?>"
                                      style="width:28px;height:28px;font-size:11px;flex-shrink:0;">
                                    <i class="fas <?php echo $is_pdf ? 'fa-file-pdf' : 'fa-file-image'; ?>"></i>
                                </span>
                                <span style="font-size:12.5px;"><?php echo htmlspecialchars($doc['file_name']); ?></span>
                            </div>
                        </td>
                        <td><span class="db-badge db-badge--primary"><?php echo htmlspecialchars($doc['document_type']); ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></span></td>
                        <td>
                            <div class="db-btn-group">
                                <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank"
                                   class="db-icon-btn db-icon-btn--info" title="View"><i class="fas fa-eye"></i></a>
                                <a href="../../uploads/education/<?php echo htmlspecialchars($doc['file_path']); ?>" download
                                   class="db-icon-btn" title="Download"><i class="fas fa-download"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Assistance Requests -->
        <?php if (!empty($assistance_requests)): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-hand-holding-usd"></i></span>
                    <h2>Assistance Requests</h2>
                </div>
                <span class="db-badge db-badge--info"><?php echo count($assistance_requests); ?></span>
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead><tr><th>Type</th><th>Purpose</th><th>Amount</th><th>Date</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($assistance_requests as $req):
                        $rbadge = ['pending'=>'db-badge--warning','approved'=>'db-badge--success','rejected'=>'db-badge--danger','completed'=>'db-badge--info'][$req['status']] ?? 'db-badge--muted';
                    ?>
                    <tr>
                        <td><span class="db-badge db-badge--primary"><?php echo htmlspecialchars($req['assistance_type']); ?></span></td>
                        <td><span class="db-text-sm"><?php echo htmlspecialchars(substr($req['purpose'], 0, 60)); ?></span></td>
                        <td><strong style="color:var(--db-teal);">₱<?php echo number_format($req['requested_amount'], 2); ?></strong></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($req['request_date'])); ?></span></td>
                        <td><span class="db-badge <?php echo $rbadge; ?>"><?php echo ucfirst($req['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /main -->


    <!-- SIDEBAR -->
    <div class="db-grid__side">

        <!-- Scholarship Details -->
        <?php if ($student['scholarship_type']): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-award"></i></span>
                    <h2>Scholarship Details</h2>
                </div>
            </div>
            <div style="padding:14px 22px;display:flex;flex-direction:column;gap:0;">
                <?php
                $schol_rows = [
                    ['Program',    $student['scholarship_type']],
                    ['Amount',     $student['scholarship_amount'] > 0 ? '₱' . number_format($student['scholarship_amount'], 2) : '—'],
                    ['Start Date', $student['scholarship_start_date'] ? date('M d, Y', strtotime($student['scholarship_start_date'])) : '—'],
                    ['End Date',   $student['scholarship_end_date']   ? date('M d, Y', strtotime($student['scholarship_end_date']))   : '—'],
                    ['Approved',   $student['approval_date']          ? date('M d, Y', strtotime($student['approval_date']))          : '—'],
                    ['Approved By',$student['approved_by_username']   ?? '—'],
                ];
                foreach ($schol_rows as $i => [$label, $value]):
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;<?php echo $i < count($schol_rows)-1 ? 'border-bottom:1px solid var(--db-border);' : ''; ?>">
                    <span style="font-size:11px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;"><?php echo $label; ?></span>
                    <strong style="font-size:13px;<?php echo $label === 'Amount' ? 'color:var(--db-teal);' : ''; ?>"><?php echo htmlspecialchars($value); ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Timeline -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-history"></i></span>
                    <h2>Timeline</h2>
                </div>
            </div>
            <div style="padding:18px 22px 8px 48px;position:relative;">
                <div style="position:absolute;left:34px;top:18px;bottom:18px;width:2px;background:var(--db-border);border-radius:2px;"></div>

                <!-- Applied -->
                <div style="display:flex;gap:12px;align-items:flex-start;padding-bottom:18px;position:relative;">
                    <div class="db-panel__icon db-panel__icon--blue"
                         style="width:26px;height:26px;font-size:11px;flex-shrink:0;margin-left:-14px;z-index:1;">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div>
                        <strong style="font-size:13px;">Application Submitted</strong>
                        <div class="db-text-sm"><?php echo date('M d, Y', strtotime($student['application_date'])); ?></div>
                    </div>
                </div>

                <?php if ($ss === 'active' && $student['approval_date']): ?>
                <div style="display:flex;gap:12px;align-items:flex-start;padding-bottom:18px;position:relative;">
                    <div class="db-panel__icon db-panel__icon--teal"
                         style="width:26px;height:26px;font-size:11px;flex-shrink:0;margin-left:-14px;z-index:1;">
                        <i class="fas fa-check"></i>
                    </div>
                    <div>
                        <strong style="font-size:13px;color:var(--db-success);">Approved</strong>
                        <div class="db-text-sm"><?php echo date('M d, Y', strtotime($student['approval_date'])); ?></div>
                        <?php if ($student['approved_by_username']): ?>
                        <div class="db-text-sm">by <?php echo htmlspecialchars($student['approved_by_username']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($ss === 'rejected'): ?>
                <div style="display:flex;gap:12px;align-items:flex-start;padding-bottom:18px;position:relative;">
                    <div class="db-panel__icon db-panel__icon--rose"
                         style="width:26px;height:26px;font-size:11px;flex-shrink:0;margin-left:-14px;z-index:1;">
                        <i class="fas fa-times"></i>
                    </div>
                    <div>
                        <strong style="font-size:13px;color:var(--db-danger);">Rejected</strong>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ss === 'active' && $student['scholarship_start_date']): ?>
                <div style="display:flex;gap:12px;align-items:flex-start;padding-bottom:18px;position:relative;">
                    <div class="db-panel__icon db-panel__icon--indigo"
                         style="width:26px;height:26px;font-size:11px;flex-shrink:0;margin-left:-14px;z-index:1;">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div>
                        <strong style="font-size:13px;color:var(--db-indigo);">Scholarship Active</strong>
                        <div class="db-text-sm"><?php echo date('M d, Y', strtotime($student['scholarship_start_date'])); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Admin Actions -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-cog"></i></span>
                    <h2>Admin Actions</h2>
                </div>
            </div>
            <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
                <a href="edit-student.php?id=<?php echo $student_id; ?>" class="db-btn db-btn--primary db-btn--full">
                    <i class="fas fa-edit"></i> Edit Record
                </a>
                <button onclick="openModal('statusModal')" class="db-btn db-btn--outline db-btn--full">
                    <i class="fas fa-user-cog"></i> Update Status
                </button>
                <button onclick="openModal('noteModal')" class="db-btn db-btn--outline db-btn--full">
                    <i class="fas fa-sticky-note"></i> Add Note
                </button>
                <button onclick="window.print()" class="db-btn db-btn--ghost db-btn--full">
                    <i class="fas fa-print"></i> Print Record
                </button>
            </div>
        </div>

        <!-- Remarks -->
        <?php if ($student['remarks']): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-comment-alt"></i></span>
                    <h2>Remarks & Notes</h2>
                </div>
            </div>
            <div style="padding:14px 22px;">
                <div style="white-space:pre-wrap;font-size:12.5px;color:var(--db-muted);line-height:1.8;font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($student['remarks']); ?></div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /sidebar -->
</div><!-- /grid -->


<!-- Approve Modal -->
<div id="approveModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header" style="background:linear-gradient(135deg,var(--db-success),#059669);">
            <h3 style="color:#fff;"><i class="fas fa-check-circle"></i> Approve Scholarship</h3>
            <button class="db-modal__close" style="color:#fff;" onclick="closeModal('approveModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body" style="padding:22px;">
            <input type="hidden" name="action" value="approve_scholarship">
            <div class="db-field">
                <label>Scholarship Amount <span class="req">*</span></label>
                <div style="display:flex;">
                    <span style="padding:9px 13px;background:var(--db-surf2);border:1.5px solid var(--db-border);border-right:none;border-radius:var(--db-radius-sm) 0 0 var(--db-radius-sm);font-weight:700;color:var(--db-teal);">₱</span>
                    <input type="number" step="0.01" name="scholarship_amount" class="db-input" required
                           value="<?php echo $student['scholarship_amount'] > 0 ? $student['scholarship_amount'] : ''; ?>"
                           style="border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;">
                </div>
            </div>
            <div class="db-field">
                <label>Start Date <span class="req">*</span></label>
                <input type="date" name="start_date" class="db-input"
                       value="<?php echo $student['scholarship_start_date'] ?? date('Y-m-d'); ?>" required>
            </div>
            <div class="db-field">
                <label>End Date <span style="color:var(--db-muted);font-weight:400;">(Optional)</span></label>
                <input type="date" name="end_date" class="db-input"
                       value="<?php echo $student['scholarship_end_date'] ?? ''; ?>">
            </div>
            <div style="display:flex;gap:10px;">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--full" style="background:var(--db-success);color:#fff;border:none;">
                    <i class="fas fa-check"></i> Approve Scholarship
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-times-circle"></i> Reject Application</h3>
            <button class="db-modal__close" onclick="closeModal('rejectModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body" style="padding:22px;">
            <input type="hidden" name="action" value="reject_scholarship">
            <div class="db-field">
                <label>Reason for Rejection <span class="req">*</span></label>
                <textarea name="remarks" class="db-input" rows="4"
                          placeholder="Please provide a clear reason for rejecting this application…" required></textarea>
            </div>
            <div class="db-alert db-alert--error" style="margin-bottom:14px;">
                <div class="db-alert__icon"><i class="fas fa-exclamation-triangle"></i></div>
                <span style="font-size:12.5px;">The student will be notified of this rejection.</span>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-times"></i> Reject Application</button>
            </div>
        </form>
    </div>
</div>

<!-- Update Status Modal -->
<div id="statusModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-user-cog"></i> Update Student Status</h3>
            <button class="db-modal__close" onclick="closeModal('statusModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body" style="padding:22px;">
            <input type="hidden" name="action" value="update_status">
            <div class="db-field">
                <label>Student Status <span class="req">*</span></label>
                <select name="status" class="db-input" required>
                    <option value="active"    <?php echo $student['status']==='active'    ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive"  <?php echo $student['status']==='inactive'  ? 'selected' : ''; ?>>Inactive</option>
                    <option value="graduated" <?php echo $student['status']==='graduated' ? 'selected' : ''; ?>>Graduated</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('statusModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full">Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Note Modal -->
<div id="noteModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3><i class="fas fa-sticky-note"></i> Add Note</h3>
            <button class="db-modal__close" onclick="closeModal('noteModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body" style="padding:22px;">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="current_remarks" value="<?php echo htmlspecialchars($student['remarks'] ?? ''); ?>">
            <div class="db-field">
                <label>Note <span class="req">*</span></label>
                <textarea name="new_note" class="db-input" rows="4"
                          placeholder="Enter your note here…" required></textarea>
                <small style="color:var(--db-muted);font-size:11.5px;">Will be timestamped and attributed to you automatically.</small>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('noteModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Add Note</button>
            </div>
        </form>
    </div>
</div>


<script>
function openModal(id)  { document.getElementById(id).classList.add('db-modal--open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity='0'; a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../includes/footer.php'; ?>