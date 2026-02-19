<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';

// Require login
requireLogin();

$user_id = getCurrentUserId();
$resident_id = getCurrentResidentId();

// Fetch user verification status
$stmt = $conn->prepare("
    SELECT u.username, r.first_name, r.last_name, r.is_verified, r.id_photo,
           r.email, r.contact_number
    FROM tbl_users u
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

// If already verified, redirect to incidents page
if ($user_data && $user_data['is_verified'] == 1) {
    header("Location: view-incidents.php");
    exit();
}

$page_title = 'Verification Required';
include '../../includes/header.php';
?>

<style>
.verification-container {
    min-height: calc(100vh - 200px);
    display: flex;
    align-items: center;
    justify-content: center;
}

.verification-card {
    max-width: 700px;
    margin: 0 auto;
}

.icon-container {
    width: 120px;
    height: 120px;
    margin: 0 auto 30px;
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: pulse 2s infinite;
}

.icon-container i {
    font-size: 60px;
    color: white;
}

@keyframes pulse {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
    }
    50% {
        transform: scale(1.05);
        box-shadow: 0 0 0 20px rgba(220, 53, 69, 0);
    }
}

.timeline {
    position: relative;
    padding: 20px 0;
}

.timeline-item {
    position: relative;
    padding-left: 60px;
    padding-bottom: 30px;
}

.timeline-item:last-child {
    padding-bottom: 0;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: 20px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item:last-child::before {
    display: none;
}

.timeline-badge {
    position: absolute;
    left: 0;
    top: 0;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    border: 3px solid #dee2e6;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
}

.timeline-item.active .timeline-badge {
    border-color: #dc3545;
    background: #dc3545;
    color: white;
}

.timeline-item.completed .timeline-badge {
    border-color: #198754;
    background: #198754;
    color: white;
}

.feature-box {
    padding: 20px;
    border-radius: 10px;
    background: #f8f9fa;
    margin-bottom: 15px;
    transition: all 0.3s;
}

.feature-box:hover {
    background: #e9ecef;
    transform: translateX(5px);
}

.feature-box i {
    font-size: 24px;
    color: #dc3545;
    margin-right: 15px;
}

.btn-upload-id {
    padding: 15px 40px;
    font-size: 18px;
    border-radius: 50px;
    box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
    transition: all 0.3s;
}

.btn-upload-id:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
}

