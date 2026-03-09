<?php
/**
 * Leave Request Form (For Staff) - Restyled to match Admin Attendance UI
 * modules/attendance/leave-request.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('/barangaylink1/modules/auth/login.php', 'Please login to continue', 'error');
}

$page_title = 'Request Leave';
$current_user_id = getCurrentUserId();
$user_role = getCurrentUserRole();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    $leave_type = sanitizeInput($_POST['leave_type']);
    $start_date = sanitizeInput($_POST['start_date']);
    $end_date   = sanitizeInput($_POST['end_date']);
    $reason     = sanitizeInput($_POST['reason']);

    if (strtotime($start_date) > strtotime($end_date)) {
        $_SESSION['error_message'] = 'End date must be after start date';
    } elseif (strtotime($start_date) < strtotime(date('Y-m-d'))) {
        $_SESSION['error_message'] = 'Start date cannot be in the past';
    } else {
        $overlap = fetchOne($conn,
            "SELECT leave_id FROM tbl_leave_requests
            WHERE user_id = ?
            AND status IN ('Pending', 'Approved')
            AND (
                (start_date <= ? AND end_date >= ?) OR
                (start_date <= ? AND end_date >= ?) OR
                (start_date >= ? AND end_date <= ?)
            )",
            [$current_user_id, $start_date, $start_date, $end_date, $end_date, $start_date, $end_date],
            'issssss'
        );

        if ($overlap) {
            $_SESSION['error_message'] = 'You already have a leave request for these dates';
        } else {
            $sql = "INSERT INTO tbl_leave_requests (user_id, leave_type, start_date, end_date, reason, status)
                    VALUES (?, ?, ?, ?, ?, 'Pending')";

            if (executeQuery($conn, $sql, [$current_user_id, $leave_type, $start_date, $end_date, $reason], 'issss')) {
                $leave_id = $conn->insert_id;
                logActivity($conn, $current_user_id, 'Submitted leave request', 'tbl_leave_requests', $leave_id);

                $admins = fetchAll($conn,
                    "SELECT user_id FROM tbl_users WHERE role IN ('Admin', 'Super Admin') AND is_active = 1"
                );
                if ($admins) {
                    foreach ($admins as $admin) {
                        createNotification($conn, $admin['user_id'], 'New Leave Request',
                            "A new $leave_type request has been submitted from $start_date to $end_date",
                            'leave_request', $leave_id, 'leave');
                    }
                }

                $_SESSION['success_message'] = 'Leave request submitted successfully';
                if (in_array($user_role, ['Admin', 'Super Admin'])) {
                    header('Location: admin/manage-leaves.php');
                } else {
                    header('Location: manage-leaves.php');
                }
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to submit leave request';
            }
        }
    }
}

$leave_types = [
    'Sick Leave', 'Vacation Leave', 'Emergency Leave',
    'Personal Leave', 'Bereavement Leave', 'Maternity Leave', 'Paternity Leave'
];

$leave_icons = [
    'Sick Leave'        => 'fa-briefcase-medical',
    'Vacation Leave'    => 'fa-umbrella-beach',
    'Emergency Leave'   => 'fa-exclamation-triangle',
    'Personal Leave'    => 'fa-user',
    'Bereavement Leave' => 'fa-heart-broken',
    'Maternity Leave'   => 'fa-baby',
    'Paternity Leave'   => 'fa-baby-carriage',
];

$manage_url = in_array($user_role, ['Admin', 'Super Admin']) ? 'admin/manage-leaves.php' : 'manage-leaves.php';

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<style>
/* ── Design tokens (matches index.php) ───────────────────────────── */
:root {
    --navy-deep : #0d1b36;
    --navy-mid  : #1c3461;
    --navy-light: #2a4a82;
    --amber     : #f59e0b;
    --amber-dark: #d97706;
    --green     : #10b981;
    --rose      : #e11d48;
    --sky       : #0ea5e9;
    --indigo    : #6366f1;
    --slate-50  : #f8fafc;
    --slate-100 : #f1f5f9;
    --slate-200 : #e2e8f0;
    --slate-400 : #94a3b8;
    --slate-600 : #475569;
    --slate-900 : #0f172a;
}

