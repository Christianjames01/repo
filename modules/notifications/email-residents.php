<?php
/**
 * Email Residents - modules/notifications/email-residents.php
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/phpmailer/mailer.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$user_query = "SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1";
$stmt = $conn->prepare($user_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_result = $stmt->get_result();
$current_user = $user_result->fetch_assoc();
$stmt->close();

$user_role = $current_user['role'] ?? '';

if ($user_role !== 'Super Administrator') {
    $_SESSION['error_message'] = 'Access denied. Super Administrator only.';
    header('Location: ../../dashboard.php');
    exit();
}

$page_title = 'Email Residents';

$stats = fetchOne($conn, 
    "SELECT 
        COUNT(*) as total_residents,
        COUNT(CASE WHEN email IS NOT NULL AND email != '' THEN 1 END) as with_email,
        COUNT(CASE WHEN email IS NULL OR email = '' THEN 1 END) as without_email
     FROM tbl_residents",
    [], ''
);

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-envelope me-2 text-primary"></i>Email Residents
                    </h2>
                    <p class="text-muted mb-0">Send notifications via email to residents</p>
                </div>
                <div>
                    <a href="email-history.php" class="btn btn-info me-2">
                        <i class="fas fa-history me-2"></i>Email History
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Notifications
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm stat-card-modern">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-icon bg-primary-subtle rounded-3 p-3">
                                <i class="fas fa-users fa-2x text-primary"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h3 class="mb-0"><?= $stats['total_residents'] ?></h3>
                            <p class="text-muted mb-0 small">Total Residents</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm stat-card-modern">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-icon bg-success-subtle rounded-3 p-3">
                                <i class="fas fa-envelope-open fa-2x text-success"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h3 class="mb-0"><?= $stats['with_email'] ?></h3>
                            <p class="text-muted mb-0 small">With Email Address</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm stat-card-modern">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-icon bg-warning-subtle rounded-3 p-3">
                                <i class="fas fa-exclamation-circle fa-2x text-warning"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h3 class="mb-0"><?= $stats['without_email'] ?></h3>
                            <p class="text-muted mb-0 small">Without Email</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm modern-form-card">
                <div class="card-header bg-white border-bottom-0">
                    <h5 class="mb-0 fw-semibold">
                        <i class="fas fa-paper-plane me-2 text-primary"></i>Compose Email
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" id="emailForm">
                        <!-- Recipient Selection -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Recipients <span class="text-danger">*</span></label>
                            <select class="form-select" name="recipient_type" id="recipientType" required onchange="toggleRecipientOptions()">
                                <option value="">-- Select Recipients --</option>
                                <option value="all">All Residents with Email</option>
                                <option value="selected">Select Specific Residents</option>
                            </select>
                        </div>

                        <!-- Resident Selection -->
                        <div class="mb-3 d-none" id="residentSelection">
                            <label class="form-label fw-semibold">Select Residents</label>
                            <div class="mb-2">
                                <input type="text" class="form-control" id="residentSearch" 
                                       placeholder="Search by name or email..." onkeyup="filterResidents()">
                            </div>
                            <div class="resident-box" id="residentList">
                                <div class="text-center py-4 text-muted">
                                    <div class="spinner-border spinner-border-sm mb-2" role="status"></div>
                                    <div class="small">Loading...</div>
                                </div>
                            </div>
                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllResidents()">
                                        Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllResidents()">
                                        Deselect All
                                    </button>
                                </div>
                                <span class="text-muted small">
                                    <span id="selectedCount">0</span> resident(s) selected
                                </span>
                            </div>
                        </div>

                        <!-- Email Title -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Subject <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="email_title" 
                                   placeholder="Enter email subject" required maxlength="200">
                        </div>

                        <!-- Notification Type -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Notification Type</label>
                            <select class="form-select" name="notification_type">
                                <option value="general">General Notification</option>
                                <option value="announcement">Announcement</option>
                                <option value="alert">Alert/Warning</option>
                                <option value="incident_reported">Incident Report</option>
                                <option value="status_update">Status Update</option>
                            </select>
                        </div>

                        <!-- Email Message -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="email_message" rows="8" 
                                      placeholder="Enter your message here..." required></textarea>
                            <small class="text-muted">You can use simple HTML formatting if needed</small>
                        </div>

                        <!-- Action Link -->
                        <div class="mb-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="include_link" value="1" 
                                       id="includeLink" onchange="toggleActionUrl()">
                                <label class="form-check-label fw-semibold" for="includeLink">
                                    Include action button/link
                                </label>
                            </div>
                            <div class="d-none" id="actionUrlDiv">
                                <input type="url" class="form-control" name="action_url" 
                                       placeholder="https://example.com/view-details">
                                <small class="text-muted">Optional: Add a link for residents to view more details</small>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid gap-2">
                            <button type="submit" name="send_emails" class="btn btn-primary btn-lg" id="sendEmailBtn">
                                <i class="fas fa-paper-plane me-2"></i>Send Emails
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Preview/Help -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm info-card-modern mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="fas fa-info-circle me-2 text-info"></i>Information
                    </h6>
                    <ul class="info-list-modern">
                        <li>Emails are sent from: <strong><?= MAIL_FROM_EMAIL ?></strong></li>
                        <li>Only residents with valid email addresses will receive notifications</li>
                        <li>Notifications will also be saved in the system</li>
                        <li>There's a small delay between emails to prevent spam detection</li>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm info-card-modern">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3">
                        <i class="fas fa-lightbulb me-2 text-warning"></i>Tips
                    </h6>
                    <ul class="info-list-modern">
                        <li>Keep your subject line clear and concise</li>
                        <li>Use professional language</li>
                        <li>Include all necessary details in the message</li>
                        <li>Test with a small group first if unsure</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Validation Modal -->
<div class="modal fade" id="validationModal" tabindex="-1" aria-labelledby="validationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle" 
                             style="width: 72px; height: 72px;">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2" id="validationModalLabel">Action Required</h5>
                    <p class="text-muted mb-0" id="validationModalMessage">Please complete all required fields.</p>
                </div>
                <div class="px-4 pb-4">
                    <button type="button" class="btn btn-primary w-100 fw-semibold" data-bs-dismiss="modal">
                        <i class="fas fa-check me-2"></i>Got it
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 460px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-body p-0">
                <div class="text-center p-4 pb-3">
                    <div class="mb-3">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle"
                             style="width: 72px; height: 72px;">
                            <i class="fas fa-paper-plane fa-2x text-primary"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2" id="confirmModalLabel">Confirm Send</h5>
                    <p class="text-muted mb-3" id="confirmModalMessage">
                        You are about to send this email to <strong id="confirmRecipientCount">0</strong> resident(s).
                    </p>
                    <div class="alert alert-warning py-2 px-3 text-start small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        This action cannot be undone. All selected recipients will receive the email immediately.
                    </div>
                </div>
                <div class="px-4 pb-4 d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary flex-fill fw-semibold" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary flex-fill fw-semibold" id="confirmSendBtn">
                        <i class="fas fa-paper-plane me-2"></i>Yes, Send Now
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sending Progress Modal -->
<div class="modal fade" id="sendingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-body p-4">
                <!-- Sending State -->
                <div id="sendingState" class="text-center py-4">
                    <div class="mb-4">
                        <div class="spinner-border text-primary" style="width: 4rem; height: 4rem;" role="status">
                            <span class="visually-hidden">Sending...</span>
                        </div>
                    </div>
                    <h4 class="mb-3">Sending Emails...</h4>
                    <p class="text-muted mb-4">Please wait while we send notifications to residents.</p>
                    
                    <div class="progress mb-4" style="height: 30px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" 
                             role="progressbar" style="width: 0%;" id="emailProgress">
                            <span class="fw-bold" id="progressText">0%</span>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-around text-center mb-3">
                        <div>
                            <h5 class="mb-0 text-success" id="sentCount">0</h5>
                            <small class="text-muted">Sent</small>
                        </div>
                        <div>
                            <h5 class="mb-0 text-primary" id="totalCount">0</h5>
                            <small class="text-muted">Total</small>
                        </div>
                        <div>
                            <h5 class="mb-0 text-danger" id="failedCount">0</h5>
                            <small class="text-muted">Failed</small>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mb-0" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>This may take a few moments. Please don't close this window.</small>
                    </div>
                </div>

                <!-- Success State -->
                <div id="successState" class="text-center py-4 d-none">
                    <div class="mb-4">
                        <div class="success-checkmark">
                            <div class="check-icon">
                                <span class="icon-line line-tip"></span>
                                <span class="icon-line line-long"></span>
                                <div class="icon-circle"></div>
                                <div class="icon-fix"></div>
                            </div>
                        </div>
                    </div>
                    <h4 class="text-success mb-3">
                        <i class="fas fa-check-circle me-2"></i>Emails Sent Successfully!
                    </h4>
                    <p class="text-muted mb-4" id="successMessage">
                        All notifications have been sent successfully.
                    </p>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h3 class="text-success mb-1" id="successSentCount">0</h3>
                                    <small class="text-muted">Successfully Sent</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-primary">
                                <div class="card-body text-center">
                                    <h3 class="text-primary mb-1" id="successTotalCount">0</h3>
                                    <small class="text-muted">Total Recipients</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <h3 class="text-danger mb-1" id="successFailedCount">0</h3>
                                    <small class="text-muted">Failed</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-primary btn-lg" onclick="location.reload()">
                        <i class="fas fa-check me-2"></i>Close
                    </button>
                </div>

                <!-- Error State -->
                <div id="errorState" class="text-center py-4 d-none">
                    <div class="mb-4">
                        <div class="error-icon">
                            <i class="fas fa-exclamation-circle fa-5x text-danger"></i>
                        </div>
                    </div>
                    <h4 class="text-danger mb-3">
                        <i class="fas fa-times-circle me-2"></i>Email Sending Failed
                    </h4>
                    <p class="text-muted mb-4" id="errorMessage">
                        There was an error sending the emails. Please check your configuration.
                    </p>
                    
                    <div class="alert alert-danger text-start" id="errorDetails">
                        <h6 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Error Details:</h6>
                        <p class="mb-0" id="errorDetailsText">Unknown error occurred</p>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h3 class="text-success mb-1" id="errorSentCount">0</h3>
                                    <small class="text-muted">Sent Successfully</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <h3 class="text-danger mb-1" id="errorFailedCount">0</h3>
                                    <small class="text-muted">Failed to Send</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                            <i class="fas fa-times me-2"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-primary" onclick="retryEmailSending()">
                            <i class="fas fa-redo me-2"></i>Try Again
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.stat-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-primary-subtle { background-color: rgba(13, 110, 253, 0.1); }
.bg-success-subtle { background-color: rgba(25, 135, 84, 0.1); }
.bg-warning-subtle { background-color: rgba(255, 193, 7, 0.1); }

/* Modern Statistics Cards */
.stat-card-modern {
    transition: all 0.3s ease;
    border-radius: 12px;
}