.info-card {
    background: #ffe5e8;
    border-left: 4px solid #dc3545;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}
</style>

<div class="verification-container">
    <div class="container py-5">
        <div class="verification-card">
            <div class="card border-0 shadow-lg">
                <div class="card-body p-5 text-center">
                    <!-- Icon -->
                    <div class="icon-container">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>

                    <!-- Title -->
                    <h2 class="mb-3">
                        <i class="fas fa-shield-alt text-warning me-2"></i>
                        Verification Required
                    </h2>
                    <p class="text-muted mb-4 fs-5">
                        Hello, <strong><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></strong>
                    </p>

                    <!-- Status Message -->
                    <?php if (empty($user_data['id_photo'])): ?>
                        <div class="alert alert-warning mb-4">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Action Required:</strong> You need to upload your valid government-issued ID to access the incident reporting system.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-4">
                            <i class="fas fa-clock me-2"></i>
                            <strong>Pending Review:</strong> Your ID has been submitted and is currently under review by our administrators.
                        </div>
                    <?php endif; ?>

                    <!-- Why Verification -->
                    <div class="info-card text-start mb-4">
                        <h5 class="mb-3">
                            <i class="fas fa-question-circle text-danger me-2"></i>
                            Why do I need to be verified to report incidents?
                        </h5>
                        <p class="mb-2">
                            Verification is crucial for incident reporting to ensure public safety and proper response. It helps us:
                        </p>
                        <ul class="mb-0">
                            <li>Verify the credibility of incident reports</li>
                            <li>Prevent false alarms and hoax reports</li>
                            <li>Enable proper follow-up and emergency response</li>
                            <li>Coordinate with appropriate authorities effectively</li>
                            <li>Maintain accurate incident records for the barangay</li>
                            <li>Contact reporters for additional information if needed</li>
                        </ul>
                    </div>

                    <!-- Verification Steps -->
                    <div class="text-start mb-4">
                        <h5 class="mb-3">
                            <i class="fas fa-clipboard-list text-danger me-2"></i>
                            Verification Steps
                        </h5>
                        <div class="timeline">
                            <div class="timeline-item <?php echo !empty($user_data['id_photo']) ? 'completed' : 'active'; ?>">
                                <div class="timeline-badge">
                                    <?php if (!empty($user_data['id_photo'])): ?>
                                        <i class="fas fa-check"></i>
                                    <?php else: ?>
                                        <i class="fas fa-1"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Upload Valid ID</h6>
                                    <p class="text-muted small mb-0">
                                        Submit a clear photo of your government-issued ID
                                    </p>
                                </div>
                            </div>

                            <div class="timeline-item <?php echo $user_data['is_verified'] == 1 ? 'completed' : (!empty($user_data['id_photo']) ? 'active' : ''); ?>">
                                <div class="timeline-badge">
                                    <?php if ($user_data['is_verified'] == 1): ?>
                                        <i class="fas fa-check"></i>
                                    <?php else: ?>
                                        <i class="fas fa-2"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Admin Review</h6>
                                    <p class="text-muted small mb-0">
                                        Our team will verify your submitted ID (usually within 24-48 hours)
                                    </p>
                                </div>
                            </div>

                            <div class="timeline-item <?php echo $user_data['is_verified'] == 1 ? 'completed' : ''; ?>">
                                <div class="timeline-badge">
                                    <?php if ($user_data['is_verified'] == 1): ?>
                                        <i class="fas fa-check"></i>
                                    <?php else: ?>
                                        <i class="fas fa-3"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-content">
                                    <h6 class="mb-1">Account Activated</h6>
                                    <p class="text-muted small mb-0">
                                        Once verified, you can report and track incidents
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- What You'll Access -->
                    <div class="text-start mb-4">
                        <h5 class="mb-3">
                            <i class="fas fa-gift text-danger me-2"></i>
                            What you'll get after verification
                        </h5>
                        <div class="feature-box">
                            <i class="fas fa-bullhorn"></i>
                            <strong>Report Incidents</strong>
                            <p class="mb-0 text-muted small">
                                Report crimes, fires, accidents, health emergencies, violations, and disasters
                            </p>
                        </div>
                        <div class="feature-box">
                            <i class="fas fa-map-marked-alt"></i>
                            <strong>Track Incident Status</strong>
                            <p class="mb-0 text-muted small">
                                Monitor response and resolution of your reported incidents
                            </p>
                        </div>
                        <div class="feature-box">
                            <i class="fas fa-history"></i>
                            <strong>View Incident History</strong>
                            <p class="mb-0 text-muted small">
                                Access all your past and current incident reports
                            </p>
                        </div>
                        <div class="feature-box">
                            <i class="fas fa-users"></i>
                            <strong>Emergency Coordination</strong>
                            <p class="mb-0 text-muted small">
                                Receive updates and coordinate with emergency responders
                            </p>
                        </div>
                    </div>

                    <!-- Acceptable IDs -->
                    <div class="text-start mb-4">
                        <h6 class="mb-2">
                            <i class="fas fa-id-card text-danger me-2"></i>
                            Acceptable Government IDs:
                        </h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success me-1"></i> Driver's License
                                </small>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success me-1"></i> National ID / PhilSys
                                </small>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success me-1"></i> Passport
                                </small>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success me-1"></i> SSS / GSIS / UMID ID
                                </small>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success me-1"></i> Postal ID
                                </small>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-check-circle text-success me-1"></i> Voter's ID
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-grid gap-3 mt-4">
                        <?php if (empty($user_data['id_photo'])): ?>
                            <a href="../profile/index.php" class="btn btn-danger btn-upload-id">
                                <i class="fas fa-upload me-2"></i>Upload My ID Now
                            </a>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-upload-id" disabled>
                                <i class="fas fa-clock me-2"></i>Awaiting Verification
                            </button>
                        <?php endif; ?>
                        
                        <a href="../dashboard/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>

                    <!-- Emergency Notice -->
                    <div class="mt-4 pt-4 border-top">
                        <div class="alert alert-danger mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Emergency Situations:</strong> For life-threatening emergencies, call 911 or your local emergency hotline immediately!
                        </div>
                        <p class="text-muted small mb-0">
                            <i class="fas fa-question-circle me-1"></i>
                            Need help? Contact us at 
                            <strong><?php echo htmlspecialchars($user_data['contact_number'] ?? 'barangay office'); ?></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php include '../../includes/footer.php'; ?>