/* ── Leave-type card grid ─────────────────────────────────────────── */
.lr-type-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
    gap: 10px;
    margin-bottom: 6px;
}
.lr-type-card {
    border: 2px solid var(--slate-200);
    border-radius: 12px;
    padding: 14px 12px;
    cursor: pointer;
    text-align: center;
    background: #fff;
    transition: all .18s ease;
    position: relative;
    overflow: hidden;
}
.lr-type-card:hover {
    border-color: var(--navy-mid);
    background: #f0f4ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(13,27,54,.12);
}
.lr-type-card.selected {
    border-color: var(--navy-mid);
    background: linear-gradient(135deg, #eff6ff, #f0f4ff);
    box-shadow: 0 4px 18px rgba(28,52,97,.18);
}
.lr-type-card.selected::after {
    content: '\f00c';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    position: absolute;
    top: 6px; right: 8px;
    font-size: 10px;
    color: var(--navy-mid);
}
.lr-type-card .lr-type-icon {
    width: 40px; height: 40px; border-radius: 10px;
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 8px;
    font-size: 15px; color: #fff;
    transition: transform .18s;
}
.lr-type-card:hover .lr-type-icon,
.lr-type-card.selected .lr-type-icon { transform: scale(1.1); }
.lr-type-card .lr-type-label {
    font-family: 'Sora', sans-serif;
    font-size: 11.5px; font-weight: 700;
    color: var(--slate-900); line-height: 1.3;
}
input[name="leave_type"] { display: none; }

/* ── Date range row ───────────────────────────────────────────────── */
.lr-date-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 0;
}
@media (max-width: 540px) { .lr-date-row { grid-template-columns: 1fr; } }

/* ── Duration pill ────────────────────────────────────────────────── */
.lr-duration-pill {
    display: none;
    align-items: center; gap: 8px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border: 1px solid #bfdbfe;
    border-radius: 10px; padding: 10px 16px;
    font-family: 'DM Mono', monospace;
    font-size: 13px; font-weight: 600; color: #1e40af;
    margin-top: 12px;
}
.lr-duration-pill.warn {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border-color: #fde68a; color: #92400e;
}
.lr-duration-pill .lr-dur-num {
    font-size: 22px; font-weight: 800;
    color: var(--navy-mid); line-height: 1;
}
.lr-duration-pill.warn .lr-dur-num { color: var(--amber-dark); }

/* ── Reason textarea ──────────────────────────────────────────────── */
.lr-reason-wrap { position: relative; }
.lr-reason-wrap textarea { padding-bottom: 28px !important; resize: vertical; min-height: 110px; }
.lr-char-count {
    position: absolute; bottom: 10px; right: 12px;
    font-family: 'DM Mono', monospace;
    font-size: 10.5px; color: var(--slate-400);
    pointer-events: none;
}
.lr-char-count.ok  { color: var(--green); }
.lr-char-count.bad { color: var(--rose); }

/* ── Field label ──────────────────────────────────────────────────── */
.lr-field { margin-bottom: 22px; }
.lr-field label {
    display: flex; align-items: center; gap: 7px;
    font-family: 'Sora', sans-serif;
    font-size: 11.5px; font-weight: 700;
    color: var(--slate-600); text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 8px;
}
.lr-field label .req { color: var(--rose); }
.lr-input {
    width: 100%; padding: 10px 14px;
    border: 1.5px solid var(--slate-200); border-radius: 9px;
    font-family: 'Sora', sans-serif; font-size: 13px; color: var(--slate-900);
    background: var(--slate-50); outline: none;
    transition: border-color .18s, box-shadow .18s, background .18s;
    appearance: none;
}
.lr-input:focus {
    border-color: var(--navy-mid);
    box-shadow: 0 0 0 3px rgba(28,52,97,.1);
    background: #fff;
}

/* ── Reminder card ────────────────────────────────────────────────── */
.lr-reminder {
    background: linear-gradient(135deg, #fffbeb, #fef9c3);
    border: 1px solid #fde68a; border-radius: 12px;
    padding: 14px 18px; margin-bottom: 22px;
}
.lr-reminder-title {
    font-family: 'Sora', sans-serif;
    font-size: 12px; font-weight: 700;
    color: #92400e; text-transform: uppercase;
    letter-spacing: .5px; margin-bottom: 9px;
    display: flex; align-items: center; gap: 6px;
}
.lr-reminder ul {
    margin: 0; padding-left: 18px;
    font-family: 'Sora', sans-serif;
    font-size: 12px; color: #78350f; line-height: 1.8;
}

/* ── Guidelines split ─────────────────────────────────────────────── */
.lr-guide-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
    margin-top: 20px;
}
@media (max-width: 540px) { .lr-guide-grid { grid-template-columns: 1fr; } }
.lr-guide-col {
    background: var(--slate-50); border: 1px solid var(--slate-200);
    border-radius: 12px; padding: 14px 16px;
}
.lr-guide-col-title {
    font-family: 'Sora', sans-serif;
    font-size: 11.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}