.stat-card-modern:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12) !important;
}

/* Modern Form Card */
.modern-form-card {
    border-radius: 12px;
}

.modern-form-card .card-header {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #f0f0f0 !important;
}

.form-label {
    font-size: 0.875rem;
    color: #495057;
    margin-bottom: 0.5rem;
}

.form-control,
.form-select {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 0.625rem 0.875rem;
    font-size: 0.9375rem;
    transition: all 0.2s;
}

.form-control:focus,
.form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
}

textarea.form-control {
    resize: vertical;
}

.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

/* Resident Selection Box */
.resident-box {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    max-height: 300px;
    overflow-y: auto;
    background: #fafbfc;
}

.resident-item {
    padding: 0.625rem;
    margin-bottom: 0.25rem;
    border-radius: 6px;
    transition: background 0.2s;
}

.resident-item:hover {
    background: white;
}

.resident-item:last-child {
    margin-bottom: 0;
}

/* Info Cards */
.info-card-modern {
    border-radius: 12px;
}

.info-list-modern {
    list-style: none;
    padding: 0;
    margin: 0;
}

.info-list-modern li {
    padding: 0.625rem 0;
    font-size: 0.875rem;
    color: #6c757d;
    line-height: 1.6;
    border-bottom: 1px solid #f0f0f0;
}

