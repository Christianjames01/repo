<?php
require_once '../../../config/config.php';

if (!isLoggedIn() || !hasRole(['Super Admin', 'Admin', 'Staff'])) {
    redirect('/modules/auth/login.php');
}

$page_title = "Permit Details";
$current_user_id = getCurrentUserId();
$permit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$permit_id) { $_SESSION['error_message'] = "Invalid permit ID"; header('Location: applications.php'); exit; }

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action  = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';
    $conn->begin_transaction();
    try {
        switch ($action) {
            case 'approve':
                $permit_fee = (float)($_POST['permit_fee'] ?? 0);
                $issue_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime('+1 year'));
                $new_status = 'Approved';
                $stmt = $conn->prepare("UPDATE tbl_business_permits SET status=?,approved_by=?,approval_date=NOW(),issue_date=?,expiry_date=?,permit_fee=?,amount_paid=0.00,payment_status='unpaid',remarks=? WHERE permit_id=?");
                $stmt->bind_param("sissdsi", $new_status, $current_user_id, $issue_date, $expiry_date, $permit_fee, $remarks, $permit_id);
                $stmt->execute();
                break;
            case 'reject':
                if (empty($remarks)) throw new Exception("Rejection reason is required");
                $new_status = 'Rejected';
                $stmt = $conn->prepare("UPDATE tbl_business_permits SET status=?,rejection_reason=?,remarks=? WHERE permit_id=?");
                $stmt->bind_param("sssi", $new_status, $remarks, $remarks, $permit_id);
                $stmt->execute();
                break;
            case 'pending':
                $new_status = 'Pending';
                $stmt = $conn->prepare("UPDATE tbl_business_permits SET status=?,remarks=? WHERE permit_id=?");
                $stmt->bind_param("ssi", $new_status, $remarks, $permit_id);
                $stmt->execute();
                break;
        }
        try {
            $desc = ucfirst($action) . ' action';
            $h = $conn->prepare("INSERT INTO tbl_business_permit_history (permit_id,action,notes,action_by) VALUES (?,?,?,?)");
            $h->bind_param("issi", $permit_id, $desc, $remarks, $current_user_id);
            $h->execute();
        } catch(Exception $e) {}
        $conn->commit();
        $_SESSION['success_message'] = "Permit status updated successfully!";
        header('Location: view-permit.php?id=' . $permit_id); exit;
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