.lr-guide-col-title.do  { color: var(--green); }
.lr-guide-col-title.dont { color: var(--rose); }
.lr-guide-col ul {
    margin: 0; padding-left: 16px;
    font-family: 'Sora', sans-serif;
    font-size: 11.5px; color: var(--slate-600); line-height: 1.85;
}

/* ── Submit button ────────────────────────────────────────────────── */
.lr-submit-btn {
    width: 100%; padding: 13px 28px;
    border: none; border-radius: 10px;
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    color: #fff; font-family: 'Sora', sans-serif;
    font-size: 14px; font-weight: 700;
    cursor: pointer; transition: all .18s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    margin-bottom: 10px;
}
.lr-submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(13,27,54,.3);
}
.lr-cancel-btn {
    width: 100%; padding: 11px 28px;
    border: 1.5px solid var(--slate-200); border-radius: 10px;
    background: #fff; color: var(--slate-600);
    font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .15s; text-align: center; display: block;
    text-decoration: none;
}
.lr-cancel-btn:hover { border-color: var(--slate-400); color: var(--slate-900); }

/* ── Confirmation modal ───────────────────────────────────────────── */
.lr-modal-header {
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    padding: 18px 22px; border-radius: 20px 20px 0 0;
    display: flex; align-items: center; justify-content: space-between;
}
.lr-modal-header h3 {
    color: #fff; font-family: 'Sora', sans-serif;
    font-size: 15px; font-weight: 700;
    display: flex; align-items: center; gap: 8px; margin: 0;
}
.lr-modal-close {
    background: rgba(255,255,255,.12); border: none;
    color: rgba(255,255,255,.85); width: 30px; height: 30px;
    border-radius: 7px; cursor: pointer; font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.lr-modal-close:hover { background: rgba(255,255,255,.25); }
.lr-modal-body { padding: 22px; }
.lr-modal-footer {
    padding: 16px 22px; border-top: 1px solid var(--slate-200);
    display: flex; gap: 10px; justify-content: flex-end;
    background: var(--slate-50); border-radius: 0 0 20px 20px;
}

.lr-review-card {
    background: var(--slate-50); border: 1px solid var(--slate-200);
    border-radius: 12px; overflow: hidden; margin: 14px 0;
}
.lr-review-row {
    display: flex; gap: 0;
    border-bottom: 1px solid var(--slate-200);
}
.lr-review-row:last-child { border-bottom: none; }
.lr-review-label {
    width: 140px; flex-shrink: 0;
    padding: 12px 16px;
    background: #f0f4ff;
    font-family: 'Sora', sans-serif;
    font-size: 11px; font-weight: 700;
    color: var(--slate-600); text-transform: uppercase;
    letter-spacing: .4px;
    display: flex; align-items: center; gap: 6px;
    border-right: 1px solid var(--slate-200);
}
.lr-review-val {
    padding: 12px 16px; flex: 1;
    font-family: 'Sora', sans-serif;
    font-size: 13px; color: var(--slate-900); font-weight: 500;
    overflow-wrap: anywhere;
}
.lr-review-val .badge-dur {
    display: inline-flex; align-items: center; gap: 5px;
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    color: #fff; border-radius: 20px; padding: 3px 12px;
    font-family: 'DM Mono', monospace; font-size: 11px; font-weight: 700;
}

.lr-warn-box {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1px solid #fde68a; border-radius: 10px;
    padding: 12px 16px; font-family: 'Sora', sans-serif;
    font-size: 12px; color: #92400e;
    display: flex; gap: 8px; align-items: flex-start;
}

.lr-save-btn {
    padding: 10px 28px; border-radius: 8px; border: none;
    background: linear-gradient(135deg, var(--navy-deep), var(--navy-mid));
    color: #fff; font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 700;
    cursor: pointer; transition: all .18s;
    display: flex; align-items: center; gap: 6px;
}
.lr-save-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(13,27,54,.3); }
.lr-save-btn:disabled { opacity: .7; cursor: not-allowed; transform: none; }
.lr-back-btn {
    padding: 10px 20px; border-radius: 8px;
    border: 1.5px solid var(--slate-200); background: #fff;
    color: var(--slate-600); font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s;
}
.lr-back-btn:hover { border-color: var(--slate-400); color: var(--slate-900); }