.info-list-modern li:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.info-list-modern li strong {
    color: #212529;
    font-weight: 600;
}

/* Modals */
.modal-content {
    border-radius: 16px;
    border: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}

/* Progress Bar */
.progress {
    border-radius: 10px;
    background-color: #e9ecef;
    overflow: hidden;
}

.progress-bar {
    transition: width 0.5s ease;
    font-size: 0.875rem;
}

/* Success Checkmark Animation */
.success-checkmark {
    width: 80px;
    height: 80px;
    margin: 0 auto;
}

.success-checkmark .check-icon {
    width: 80px;
    height: 80px;
    position: relative;
    border-radius: 50%;
    box-sizing: content-box;
    border: 4px solid #198754;
}

.success-checkmark .check-icon::before {
    top: 3px;
    left: -2px;
    width: 30px;
    transform-origin: 100% 50%;
    border-radius: 100px 0 0 100px;
}

.success-checkmark .check-icon::after {
    top: 0;
    left: 30px;
    width: 60px;
    transform-origin: 0 50%;
    border-radius: 0 100px 100px 0;
    animation: rotate-circle 4.25s ease-in;
}

.success-checkmark .check-icon::before,
.success-checkmark .check-icon::after {
    content: '';
    height: 100px;
    position: absolute;
    background: #fff;
    transform: rotate(-45deg);
}

