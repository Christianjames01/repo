<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();

$user_id = getCurrentUserId();

// Check verification status FIRST
$stmt = $conn->prepare("
    SELECT r.is_verified, r.id_photo, r.first_name, r.last_name, r.resident_id
    FROM tbl_users u
    JOIN tbl_residents r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

// If not verified, redirect to health verification page
if (!$user_data || $user_data['is_verified'] != 1) {
    header("Location: health-verification.php");
    exit();
}

// Continue with normal page - user is verified
$page_title = 'My Vaccinations';
$resident_id = $user_data['resident_id'];

// Get vaccination records
$stmt = $conn->prepare("SELECT * FROM tbl_vaccination_records WHERE resident_id = ? ORDER BY vaccination_date DESC");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$vaccinations = $stmt->get_result();
$stmt->close();

// Get vaccination summary
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_vaccinations,
        COUNT(DISTINCT vaccine_type) as vaccine_types,
        SUM(CASE WHEN dose_number >= total_doses THEN 1 ELSE 0 END) as complete_series,
        MAX(vaccination_date) as last_vaccination
    FROM tbl_vaccination_records 
    WHERE resident_id = ?
");
$stmt->bind_param("i", $resident_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

include '../../includes/header.php';
?>

<style>
/* Enhanced Modern Styles - EXACT MATCH from view-incidents.php */
:root {
    --transition-speed: 0.3s;
    --shadow-sm: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.12);
    --shadow-lg: 0 8px 24px rgba(0,0,0,0.15);
    --border-radius: 12px;
    --border-radius-lg: 16px;
}

/* Card Enhancements - EXACT match */
.card {
    border: none;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-sm);
    transition: all var(--transition-speed) ease;
    overflow: hidden;
}

.card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}

.card-body {
    padding: 1.75rem;
}

/* Statistics Cards */
.stat-card {
    transition: all var(--transition-speed) ease;
    cursor: pointer;
    background: white;
    border-radius: var(--border-radius);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
    line-height: 1.2;
}

.stat-label {
    font-size: 0.875rem;
    color: #718096;
    font-weight: 500;
    margin-top: 0.25rem;
}

/* Enhanced Badges */
.badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 50px;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.badge-success {
    background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
    color: white;
}

.badge-warning {
    background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
    color: white;
}

/* Vaccination Cards */
.vaccination-card {
    background: white;
    border-radius: var(--border-radius);
    padding: 1.75rem;
    box-shadow: var(--shadow-sm);
    border-left: 4px solid #4299e1;
    margin-bottom: 1.5rem;
    transition: all var(--transition-speed) ease;
}

.vaccination-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.vaccination-card.complete {
    border-left-color: #48bb78;
}

.vaccination-card.incomplete {
    border-left-color: #ed8936;
}

.vaccination-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 1rem;
}

.vaccination-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2d3748;
    margin-bottom: 0.5rem;
}

.vaccination-type {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: #bee3f8;
    color: #2c5282;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.vaccination-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    padding-top: 1rem;
    border-top: 2px solid #e9ecef;
}

.vaccination-detail-item {
    display: flex;
    flex-direction: column;
}

.vaccination-detail-item label {
    font-size: 0.75rem;
    color: #718096;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.vaccination-detail-item span {
    font-size: 0.95rem;
    color: #2d3748;
    font-weight: 500;
}

/* Next Dose Alert */
.next-dose-alert {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    margin-top: 1rem;
    border-radius: 8px;
    border-left: 4px solid;
}