/* ── db-modal reuse ───────────────────────────────────────────────── */
.db-modal {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(13,27,54,.55); backdrop-filter: blur(3px);
    align-items: center; justify-content: center; padding: 20px;
}
.db-modal__box {
    background: #fff; border-radius: 20px;
    box-shadow: 0 24px 64px rgba(13,27,54,.28);
    width: 100%; max-height: 90vh; overflow-y: auto;
    animation: lrModalIn .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes lrModalIn {
    from { opacity: 0; transform: scale(.92) translateY(16px); }
    to   { opacity: 1; transform: scale(1)  translateY(0); }
}
</style>

<!-- ─── HERO ─────────────────────────────────────────────────────────────── -->
<div class="db-hero">
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>
    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar">
                <i class="fas fa-calendar-plus" style="font-size:22px;color:#fff;"></i>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge badge-admin">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($user_role); ?>
                </div>
                <h1 class="db-hero__title">Request Leave</h1>
                <p class="db-hero__sub">Submit a new leave request for administrator review</p>
            </div>
        </div>
        <div class="db-hero__right">
            <a href="<?php echo $manage_url; ?>" class="db-btn db-btn--ghost">
                <i class="fas fa-arrow-left"></i> Back to My Leaves
            </a>
        </div>
    </div>
</div>

<!-- ─── ALERTS ───────────────────────────────────────────────────────────── -->
<?php if (!empty($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ─── MAIN PANEL ───────────────────────────────────────────────────────── -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-file-alt"></i></span>
            <h2>Leave Request Form</h2>
        </div>
    </div>

    <div style="padding: 24px 28px;">
        <form method="POST" id="leaveRequestForm">

            <!-- Leave Type -->
            <div class="lr-field">
                <label>
                    <i class="fas fa-tag" style="color:var(--navy-mid);"></i>
                    Leave Type <span class="req">*</span>
                </label>
                <input type="hidden" name="leave_type" id="leave_type_hidden" required>
                <div class="lr-type-grid">
                    <?php foreach ($leave_types as $type):
                        $icon = $leave_icons[$type] ?? 'fa-calendar'; ?>
                    <div class="lr-type-card" data-value="<?php echo $type; ?>" onclick="selectLeaveType(this)">
                        <div class="lr-type-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                        <div class="lr-type-label"><?php echo $type; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="lr_type_err" style="font-size:11.5px;color:var(--rose);margin-top:5px;display:none;">
                    <i class="fas fa-exclamation-circle me-1"></i>Please select a leave type
                </div>
            </div>

            <!-- Date Range -->
            <div class="lr-field">
                <label>
                    <i class="fas fa-calendar-alt" style="color:var(--navy-mid);"></i>
                    Leave Period <span class="req">*</span>
                </label>
                <div class="lr-date-row">
                    <div>
                        <div style="font-size:11px;font-weight:600;color:var(--slate-600);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
                            <i class="fas fa-sign-in-alt" style="color:var(--green);"></i> Start Date
                        </div>
                        <input type="date" class="lr-input" id="start_date" name="start_date"
                               min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div>
                        <div style="font-size:11px;font-weight:600;color:var(--slate-600);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">
                            <i class="fas fa-sign-out-alt" style="color:var(--rose);"></i> End Date
                        </div>
                        <input type="date" class="lr-input" id="end_date" name="end_date"
                               min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="lr-duration-pill" id="duration_display">
                    <i class="fas fa-clock" style="font-size:16px;"></i>
                    <div>
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;opacity:.75;">Duration</div>
                        <div><span class="lr-dur-num" id="duration_num">0</span> <span id="duration_label">day(s)</span></div>
                    </div>
                    <div id="duration_extra" style="font-size:11px;margin-left:auto;opacity:.8;"></div>
                </div>
            </div>

            <!-- Reason -->
            <div class="lr-field">
                <label>
                    <i class="fas fa-comment-alt" style="color:var(--navy-mid);"></i>
                    Reason <span class="req">*</span>
                </label>
                <div class="lr-reason-wrap">
                    <textarea class="lr-input" id="reason" name="reason" rows="5"
                              placeholder="Please provide a clear and detailed reason for your leave request…"
                              minlength="10" oninput="lrCharCount(this)"></textarea>
                    <div class="lr-char-count bad" id="lr_char_count">0 / 10 min</div>
                </div>
            </div>

            <!-- Reminders -->
            <div class="lr-reminder">
                <div class="lr-reminder-title">
                    <i class="fas fa-exclamation-triangle"></i> Important Reminders
                </div>
                <ul>
                    <li>Submit your leave request at least <strong>3 days in advance</strong> when possible</li>
                    <li>For emergency leaves, contact your supervisor immediately</li>
                    <li>Your request will be reviewed by administration</li>
                    <li>You will be notified once your request is processed</li>
                    <li>Ensure all information is accurate before submitting</li>
                </ul>
            </div>

            <!-- Actions -->
            <button type="button" class="lr-submit-btn" id="previewBtn">
                <i class="fas fa-eye"></i> Review &amp; Submit Request
            </button>
            <a href="<?php echo $manage_url; ?>" class="lr-cancel-btn">
                <i class="fas fa-times me-1"></i> Cancel
            </a>

        </form>

        <!-- Guidelines -->
        <div class="lr-guide-grid">
            <div class="lr-guide-col">
                <div class="lr-guide-col-title do">
                    <i class="fas fa-check-circle"></i> Do's
                </div>
                <ul>
                    <li>Plan and submit your leave in advance</li>
                    <li>Provide clear and valid reasons</li>
                    <li>Check your leave balance before requesting</li>
                    <li>Coordinate with your team beforehand</li>
                </ul>
            </div>
            <div class="lr-guide-col">
                <div class="lr-guide-col-title dont">
                    <i class="fas fa-times-circle"></i> Don'ts
                </div>
                <ul>
                    <li>Don't submit last-minute requests (except emergencies)</li>
                    <li>Don't provide vague or incomplete reasons</li>
                    <li>Don't submit overlapping leave requests</li>
                    <li>Don't forget to follow up on pending requests</li>
                </ul>
            </div>
        </div>
    </div>
</div>


<!-- ═══ CONFIRMATION MODAL ═══ -->
<div id="confirmSubmitModal" class="db-modal">
    <div class="db-modal__box" style="max-width:520px;">
        <div class="lr-modal-header">
            <h3><i class="fas fa-check-circle"></i> Confirm Leave Request</h3>
            <button type="button" class="lr-modal-close" onclick="closeModal('confirmSubmitModal')">×</button>
        </div>
        <div class="lr-modal-body">
            <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;font-size:12.5px;color:#1e40af;display:flex;gap:8px;align-items:flex-start;margin-bottom:4px;">
                <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;"></i>
                <span>Please review your leave request details carefully before submitting.</span>
            </div>

            <div class="lr-review-card">
                <div class="lr-review-row">
                    <div class="lr-review-label"><i class="fas fa-tag"></i> Leave Type</div>
                    <div class="lr-review-val" id="modal_leave_type"></div>
                </div>
                <div class="lr-review-row">
                    <div class="lr-review-label"><i class="fas fa-sign-in-alt" style="color:var(--green);"></i> Start Date</div>
                    <div class="lr-review-val" id="modal_start_date"></div>
                </div>
                <div class="lr-review-row">
                    <div class="lr-review-label"><i class="fas fa-sign-out-alt" style="color:var(--rose);"></i> End Date</div>
                    <div class="lr-review-val" id="modal_end_date"></div>
                </div>
                <div class="lr-review-row">
                    <div class="lr-review-label"><i class="fas fa-clock" style="color:var(--sky);"></i> Duration</div>
                    <div class="lr-review-val">
                        <span class="badge-dur" id="modal_duration"></span>
                    </div>
                </div>
                <div class="lr-review-row">
                    <div class="lr-review-label"><i class="fas fa-comment-alt"></i> Reason</div>
                    <div class="lr-review-val" id="modal_reason" style="max-height:120px;overflow-y:auto;"></div>
                </div>
            </div>

            <div class="lr-warn-box">
                <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i>
                <span><strong>Important:</strong> Once submitted, you cannot edit this request. Please ensure all information is correct.</span>
            </div>
        </div>
        <div class="lr-modal-footer">
            <button type="button" class="lr-back-btn" onclick="closeModal('confirmSubmitModal')">
                <i class="fas fa-edit me-1"></i> Go Back to Edit
            </button>
            <button type="button" class="lr-save-btn" id="finalSubmitBtn">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </div>
    </div>
</div>


<script>
/* ── helpers ───────────────────────────────────────────────────── */
function openModal(id)  { var el=document.getElementById(id); if(el){el.style.display='flex'; document.body.style.overflow='hidden';} }
function closeModal(id) { var el=document.getElementById(id); if(el){el.style.display='none'; document.body.style.overflow='';} }

document.querySelectorAll('.db-modal').forEach(function(m){
    m.addEventListener('click', function(e){ if(e.target===m) closeModal(m.id); });
});
document.addEventListener('keydown', function(e){
    if(e.key==='Escape') document.querySelectorAll('.db-modal').forEach(function(m){
        if(m.style.display==='flex') closeModal(m.id);
    });
});

/* ── leave type selection ──────────────────────────────────────── */
function selectLeaveType(card) {
    document.querySelectorAll('.lr-type-card').forEach(function(c){ c.classList.remove('selected'); });
    card.classList.add('selected');
    document.getElementById('leave_type_hidden').value = card.dataset.value;
    document.getElementById('lr_type_err').style.display = 'none';
}

/* ── duration calc ─────────────────────────────────────────────── */
function calculateDuration() {
    var s = document.getElementById('start_date').value;
    var e = document.getElementById('end_date').value;
    var pill = document.getElementById('duration_display');
    if (s && e) {
        var diff = Math.ceil((new Date(e) - new Date(s)) / 86400000) + 1;
        if (diff > 0) {
            document.getElementById('duration_num').textContent = diff;
            document.getElementById('duration_label').textContent = diff === 1 ? 'day' : 'days';
            document.getElementById('duration_extra').textContent = diff > 15 ? 'Long duration — may require additional approval' : '';
            pill.style.display = 'flex';
            pill.className = 'lr-duration-pill' + (diff > 15 ? ' warn' : '');
            return diff;
        }
    }
    pill.style.display = 'none';
    return 0;
}

document.getElementById('start_date').addEventListener('change', function(){
    document.getElementById('end_date').min = this.value;
    calculateDuration();
});
document.getElementById('end_date').addEventListener('change', calculateDuration);

/* ── char counter ──────────────────────────────────────────────── */
function lrCharCount(el) {
    var len = el.value.trim().length;
    var counter = document.getElementById('lr_char_count');
    if (len >= 10) { counter.textContent = len + ' chars ✓'; counter.className = 'lr-char-count ok'; }
    else           { counter.textContent = len + ' / 10 min'; counter.className = 'lr-char-count bad'; }
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}

/* ── date formatter ────────────────────────────────────────────── */
function formatDate(d) {
    return new Date(d + 'T00:00:00').toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'});
}

/* ── preview / confirm ─────────────────────────────────────────── */
document.getElementById('previewBtn').addEventListener('click', function(){
    var leaveType = document.getElementById('leave_type_hidden').value;
    var startDate = document.getElementById('start_date').value;
    var endDate   = document.getElementById('end_date').value;
    var reason    = document.getElementById('reason').value.trim();

    if (!leaveType) {
        document.getElementById('lr_type_err').style.display = 'block';
        document.querySelector('.lr-type-grid').scrollIntoView({behavior:'smooth',block:'center'});
        return;
    }
    if (!startDate) { alert('Please select a start date'); document.getElementById('start_date').focus(); return; }
    if (!endDate)   { alert('Please select an end date');  document.getElementById('end_date').focus();   return; }
    if (new Date(startDate) > new Date(endDate)) { alert('End date must be after or equal to start date'); document.getElementById('end_date').focus(); return; }
    if (!reason || reason.length < 10) { alert('Please provide a more detailed reason (at least 10 characters)'); document.getElementById('reason').focus(); return; }

    var duration = Math.ceil((new Date(endDate) - new Date(startDate)) / 86400000) + 1;
    document.getElementById('modal_leave_type').textContent  = leaveType;
    document.getElementById('modal_start_date').textContent  = formatDate(startDate);
    document.getElementById('modal_end_date').textContent    = formatDate(endDate);
    document.getElementById('modal_duration').textContent    = duration + (duration === 1 ? ' day' : ' days');
    document.getElementById('modal_reason').textContent      = reason;

    openModal('confirmSubmitModal');
});

document.getElementById('finalSubmitBtn').addEventListener('click', function(){
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'submit_leave'; inp.value = '1';
    document.getElementById('leaveRequestForm').appendChild(inp);
    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    document.getElementById('leaveRequestForm').submit();
});

/* ── auto-dismiss alerts ───────────────────────────────────────── */
setTimeout(function(){
    document.querySelectorAll('.db-alert').forEach(function(a){
        a.style.transition = 'opacity .4s'; a.style.opacity = '0';
        setTimeout(function(){ try { a.remove(); } catch(e){} }, 400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>