.success-checkmark .check-icon .icon-line {
    height: 5px;
    background-color: #198754;
    display: block;
    border-radius: 2px;
    position: absolute;
    z-index: 10;
}

.success-checkmark .check-icon .icon-line.line-tip {
    top: 46px;
    left: 14px;
    width: 25px;
    transform: rotate(45deg);
    animation: icon-line-tip 0.75s;
}

.success-checkmark .check-icon .icon-line.line-long {
    top: 38px;
    right: 8px;
    width: 47px;
    transform: rotate(-45deg);
    animation: icon-line-long 0.75s;
}

.success-checkmark .check-icon .icon-circle {
    top: -4px;
    left: -4px;
    z-index: 10;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    position: absolute;
    box-sizing: content-box;
    border: 4px solid rgba(25, 135, 84, .5);
}

.success-checkmark .check-icon .icon-fix {
    top: 8px;
    width: 5px;
    left: 26px;
    z-index: 1;
    height: 85px;
    position: absolute;
    transform: rotate(-45deg);
    background-color: #fff;
}

@keyframes rotate-circle {
    0% { transform: rotate(-45deg); }
    5% { transform: rotate(-45deg); }
    12% { transform: rotate(-405deg); }
    100% { transform: rotate(-405deg); }
}

@keyframes icon-line-tip {
    0% { width: 0; left: 1px; top: 19px; }
    54% { width: 0; left: 1px; top: 19px; }
    70% { width: 50px; left: -8px; top: 37px; }
    84% { width: 17px; left: 21px; top: 48px; }
    100% { width: 25px; left: 14px; top: 45px; }
}

@keyframes icon-line-long {
    0% { width: 0; right: 46px; top: 54px; }
    65% { width: 0; right: 46px; top: 54px; }
    84% { width: 55px; right: 0px; top: 35px; }
    100% { width: 47px; right: 8px; top: 38px; }
}

.error-icon { animation: shake 0.5s; }

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-10px); }
    20%, 40%, 60%, 80% { transform: translateX(10px); }
}

/* Scrollbar */
.resident-box::-webkit-scrollbar {
    width: 6px;
}

.resident-box::-webkit-scrollbar-track {
    background: #f0f0f0;
    border-radius: 3px;
}

.resident-box::-webkit-scrollbar-thumb {
    background: #dee2e6;
    border-radius: 3px;
}

.resident-box::-webkit-scrollbar-thumb:hover {
    background: #ced4da;
}

/* Responsive */
@media (max-width: 768px) {
    .modern-form-card .card-header {
        padding: 1.25rem 1.5rem;
    }
    
    .modern-form-card .card-body {
        padding: 1.5rem !important;
    }
}
</style>