.next-dose-alert.overdue {
    background: linear-gradient(135deg, #fff5f5 0%, #ffe5e5 100%);
    border-left-color: #f56565;
}

.next-dose-alert.soon {
    background: linear-gradient(135deg, #fffaf0 0%, #fff8e1 100%);
    border-left-color: #ed8936;
}

.next-dose-alert.scheduled {
    background: linear-gradient(135deg, #f0f9ff 0%, #e7f1ff 100%);
    border-left-color: #4299e1;
}

.alert-icon {
    font-size: 1.5rem;
}

.next-dose-alert.overdue .alert-icon {
    color: #f56565;
}

.next-dose-alert.soon .alert-icon {
    color: #ed8936;
}

.next-dose-alert.scheduled .alert-icon {
    color: #4299e1;
}

.alert-content {
    flex: 1;
}

.alert-content strong {
    display: block;
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
    font-weight: 700;
}

.next-dose-alert.overdue strong {
    color: #c53030;
}

.next-dose-alert.soon strong {
    color: #c05621;
}

.next-dose-alert.scheduled strong {
    color: #2c5282;
}

.next-dose-date {
    font-size: 1rem;
    font-weight: 600;
    color: #2d3748;
}

.days-info {
    font-size: 0.875rem;
    font-weight: 400;
    color: #718096;
    margin-left: 0.5rem;
}

/* Dose Progress */
.dose-progress {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 1rem;
}

.dose-progress-bar {
    flex: 1;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}

.dose-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #48bb78, #38a169);
    transition: width 0.3s ease;
}

.dose-progress-text {
    font-size: 0.875rem;
    font-weight: 600;
    color: #2d3748;
    min-width: 45px;
    text-align: right;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.3;
}

.empty-state h3 {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 1rem;
}

.empty-state p {
    font-size: 1.1rem;
    margin-bottom: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .stat-card {
        flex-direction: column;
        text-align: center;
    }
}

/* Smooth Scrolling */
html {
    scroll-behavior: smooth;
}
</style>

<div class="container-fluid py-4">
    <!-- Header - EXACT MATCH from view-incidents.php -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0 fw-bold">
                        <i class="fas fa-syringe me-2"></i>
                        My Vaccination Records
                    </h2>
                    <p class="text-muted mb-0 mt-1">Track your immunization history</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #4299e1;">
                    <i class="fas fa-syringe"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $summary['total_vaccinations'] ?: 0; ?></div>
                    <div class="stat-label">Total Vaccinations</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #48bb78;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $summary['complete_series'] ?: 0; ?></div>
                    <div class="stat-label">Complete Series</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #9f7aea;">
                    <i class="fas fa-shield-virus"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo $summary['vaccine_types'] ?: 0; ?></div>
                    <div class="stat-label">Vaccine Types</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm stat-card">
                <div class="stat-icon" style="background: #ed8936;">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-value">
                        <?php echo $summary['last_vaccination'] ? date('M d, Y', strtotime($summary['last_vaccination'])) : 'N/A'; ?>
                    </div>
                    <div class="stat-label">Last Vaccination</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Vaccination Records -->
    <?php if ($vaccinations->num_rows > 0): ?>
        <?php while ($vax = $vaccinations->fetch_assoc()): 
            $is_complete = $vax['dose_number'] >= $vax['total_doses'];
            $progress_percent = ($vax['dose_number'] / $vax['total_doses']) * 100;
        ?>
            <div class="vaccination-card <?php echo $is_complete ? 'complete' : 'incomplete'; ?>">
                <div class="vaccination-header">
                    <div>
                        <div class="vaccination-name"><?php echo htmlspecialchars($vax['vaccine_name']); ?></div>
                        <span class="vaccination-type"><?php echo htmlspecialchars($vax['vaccine_type']); ?></span>
                    </div>
                    <div>
                        <?php if ($is_complete): ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check-circle me-1"></i> Complete
                            </span>
                        <?php else: ?>
                            <span class="badge badge-warning">
                                <i class="fas fa-clock me-1"></i> Incomplete
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="vaccination-details">
                    <div class="vaccination-detail-item">
                        <label>Vaccination Date</label>
                        <span><?php echo date('F j, Y', strtotime($vax['vaccination_date'])); ?></span>
                    </div>
                    
                    <div class="vaccination-detail-item">
                        <label>Dose Number</label>
                        <span>Dose <?php echo $vax['dose_number']; ?> of <?php echo $vax['total_doses']; ?></span>
                    </div>
                    
                    <?php if (!empty($vax['vaccine_brand'])): ?>
                    <div class="vaccination-detail-item">
                        <label>Brand</label>
                        <span><?php echo htmlspecialchars($vax['vaccine_brand']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($vax['batch_number'])): ?>
                    <div class="vaccination-detail-item">
                        <label>Batch Number</label>
                        <span><?php echo htmlspecialchars($vax['batch_number']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($vax['administered_by'])): ?>
                    <div class="vaccination-detail-item">
                        <label>Administered By</label>
                        <span><?php echo htmlspecialchars($vax['administered_by']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($vax['vaccination_site'])): ?>
                    <div class="vaccination-detail-item">
                        <label>Vaccination Site</label>
                        <span><?php echo htmlspecialchars($vax['vaccination_site']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Next Dose Alert -->
                <?php if (!empty($vax['next_dose_date'])): ?>
                    <?php 
                    $next_date = strtotime($vax['next_dose_date']);
                    $today = strtotime(date('Y-m-d'));
                    $days_until = floor(($next_date - $today) / (60 * 60 * 24));
                    $is_overdue = $days_until < 0;
                    $is_soon = $days_until >= 0 && $days_until <= 7;
                    ?>
                    <div class="next-dose-alert <?php echo $is_overdue ? 'overdue' : ($is_soon ? 'soon' : 'scheduled'); ?>">
                        <div class="alert-icon">
                            <i class="fas fa-<?php echo $is_overdue ? 'exclamation-triangle' : ($is_soon ? 'clock' : 'calendar-check'); ?>"></i>
                        </div>
                        <div class="alert-content">
                            <strong>
                                <?php if ($is_overdue): ?>
                                    Next Dose Overdue!
                                <?php elseif ($is_soon): ?>
                                    Next Dose Coming Soon
                                <?php else: ?>
                                    Next Dose Scheduled
                                <?php endif; ?>
                            </strong>
                            <div class="next-dose-date">
                                <?php echo date('F j, Y', $next_date); ?>
                                <?php if ($is_overdue): ?>
                                    <span class="days-info">(<?php echo abs($days_until); ?> days overdue)</span>
                                <?php elseif ($is_soon): ?>
                                    <span class="days-info">(in <?php echo $days_until; ?> days)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Dose Progress Bar -->
                <div class="dose-progress">
                    <div class="dose-progress-bar">
                        <div class="dose-progress-fill" style="width: <?php echo $progress_percent; ?>%;"></div>
                    </div>
                    <span class="dose-progress-text"><?php echo round($progress_percent); ?>%</span>
                </div>
                
                <?php if (!empty($vax['side_effects'])): ?>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 2px solid #e9ecef;">
                    <strong style="font-size: 0.875rem; color: #718096;">Side Effects:</strong>
                    <p style="margin: 0.5rem 0 0 0; color: #4a5568; font-size: 0.875rem;">
                        <?php echo nl2br(htmlspecialchars($vax['side_effects'])); ?>
                    </p>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vax['remarks'])): ?>
                <div style="margin-top: 0.75rem;">
                    <strong style="font-size: 0.875rem; color: #718096;">Remarks:</strong>
                    <p style="margin: 0.5rem 0 0 0; color: #4a5568; font-size: 0.875rem;">
                        <?php echo nl2br(htmlspecialchars($vax['remarks'])); ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-syringe"></i>
                    <h3>No Vaccination Records</h3>
                    <p>You don't have any vaccination records yet. Visit the Barangay Health Center to get vaccinated.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>