$stmt = $conn->prepare("SELECT bp.*, r.first_name, r.last_name, r.contact_number as resident_contact,
    r.email as resident_email, r.address as resident_address,
    bt.type_name, bt.base_fee, u.username as approved_by_name
    FROM tbl_business_permits bp
    LEFT JOIN tbl_residents r ON bp.resident_id = r.resident_id
    LEFT JOIN tbl_business_types bt ON bp.business_type_id = bt.type_id
    LEFT JOIN tbl_users u ON bp.approved_by = u.user_id
    WHERE bp.permit_id = ?");
$stmt->bind_param("i", $permit_id);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
if (!$permit) { $_SESSION['error_message'] = "Permit not found"; header('Location: applications.php'); exit; }

$history = []; $inspections = [];
try {
    $hq = $conn->prepare("SELECT h.*, u.username as action_by_name FROM tbl_business_permit_history h LEFT JOIN tbl_users u ON h.action_by = u.user_id WHERE h.permit_id=? ORDER BY h.history_id DESC");
    $hq->bind_param("i", $permit_id); $hq->execute();
    $history = $hq->get_result()->fetch_all(MYSQLI_ASSOC);
} catch(Exception $e) {}
try {
    $iq = $conn->prepare("SELECT i.*, u.username as inspector_name FROM tbl_business_inspections i LEFT JOIN tbl_users u ON i.inspector_id = u.user_id WHERE i.permit_id=? ORDER BY i.inspection_date DESC");
    $iq->bind_param("i", $permit_id); $iq->execute();
    $inspections = $iq->get_result()->fetch_all(MYSQLI_ASSOC);
} catch(Exception $e) {}

$success_msg = ''; $error_msg = '';
if (isset($_SESSION['success_message'])) { $success_msg = $_SESSION['success_message']; unset($_SESSION['success_message']); }
if (isset($_SESSION['error_message']))   { $error_msg   = $_SESSION['error_message'];   unset($_SESSION['error_message']); }

$status = $permit['status'] ?? 'Pending';
$badge_map = ['Pending'=>'db-badge--warning','for_inspection'=>'db-badge--info','Approved'=>'db-badge--success','Rejected'=>'db-badge--danger','expired'=>'db-badge--muted','cancelled'=>'db-badge--muted'];

$days_left = !empty($permit['expiry_date']) ? (strtotime($permit['expiry_date']) - time()) / 86400 : null;

$extra_css = '<link rel="stylesheet" href="../../../assets/css/dashboard-index.css?v=' . time() . '">';
include_once '../../../includes/header.php';
?>

<!-- ═══ HERO ═══ -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
                <i class="fas fa-file-alt" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-staff">
                    <span class="db-hero__role-dot"></span>
                    Business Permits
                </div>
                <h1 class="db-hero__title"><?php echo htmlspecialchars($permit['business_name'] ?? 'Permit Details'); ?></h1>
                <p class="db-hero__sub">
                    Permit #: <span style="font-family:'DM Mono',monospace;"><?php echo htmlspecialchars($permit['permit_number'] ?? 'Pending'); ?></span>
                    &nbsp;·&nbsp;
                    <span class="db-badge <?php echo $badge_map[$status] ?? 'db-badge--muted'; ?>"><?php echo ucfirst(str_replace('_',' ',$status)); ?></span>
                </p>
            </div>
        </div>
        <div class="db-hero__right" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
            <a href="applications.php" class="db-btn db-btn--ghost"><i class="fas fa-arrow-left"></i> Back</a>
            <?php if ($status === 'Approved'): ?>
                <a href="print-permit.php?id=<?php echo $permit_id; ?>" class="db-btn db-btn--ghost" target="_blank"><i class="fas fa-print"></i> Print</a>
            <?php endif; ?>
            <?php if (in_array($status, ['Pending', 'for_inspection'])): ?>
                <button class="db-btn db-btn--primary" onclick="openModal('approveModal')"><i class="fas fa-check"></i> Approve</button>
                <button class="db-btn db-btn--danger" onclick="openModal('rejectModal')"><i class="fas fa-times"></i> Reject</button>
            <?php elseif ($status === 'Approved'): ?>
                <button class="db-btn db-btn--ghost" onclick="openModal('pendingModal')"><i class="fas fa-undo"></i> Set Pending</button>
                <button class="db-btn db-btn--danger" onclick="openModal('rejectModal')"><i class="fas fa-times"></i> Reject</button>
            <?php elseif ($status === 'Rejected'): ?>
                <button class="db-btn db-btn--primary" onclick="openModal('approveModal')"><i class="fas fa-check"></i> Approve</button>
                <button class="db-btn db-btn--ghost" onclick="openModal('pendingModal')"><i class="fas fa-undo"></i> Set Pending</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($success_msg): ?>
<div class="db-alert db-alert--success"><div class="db-alert__icon"><i class="fas fa-check-circle"></i></div><span><?php echo htmlspecialchars($success_msg); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($error_msg): ?>
<div class="db-alert db-alert--error"><div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div><span><?php echo htmlspecialchars($error_msg); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- ═══ MAIN GRID ═══ -->
<div class="db-grid">
    <div class="db-grid__main">

        <!-- Business Information -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-store"></i></span>
                    <h2>Business Information</h2>
                </div>
            </div>
            <div class="vp-info-grid">
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-briefcase"></i> Business Type</div>
                    <div class="vp-info-value"><?php echo htmlspecialchars($permit['business_type'] ?? $permit['type_name'] ?? 'N/A'); ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-certificate"></i> Permit Number</div>
                    <div class="vp-info-value"><span class="db-id"><?php echo htmlspecialchars($permit['permit_number'] ?? 'Pending'); ?></span></div>
                </div>
                <div class="vp-info-item vp-info-item--wide">
                    <div class="vp-info-label"><i class="fas fa-map-marker-alt"></i> Business Address</div>
                    <div class="vp-info-value"><?php echo nl2br(htmlspecialchars($permit['business_address'] ?? 'N/A')); ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-peso-sign"></i> Capital Investment</div>
                    <div class="vp-info-value">₱<?php echo number_format($permit['capital_investment'] ?? 0, 2); ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-users"></i> Employees</div>
                    <div class="vp-info-value"><?php echo $permit['num_employees'] ?? 0; ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-ruler-combined"></i> Floor Area</div>
                    <div class="vp-info-value"><?php echo $permit['business_area_sqm'] ?? 0; ?> sq.m</div>
                </div>
            </div>
        </div>

        <!-- Owner Information -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-user-tie"></i></span>
                    <h2>Owner Information</h2>
                </div>
            </div>
            <div class="vp-info-grid">
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-user"></i> Owner Name</div>
                    <div class="vp-info-value"><strong><?php echo htmlspecialchars($permit['owner_name'] ?? 'N/A'); ?></strong></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-id-card"></i> Resident Name</div>
                    <div class="vp-info-value"><?php echo htmlspecialchars(($permit['first_name'] ?? '') . ' ' . ($permit['last_name'] ?? '')); ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-phone"></i> Contact Number</div>
                    <div class="vp-info-value"><?php echo htmlspecialchars($permit['owner_contact'] ?? $permit['resident_contact'] ?? 'N/A'); ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="vp-info-value"><?php echo htmlspecialchars($permit['owner_email'] ?? $permit['resident_email'] ?? 'N/A'); ?></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-file-invoice"></i> TIN</div>
                    <div class="vp-info-value"><span class="db-id"><?php echo htmlspecialchars($permit['tin_number'] ?? 'N/A'); ?></span></div>
                </div>
                <div class="vp-info-item">
                    <div class="vp-info-label"><i class="fas fa-building"></i> DTI Registration</div>
                    <div class="vp-info-value"><?php echo htmlspecialchars($permit['dti_registration'] ?? 'N/A'); ?></div>
                </div>
            </div>
        </div>

        <!-- Documents -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-file-alt"></i></span>
                    <h2>Submitted Documents</h2>
                </div>
            </div>
            <?php
            $docs_json = $permit['documents'] ?? null;
            $docs = $docs_json ? json_decode($docs_json, true) : null;
            if ($docs && is_array($docs)): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead><tr><th>Document Type</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($docs as $key => $doc): ?>
                    <tr>
                        <td><i class="fas fa-file-pdf" style="color:var(--db-rose);margin-right:8px;"></i><?php echo htmlspecialchars($doc['label'] ?? $key); ?></td>
                        <td><span class="db-badge db-badge--success">Submitted</span></td>
                        <td><a href="../../../uploads/business/<?php echo $doc['filename']; ?>" class="db-btn db-btn--ghost db-btn--sm" target="_blank"><i class="fas fa-eye"></i> View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="db-empty"><i class="fas fa-file-alt"></i><p>No documents uploaded.</p></div>
            <?php endif; ?>
        </div>

        <!-- Inspections -->
        <?php if (!empty($inspections)): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-clipboard-check"></i></span>
                    <h2>Inspection Records</h2>
                </div>
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Inspector</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($inspections as $ins):
                        $rb = ['Passed'=>'db-badge--success','Failed'=>'db-badge--danger','Conditional'=>'db-badge--warning'];
                        $r = $ins['overall_result'] ?? 'Pending';
                    ?>
                    <tr>
                        <td><span style="font-family:'DM Mono',monospace;font-size:12px;"><?php echo date('M d, Y', strtotime($ins['inspection_date'])); ?></span></td>
                        <td><?php echo htmlspecialchars($ins['inspection_type'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($ins['inspector_name'] ?? 'N/A'); ?></td>
                        <td><span class="db-badge <?php echo $rb[$r] ?? 'db-badge--muted'; ?>"><?php echo $r; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- History Timeline -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-history"></i></span>
                    <h2>Activity History</h2>
                </div>
            </div>
            <?php if (!empty($history)): ?>
            <div style="padding:20px 24px;">
                <div class="vp-timeline">
                    <?php foreach ($history as $item): ?>
                    <div class="vp-timeline__item">
                        <div class="vp-timeline__dot"></div>
                        <div class="vp-timeline__content">
                            <strong style="font-size:13px;"><?php echo htmlspecialchars($item['action'] ?? 'Action'); ?></strong>
                            <?php if (!empty($item['notes'])): ?>
                                <p style="font-size:12px;color:var(--db-muted);margin:3px 0;"><?php echo htmlspecialchars($item['notes']); ?></p>
                            <?php endif; ?>
                            <small style="font-size:11px;color:var(--db-muted);"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($item['action_by_name'] ?? 'System'); ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="db-empty"><i class="fas fa-history"></i><p>No activity history yet.</p></div>
            <?php endif; ?>
        </div>

    </div><!-- /db-grid__main -->

    <div class="db-grid__side">

        <!-- Permit Dates -->
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-calendar-alt"></i></span>
                    <h2>Permit Dates</h2>
                </div>
            </div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:14px;">
                <?php if (!empty($permit['application_date'])): ?>
                <div>
                    <div style="font-size:11px;color:var(--db-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Application Date</div>
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:600;"><?php echo date('F d, Y', strtotime($permit['application_date'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($permit['issue_date'])): ?>
                <div>
                    <div style="font-size:11px;color:var(--db-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Issue Date</div>
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:600;"><?php echo date('F d, Y', strtotime($permit['issue_date'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($permit['expiry_date'])): ?>
                <div>
                    <div style="font-size:11px;color:var(--db-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">Expiry Date</div>
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:600;"><?php echo date('F d, Y', strtotime($permit['expiry_date'])); ?></div>
                    <?php if ($days_left !== null && $days_left <= 30 && $days_left > 0): ?>
                        <div style="font-size:11.5px;color:var(--db-amber);margin-top:3px;"><i class="fas fa-exclamation-triangle"></i> Expires in <?php echo ceil($days_left); ?> days</div>
                    <?php elseif ($days_left !== null && $days_left <= 0): ?>
                        <div style="font-size:11.5px;color:var(--db-rose);margin-top:3px;"><i class="fas fa-times-circle"></i> Expired</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fee Information -->
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-peso-sign"></i></span>
                    <h2>Fee Information</h2>
                </div>
            </div>
            <div style="padding:16px 20px;">
                <?php
                $fee     = $permit['permit_fee'] ?? 0;
                $paid    = $permit['amount_paid'] ?? 0;
                $balance = $fee - $paid;
                $pay_map = ['unpaid'=>'db-badge--danger','partial'=>'db-badge--warning','paid'=>'db-badge--success'];
                $pc = $pay_map[$permit['payment_status'] ?? 'unpaid'] ?? 'db-badge--muted';
                ?>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                        <span style="color:var(--db-muted);">Permit Fee</span>
                        <span style="font-family:'DM Mono',monospace;font-weight:600;">₱<?php echo number_format($fee, 2); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                        <span style="color:var(--db-muted);">Amount Paid</span>
                        <span style="font-family:'DM Mono',monospace;font-weight:600;color:var(--db-success);">₱<?php echo number_format($paid, 2); ?></span>
                    </div>
                    <div style="height:1px;background:var(--db-border);margin:4px 0;"></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;">
                        <span style="font-weight:700;">Balance</span>
                        <span style="font-family:'DM Mono',monospace;font-weight:800;color:<?php echo $balance > 0 ? 'var(--db-rose)' : 'var(--db-success)'; ?>;">₱<?php echo number_format($balance, 2); ?></span>
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <span class="db-badge <?php echo $pc; ?>" style="width:100%;justify-content:center;display:flex;"><?php echo ucfirst($permit['payment_status'] ?? 'unpaid'); ?></span>
                </div>
                <?php if (!empty($permit['or_number'])): ?>
                    <div style="margin-top:8px;font-size:11.5px;color:var(--db-muted);">OR #: <span class="db-id"><?php echo htmlspecialchars($permit['or_number']); ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Owner Quick Info -->
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-user"></i></span>
                    <h2>Owner Contact</h2>
                </div>
            </div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px;">
                <div style="font-size:15px;font-weight:700;"><?php echo htmlspecialchars(($permit['first_name'] ?? '') . ' ' . ($permit['last_name'] ?? '')); ?></div>
                <div style="font-size:12.5px;color:var(--db-muted);"><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($permit['resident_contact'] ?? 'N/A'); ?></div>
                <div style="font-size:12.5px;color:var(--db-muted);"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($permit['resident_email'] ?? 'N/A'); ?></div>
                <div style="font-size:12.5px;color:var(--db-muted);"><i class="fas fa-home me-2"></i><?php echo htmlspecialchars($permit['resident_address'] ?? 'N/A'); ?></div>
                <?php if (!empty($permit['resident_id'])): ?>
                <div style="margin-top:4px;">
                    <a href="../../residents/view.php?id=<?php echo $permit['resident_id']; ?>" class="db-btn db-btn--ghost db-btn--sm db-btn--full"><i class="fas fa-user-circle"></i> View Full Profile</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Processing Info -->
        <?php if (!empty($permit['approved_by'])): ?>
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-user-check"></i></span>
                    <h2>Processing Info</h2>
                </div>
            </div>
            <div style="padding:16px 20px;">
                <div style="font-size:12px;color:var(--db-muted);margin-bottom:4px;">Approved By</div>
                <div style="font-weight:600;font-size:14px;"><?php echo htmlspecialchars($permit['approved_by_name'] ?? 'N/A'); ?></div>
                <?php if (!empty($permit['approval_date'])): ?>
                    <div style="font-size:12px;color:var(--db-muted);margin-top:4px;font-family:'DM Mono',monospace;"><?php echo date('M d, Y', strtotime($permit['approval_date'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /db-grid__side -->
</div>

<!-- ═══ MODALS ═══ -->

<!-- Approve Modal -->
<div class="db-modal" id="approveModal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3 class="db-modal__title"><i class="fas fa-check-circle" style="color:var(--db-success);"></i> Approve Permit Application</h3>
            <button class="db-modal__close" onclick="closeModal('approveModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="approve">
                <p style="margin-bottom:14px;">Processing permit for: <strong><?php echo htmlspecialchars($permit['business_name']); ?></strong></p>
                <div style="background:color-mix(in srgb,var(--db-sky) 8%,white);border:1px solid color-mix(in srgb,var(--db-sky) 20%,white);border-radius:var(--db-radius-sm);padding:10px 14px;font-size:12.5px;margin-bottom:14px;">
                    <i class="fas fa-info-circle" style="color:var(--db-sky);"></i> The permit will be valid for 1 year from today.
                </div>
                <div class="db-field-row">
                    <div class="db-field">
                        <label class="db-field__label">Permit Fee <span class="req">*</span></label>
                        <input type="number" name="permit_fee" class="db-input" value="<?php echo $permit['base_fee'] ?? 0; ?>" step="0.01" min="0" required id="permitFee" oninput="calcTotal()">
                    </div>
                    <div class="db-field">
                        <label class="db-field__label">Sanitary Fee</label>
                        <input type="number" name="sanitary_fee" class="db-input" value="500.00" step="0.01" min="0" id="sanitaryFee" oninput="calcTotal()">
                    </div>
                </div>
                <div class="db-field-row" style="margin-top:12px;">
                    <div class="db-field">
                        <label class="db-field__label">Garbage Fee</label>
                        <input type="number" name="garbage_fee" class="db-input" value="300.00" step="0.01" min="0" id="garbageFee" oninput="calcTotal()">
                    </div>
                    <div class="db-field">
                        <label class="db-field__label">Total Fee</label>
                        <input type="text" class="db-input" id="totalFee" readonly style="font-family:'DM Mono',monospace;font-weight:700;background:var(--db-surf2);">
                    </div>
                </div>
                <div class="db-field" style="margin-top:12px;">
                    <label class="db-field__label">Remarks</label>
                    <textarea name="remarks" class="db-input" rows="3" placeholder="Add any notes or conditions..."></textarea>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-check"></i> Approve & Calculate Fees</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div class="db-modal" id="rejectModal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3 class="db-modal__title"><i class="fas fa-times-circle" style="color:var(--db-rose);"></i> Reject Application</h3>
            <button class="db-modal__close" onclick="closeModal('rejectModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="reject">
                <div class="db-delete-warn">
                    <i class="fas fa-exclamation-triangle"></i>
                    You are rejecting the application for <strong><?php echo htmlspecialchars($permit['business_name']); ?></strong>.
                </div>
                <div class="db-field" style="margin-top:14px;">
                    <label class="db-field__label">Reason for Rejection <span class="req">*</span></label>
                    <textarea name="remarks" class="db-input" rows="4" required placeholder="Explain why this application is being rejected..."></textarea>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--danger"><i class="fas fa-times"></i> Reject Application</button>
            </div>
        </form>
    </div>
</div>

<!-- Pending Modal -->
<div class="db-modal" id="pendingModal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header">
            <h3 class="db-modal__title"><i class="fas fa-undo" style="color:var(--db-amber);"></i> Set Status to Pending</h3>
            <button class="db-modal__close" onclick="closeModal('pendingModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="pending">
                <div style="font-size:13px;margin-bottom:14px;">Revert <strong><?php echo htmlspecialchars($permit['business_name']); ?></strong> back to pending status for further review?</div>
                <div class="db-field">
                    <label class="db-field__label">Reason (Optional)</label>
                    <textarea name="remarks" class="db-input" rows="3" placeholder="Explain why status is being changed..."></textarea>
                </div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('pendingModal')">Cancel</button>
                <button type="submit" class="db-btn db-btn--ghost" style="border-color:var(--db-amber);color:var(--db-amber);"><i class="fas fa-undo"></i> Set to Pending</button>
            </div>
        </form>
    </div>
</div>

<style>
.db-field { display:flex;flex-direction:column;gap:5px; }
.db-field__label { font-size:11.5px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.4px; }
.db-field-row { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
.req { color:var(--db-rose); }

.vp-info-grid { display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:1px solid var(--db-border); }
.vp-info-item { padding:16px 24px;border-bottom:1px solid var(--db-border);border-right:1px solid var(--db-border); }
.vp-info-item:nth-child(even) { border-right:none; }
.vp-info-item--wide { grid-column:1/-1;border-right:none; }
.vp-info-label { font-size:11px;color:var(--db-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px; }
.vp-info-label i { margin-right:5px; }
.vp-info-value { font-size:13.5px;font-weight:500;color:var(--db-text); }

.vp-timeline { position:relative;padding-left:24px; }
.vp-timeline::before { content:'';position:absolute;left:6px;top:0;bottom:0;width:2px;background:var(--db-border); }
.vp-timeline__item { position:relative;padding-bottom:20px; }
.vp-timeline__dot { position:absolute;left:-24px;top:2px;width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid var(--db-indigo); }
.vp-timeline__content { padding-left:8px; }

.db-delete-warn { background:color-mix(in srgb,var(--db-rose) 8%,white);border:1px solid color-mix(in srgb,var(--db-rose) 20%,white);border-radius:var(--db-radius-sm);padding:12px 14px;font-size:13px;display:flex;gap:10px;align-items:flex-start; }
.db-delete-warn i { color:var(--db-rose);margin-top:2px;flex-shrink:0; }
</style>

<script>
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';if(id==='approveModal')calcTotal();}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
document.querySelectorAll('.db-modal').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)closeModal(m.id);});});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});

function calcTotal() {
    const p=parseFloat(document.getElementById('permitFee')?.value)||0;
    const s=parseFloat(document.getElementById('sanitaryFee')?.value)||0;
    const g=parseFloat(document.getElementById('garbageFee')?.value)||0;
    const el=document.getElementById('totalFee');
    if(el)el.value='₱'+((p+s+g).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}));
}
calcTotal();

setTimeout(()=>{
    document.querySelectorAll('.db-alert').forEach(a=>{
        a.style.opacity='0';a.style.transform='translateY(-8px)';
        setTimeout(()=>a.remove(),400);
    });
},5000);
</script>

<?php include_once '../../../includes/footer.php'; ?>