<script>
let residentsLoaded = false;
let allResidentsData = [];
let sendingModalInstance = null;
let pendingFormData = null;
let pendingRecipientCount = 0;

function showValidationModal(message, title = 'Action Required') {
    document.getElementById('validationModalLabel').textContent = title;
    document.getElementById('validationModalMessage').textContent = message;
    const modal = new bootstrap.Modal(document.getElementById('validationModal'));
    modal.show();
}

function showConfirmModal(recipientCount, onConfirm) {
    document.getElementById('confirmRecipientCount').textContent = recipientCount;

    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    const confirmBtn = document.getElementById('confirmSendBtn');

    const freshBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(freshBtn, confirmBtn);

    freshBtn.addEventListener('click', function () {
        modal.hide();
        onConfirm();
    });

    modal.show();
}

function toggleRecipientOptions() {
    const type = document.getElementById('recipientType').value;
    document.getElementById('residentSelection').classList.add('d-none');
    
    if (type === 'selected') {
        document.getElementById('residentSelection').classList.remove('d-none');
        if (!residentsLoaded) loadResidents();
    }
}

function loadResidents() {
    const listDiv = document.getElementById('residentList');
    listDiv.innerHTML = `
        <div class="text-center py-3">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2 mb-0">Loading residents...</p>
        </div>
    `;
    
    fetch('get-residents-ajax.php')
        .then(response => {
            if (!response.ok) throw new Error('Server error: ' + response.status);
            return response.json();
        })
        .then(response => {
            if (response.error) throw new Error(response.message || 'Unknown error');
            const data = response.data || [];
            allResidentsData = data;
            displayResidents(data);
            residentsLoaded = true;
        })
        .catch(error => {
            listDiv.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Failed to load residents: ${error.message}
                    <br><small>Please refresh the page or contact administrator.</small>
                </div>
            `;
        });
}

function displayResidents(residents) {
    const listDiv = document.getElementById('residentList');
    if (residents.length === 0) {
        listDiv.innerHTML = `
            <div class="alert alert-warning mb-0">
                <i class="fas fa-info-circle me-2"></i>No residents found with email addresses.
            </div>
        `;
        return;
    }
    
    let html = '';
    residents.forEach(resident => {
        html += `
            <div class="form-check resident-item">
                <input class="form-check-input resident-checkbox" type="checkbox" 
                       name="selected_residents[]" value="${resident.id}" 
                       id="res${resident.id}"
                       data-name="${resident.name.toLowerCase()}"
                       data-email="${resident.email.toLowerCase()}">
                <label class="form-check-label" for="res${resident.id}">
                    ${escapeHtml(resident.name)} 
                    <small class="text-muted">(${escapeHtml(resident.email)})</small>
                </label>
            </div>
        `;
    });
    listDiv.innerHTML = html;
    document.querySelectorAll('.resident-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
}

function filterResidents() {
    const searchTerm = document.getElementById('residentSearch').value.toLowerCase();
    const items = document.querySelectorAll('.resident-item');
    let visibleCount = 0;
    
    items.forEach(item => {
        const checkbox = item.querySelector('.resident-checkbox');
        const name = checkbox.dataset.name;
        const email = checkbox.dataset.email;
        if (name.includes(searchTerm) || email.includes(searchTerm)) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    const noResults = document.querySelector('.no-results');
    if (visibleCount === 0 && searchTerm !== '') {
        if (!noResults) {
            const div = document.createElement('div');
            div.className = 'alert alert-info no-results';
            div.innerHTML = '<i class="fas fa-search me-2"></i>No residents match your search.';
            document.getElementById('residentList').insertBefore(div, document.getElementById('residentList').firstChild);
        }
    } else if (noResults) {
        noResults.remove();
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toggleActionUrl() {
    const checkbox = document.getElementById('includeLink');
    document.getElementById('actionUrlDiv').classList.toggle('d-none', !checkbox.checked);
}

function selectAllResidents() {
    document.querySelectorAll('.resident-checkbox').forEach(cb => {
        if (cb.closest('.resident-item').style.display !== 'none') cb.checked = true;
    });
    updateSelectedCount();
}

function deselectAllResidents() {
    document.querySelectorAll('.resident-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.resident-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

function showSendingModal(recipientCount) {
    sendingModalInstance = new bootstrap.Modal(document.getElementById('sendingModal'));
    
    document.getElementById('sendingState').classList.remove('d-none');
    document.getElementById('successState').classList.add('d-none');
    document.getElementById('errorState').classList.add('d-none');
    
    document.getElementById('totalCount').textContent = recipientCount;
    document.getElementById('sentCount').textContent = 0;
    document.getElementById('failedCount').textContent = 0;
    document.getElementById('emailProgress').style.width = '0%';
    document.getElementById('progressText').textContent = '0%';
    
    sendingModalInstance.show();
    
    let progress = 0;
    const interval = setInterval(() => {
        progress += Math.random() * 15;
        if (progress > 90) progress = 90;
        document.getElementById('emailProgress').style.width = progress + '%';
        document.getElementById('progressText').textContent = Math.round(progress) + '%';
        document.getElementById('sentCount').textContent = Math.floor((progress / 100) * recipientCount);
        if (progress >= 90) clearInterval(interval);
    }, 500);
}

function doSendEmails(formData, recipientCount) {
    showSendingModal(recipientCount);

    document.getElementById('sendEmailBtn').disabled = true;
    document.getElementById('sendEmailBtn').innerHTML = 
        '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

    fetch('send-email-ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('sendingState').classList.add('d-none');

        if (data.success) {
            document.getElementById('successState').classList.remove('d-none');
            document.getElementById('successSentCount').textContent   = data.sent   || 0;
            document.getElementById('successTotalCount').textContent  = data.total  || 0;
            document.getElementById('successFailedCount').textContent = data.failed || 0;
            document.getElementById('successMessage').textContent = data.failed > 0
                ? `Successfully sent ${data.sent} email(s). ${data.failed} failed to send.`
                : `All ${data.sent} notification(s) have been sent successfully!`;
        } else {
            document.getElementById('errorState').classList.remove('d-none');
            document.getElementById('errorMessage').textContent     = data.message || 'Unknown error occurred';
            document.getElementById('errorDetailsText').textContent = data.message || 'Unknown error occurred';
            document.getElementById('errorSentCount').textContent   = data.sent   || 0;
            document.getElementById('errorFailedCount').textContent = data.failed || data.total || 0;
        }

        document.getElementById('sendEmailBtn').disabled = false;
        document.getElementById('sendEmailBtn').innerHTML = 
            '<i class="fas fa-paper-plane me-2"></i>Send Emails';
    })
    .catch(error => {
        document.getElementById('sendingState').classList.add('d-none');
        document.getElementById('errorState').classList.remove('d-none');
        document.getElementById('errorMessage').textContent     = 'Network error occurred. Please try again.';
        document.getElementById('errorDetailsText').textContent = error.message || 'Unknown network error';
        document.getElementById('errorSentCount').textContent   = 0;
        document.getElementById('errorFailedCount').textContent = recipientCount;

        document.getElementById('sendEmailBtn').disabled = false;
        document.getElementById('sendEmailBtn').innerHTML = 
            '<i class="fas fa-paper-plane me-2"></i>Send Emails';
    });
}

function retryEmailSending() {
    location.reload();
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('emailForm').addEventListener('submit', function (e) {
        e.preventDefault();

        const recipientType = document.getElementById('recipientType').value;

        if (!recipientType) {
            showValidationModal('Please select a recipient type before sending.', 'Select Recipients');
            return;
        }

        let recipientCount = 0;

        if (recipientType === 'all') {
            recipientCount = <?= $stats['with_email'] ?>;

        } else if (recipientType === 'selected') {
            recipientCount = document.querySelectorAll('.resident-checkbox:checked').length;
            if (recipientCount === 0) {
                showValidationModal('Please select at least one resident before sending.', 'No Residents Selected');
                return;
            }
        }

        const formData = new FormData(this);

        showConfirmModal(recipientCount, function () {
            doSendEmails(formData, recipientCount);
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>