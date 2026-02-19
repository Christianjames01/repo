<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

// Get current user info for the preview
$current_user_id = getCurrentUserId();
$user_info = null;
if ($current_user_id) {
    $stmt = $conn->prepare("
        SELECT u.username, r.first_name, r.last_name, r.profile_photo
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_info = $result->fetch_assoc();
    $stmt->close();
}

$page_title = 'Mobile App Download';
include '../../includes/header.php';
?>

<style>
    :root {
        --primary: #2d3748;
        --secondary: #4a5568;
        --accent: #3182ce;
        --light-bg: #f7fafc;
        --border: #e2e8f0;
    }

    .minimal-hero {
        background: var(--light-bg);
        padding: 40px 0;
        border-bottom: 1px solid var(--border);
        margin-bottom: 40px;
    }
    
    .phone-preview-container {
        position: relative;
        max-width: 360px;
        margin: 0 auto;
    }
    
    .phone-frame {
        width: 360px;
        height: 720px;
        background: #1a1a1a;
        border-radius: 40px;
        padding: 12px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        position: relative;
    }
    
    .phone-notch {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 150px;
        height: 30px;
        background: #1a1a1a;
        border-radius: 0 0 20px 20px;
        z-index: 10;
    }
    
    .phone-screen {
        width: 100%;
        height: 100%;
        background: white;
        border-radius: 32px;
        overflow: hidden;
        position: relative;
    }
    
    /* Mobile App UI */
    .app-statusbar {
        height: 44px;
        background: white;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 20px;
        border-bottom: 1px solid var(--border);
        font-size: 12px;
    }
    
    .app-header {
        height: 60px;
        background: white;
        display: flex;
        align-items: center;
        padding: 0 20px;
        border-bottom: 1px solid var(--border);
    }
    
    .app-header h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
        color: var(--primary);
    }
    
    .app-content {
        height: calc(100% - 164px);
        overflow-y: auto;
        background: var(--light-bg);
    }
    
    .app-bottom-nav {
        height: 60px;
        background: white;
        border-top: 1px solid var(--border);
        display: flex;
        justify-content: space-around;
        align-items: center;
    }
    
    .nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        padding: 8px 16px;
        color: #718096;
        cursor: pointer;
        transition: color 0.2s;
        text-decoration: none;
    }
    
    .nav-item.active {
        color: var(--accent);
    }
    
    .nav-item i {
        font-size: 20px;
    }
    
    .nav-item span {
        font-size: 10px;
    }
    
    /* App Screens */
    .app-screen {
        display: none;
        padding: 20px;
        animation: fadeIn 0.3s ease-in-out;
    }
    
    .app-screen.active {
        display: block;
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .service-card {
        background: white;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        border: 1px solid var(--border);
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .service-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .service-card h3 {
        font-size: 16px;
        margin: 0 0 8px 0;
        color: var(--primary);
    }
    
    .service-card p {
        font-size: 13px;
        color: var(--secondary);
        margin: 0;
    }
    
    .notification-item {
        background: white;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 12px;
        border-left: 4px solid var(--accent);
    }
    
    .notification-item.unread {
        background: #ebf8ff;
    }
    
    .profile-header {
        background: white;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        margin-bottom: 20px;
    }
    
    .profile-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: var(--accent);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        margin: 0 auto 16px;
    }
    
    .menu-item {
        background: white;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
    }
    
    /* Feature Cards */
    .feature-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        height: 100%;
        border: 1px solid var(--border);
        transition: all 0.3s ease;
    }
    
    .feature-card:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }
    
    .feature-icon {
        width: 48px;
        height: 48px;
        background: var(--light-bg);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        color: var(--accent);
        margin-bottom: 16px;
    }
    
    .download-btn {
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        font-size: 14px;
        border: 2px solid transparent;
    }
    
    .btn-primary {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    
    .btn-primary:hover {
        background: var(--secondary);
        color: white;
    }
    
    .btn-outline {
        background: transparent;
        color: var(--primary);
        border-color: var(--border);
    }
    
    .btn-outline:hover {
        border-color: var(--primary);
        background: var(--light-bg);
        color: var(--primary);
    }
    
    .qr-code-container {
        background: white;
        padding: 24px;
        border-radius: 12px;
        text-align: center;
        border: 1px solid var(--border);
    }
    
    .stats-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        border: 1px solid var(--border);
    }
    
    .stats-number {
        font-size: 32px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 8px;
    }
    
    .stats-label {
        color: var(--secondary);
        font-size: 14px;
    }
    
    .coming-soon-badge {
        display: inline-block;
        background: #fef3c7;
        color: #92400e;
        padding: 4px 12px;
        border-radius: 16px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 8px;
    }
    
    .section-title {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 24px;
    }
    
    .system-requirements {
        background: var(--light-bg);
        border-left: 3px solid var(--accent);
        padding: 20px;
        border-radius: 8px;
    }
    
    /* Scrollbar styling for phone preview */
    .app-content::-webkit-scrollbar {
        width: 4px;
    }
    
    .app-content::-webkit-scrollbar-track {
        background: transparent;
    }
    
    .app-content::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 4px;
    }
</style>

<div class="container-fluid px-4">
    <!-- Minimal Hero Section -->
    <div class="minimal-hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <h1 class="display-5 fw-bold mb-3" style="color: var(--primary);">
                        BarangayLink Mobile App
                    </h1>
                    <p class="lead mb-4" style="color: var(--secondary);">
                        Experience seamless barangay services on your mobile device. 
                        Access everything you need, anytime, anywhere.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="#android" class="download-btn btn-primary">
                            <i class="fab fa-google-play"></i>
                            Download for Android
                            <span class="coming-soon-badge">Coming Soon</span>
                        </a>
                        <a href="#ios" class="download-btn btn-outline">
                            <i class="fab fa-apple"></i>
                            Download for iOS
                            <span class="coming-soon-badge">Coming Soon</span>
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <!-- Interactive Phone Preview -->
                    <div class="phone-preview-container">
                        <div class="phone-frame">
                            <div class="phone-notch"></div>
                            <div class="phone-screen">
                                <!-- Status Bar -->
                                <div class="app-statusbar">
                                    <span><?php echo date('g:i A'); ?></span>
                                    <div>
                                        <i class="fas fa-signal"></i>
                                        <i class="fas fa-wifi ms-2"></i>
                                        <i class="fas fa-battery-three-quarters ms-2"></i>
                                    </div>
                                </div>
                                
                                <!-- App Header -->
                                <div class="app-header">
                                    <button id="back-btn" style="display: none; background: none; border: none; color: var(--primary); font-size: 20px; cursor: pointer; padding: 0; margin-right: 12px;" onclick="goBack()">
                                        <i class="fas fa-arrow-left"></i>
                                    </button>
                                    <h1 id="screen-title">Home</h1>
                                </div>
                                
                                <!-- App Content Area -->
                                <div class="app-content">
                                    <!-- Home Screen -->
                                    <div class="app-screen active" id="screen-home">
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin-bottom: 12px; padding: 0 4px;">Quick Access</h4>
                                        <div class="service-card" onclick="showScreen('emergency')">
                                            <h3><i class="fas fa-exclamation-triangle me-2" style="color: #e53e3e;"></i>Emergency SOS</h3>
                                            <p>One-tap emergency alert</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('documents')">
                                            <h3><i class="fas fa-file-alt me-2" style="color: var(--accent);"></i>Document Requests</h3>
                                            <p>Request certificates and clearances</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('incidents')">
                                            <h3><i class="fas fa-exclamation-circle me-2" style="color: #f59e0b;"></i>Report Incident</h3>
                                            <p>Report issues in your area</p>
                                        </div>
                                        
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">All Services</h4>
                                        <div class="service-card" onclick="showScreen('health')">
                                            <h3><i class="fas fa-heartbeat me-2" style="color: #10b981;"></i>Health Services</h3>
                                            <p>Appointments, vaccinations & records</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('education')">
                                            <h3><i class="fas fa-graduation-cap me-2" style="color: #8b5cf6;"></i>Education</h3>
                                            <p>Scholarships & assistance programs</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('business')">
                                            <h3><i class="fas fa-briefcase me-2" style="color: #3b82f6;"></i>Business</h3>
                                            <p>Permits & renewals</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('jobs')">
                                            <h3><i class="fas fa-search me-2" style="color: #06b6d4;"></i>Jobs & Livelihood</h3>
                                            <p>Browse jobs & training programs</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('community')">
                                            <h3><i class="fas fa-users me-2" style="color: #ec4899;"></i>Community</h3>
                                            <p>Forum, events & announcements</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('waste')">
                                            <h3><i class="fas fa-trash-alt me-2" style="color: #84cc16;"></i>Waste Management</h3>
                                            <p>Collection schedule & recycling</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('disaster')">
                                            <h3><i class="fas fa-shield-alt me-2" style="color: #f97316;"></i>Disaster Info</h3>
                                            <p>Alerts & evacuation centers</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Emergency SOS Screen -->
                                    <div class="app-screen" id="screen-emergency">
                                        <div style="text-align: center; padding: 40px 20px;">
                                            <div style="width: 120px; height: 120px; background: #fee2e2; border-radius: 50%; margin: 0 auto 24px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-exclamation-triangle" style="font-size: 60px; color: #e53e3e;"></i>
                                            </div>
                                            <h3 style="color: var(--primary); margin-bottom: 12px;">Emergency SOS</h3>
                                            <p style="color: var(--secondary); margin-bottom: 32px; font-size: 14px;">
                                                This will immediately alert barangay officials and emergency services with your location.
                                            </p>
                                            <button class="btn btn-danger btn-lg w-100 mb-3" style="padding: 16px; font-size: 18px; font-weight: 700;">
                                                <i class="fas fa-phone-alt me-2"></i>SEND EMERGENCY ALERT
                                            </button>
                                            <p style="color: var(--secondary); font-size: 12px;">
                                                Use only in case of real emergencies
                                            </p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-phone me-2"></i>Emergency Hotlines</h3>
                                            <div style="font-size: 13px; color: var(--secondary); margin-top: 12px;">
                                                <div style="margin-bottom: 8px;"><strong>Barangay:</strong> +63 948 797 0726</div>
                                                <div style="margin-bottom: 8px;"><strong>Police:</strong> 911</div>
                                                <div style="margin-bottom: 8px;"><strong>Fire:</strong> 911</div>
                                                <div><strong>Medical:</strong> 911</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Documents Screen -->
                                    <div class="app-screen" id="screen-documents">
                                        <div class="service-card" onclick="showScreen('doc-detail')">
                                            <h3><i class="fas fa-file-certificate me-2"></i>Barangay Clearance</h3>
                                            <p>Processing Time: 3-5 days • ₱50</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('doc-detail')">
                                            <h3><i class="fas fa-id-card me-2"></i>Certificate of Residency</h3>
                                            <p>Processing Time: 2-3 days • ₱30</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('doc-detail')">
                                            <h3><i class="fas fa-file-invoice me-2"></i>Indigency Certificate</h3>
                                            <p>Processing Time: 3-5 days • Free</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('doc-detail')">
                                            <h3><i class="fas fa-home me-2"></i>Certificate of Residency</h3>
                                            <p>Processing Time: 2-3 days • ₱30</p>
                                        </div>
                                        <div class="service-card" onclick="showScreen('doc-detail')">
                                            <h3><i class="fas fa-balance-scale me-2"></i>Good Moral Certificate</h3>
                                            <p>Processing Time: 3-5 days • ₱40</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">My Requests</h4>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h3>Barangay Clearance</h3>
                                                    <p>Requested: Jan 15, 2025</p>
                                                </div>
                                                <span class="badge bg-success">Ready</span>
                                            </div>
                                        </div>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h3>Indigency Certificate</h3>
                                                    <p>Requested: Jan 20, 2025</p>
                                                </div>
                                                <span class="badge bg-warning">Processing</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Incidents Screen -->
                                    <div class="app-screen" id="screen-incidents">
                                        <div class="service-card">
                                            <h3><i class="fas fa-camera me-2"></i>Report New Incident</h3>
                                            <p>Take photo and describe the issue</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">My Reports</h4>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Pothole on Main St</strong>
                                                <span class="badge bg-warning">Pending</span>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Reported: Jan 28, 2025
                                            </p>
                                        </div>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Broken Streetlight</strong>
                                                <span class="badge bg-success">Resolved</span>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Reported: Jan 20, 2025
                                            </p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-clipboard-list me-2"></i>View Blotter Records</h3>
                                            <p>Access your filed complaints</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-comments me-2"></i>File Complaint</h3>
                                            <p>Submit a formal complaint</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Health Services Screen -->
                                    <div class="app-screen" id="screen-health">
                                        <div class="service-card">
                                            <h3><i class="fas fa-calendar-plus me-2"></i>Book Appointment</h3>
                                            <p>Schedule health checkup or consultation</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-syringe me-2"></i>My Vaccinations</h3>
                                            <p>View vaccination records & schedule</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-notes-medical me-2"></i>Health Records</h3>
                                            <p>Access your medical history</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-hand-holding-medical me-2"></i>Request Assistance</h3>
                                            <p>Apply for medical assistance programs</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Upcoming Appointments</h4>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h3>General Checkup</h3>
                                                    <p>Feb 5, 2025 at 10:00 AM</p>
                                                </div>
                                                <span class="badge bg-info">Confirmed</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Education Screen -->
                                    <div class="app-screen" id="screen-education">
                                        <div class="service-card">
                                            <h3><i class="fas fa-file-alt me-2"></i>Apply for Scholarship</h3>
                                            <p>Submit scholarship application</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-graduation-cap me-2"></i>Student Portal</h3>
                                            <p>Access student information</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-hand-holding-usd me-2"></i>Assistance Requests</h3>
                                            <p>View your assistance applications</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-folder-open me-2"></i>My Documents</h3>
                                            <p>School forms and certificates</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-book-open me-2"></i>Scholarship Guide</h3>
                                            <p>Requirements and instructions</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Business Screen -->
                                    <div class="app-screen" id="screen-business">
                                        <div class="service-card">
                                            <h3><i class="fas fa-plus-circle me-2"></i>Apply for Permit</h3>
                                            <p>New business permit application</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-sync-alt me-2"></i>Renew Permit</h3>
                                            <p>Renew existing business permit</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-briefcase me-2"></i>My Business Permits</h3>
                                            <p>View all your business permits</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Active Permits</h4>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h3>Sari-Sari Store</h3>
                                                    <p>Expires: Dec 31, 2025</p>
                                                </div>
                                                <span class="badge bg-success">Active</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Jobs & Livelihood Screen -->
                                    <div class="app-screen" id="screen-jobs">
                                        <div class="service-card">
                                            <h3><i class="fas fa-search me-2"></i>Browse Jobs</h3>
                                            <p>Find available job opportunities</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-clipboard-list me-2"></i>My Applications</h3>
                                            <p>Track your job applications</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-chalkboard-teacher me-2"></i>Skills Training</h3>
                                            <p>Enroll in training programs</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-hands-helping me-2"></i>Livelihood Programs</h3>
                                            <p>Apply for livelihood assistance</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Available Jobs</h4>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Sales Associate</strong>
                                                <span class="badge bg-primary">New</span>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Local Store • Full-time
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Community Screen -->
                                    <div class="app-screen" id="screen-community">
                                        <div class="service-card">
                                            <h3><i class="fas fa-comments me-2"></i>Community Board</h3>
                                            <p>Join discussions and forums</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-calendar-alt me-2"></i>Events Calendar</h3>
                                            <p>View upcoming community events</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-bullhorn me-2"></i>Announcements</h3>
                                            <p>Latest barangay announcements</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-poll me-2"></i>Polls & Surveys</h3>
                                            <p>Participate in community polls</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-users-cog me-2"></i>Barangay Officials</h3>
                                            <p>View officials and contact info</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Upcoming Events</h4>
                                        <div class="service-card">
                                            <h3><i class="fas fa-broom me-2"></i>Community Cleanup</h3>
                                            <p>Saturday, Feb 8, 2025 at 6:00 AM</p>
                                        </div>
                                    </div>
                                    
                                    <!-- Waste Management Screen -->
                                    <div class="app-screen" id="screen-waste">
                                        <div class="service-card">
                                            <h3><i class="fas fa-calendar-week me-2"></i>Collection Schedule</h3>
                                            <p>View garbage collection schedule</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-recycle me-2"></i>Recycling Programs</h3>
                                            <p>Learn about recycling initiatives</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-exclamation-circle me-2"></i>Report Waste Issue</h3>
                                            <p>Report illegal dumping or issues</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">This Week's Schedule</h4>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h3>Biodegradable Waste</h3>
                                                    <p>Monday & Thursday</p>
                                                </div>
                                                <i class="fas fa-leaf" style="color: #84cc16; font-size: 24px;"></i>
                                            </div>
                                        </div>
                                        <div class="service-card">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h3>Non-Biodegradable</h3>
                                                    <p>Tuesday & Friday</p>
                                                </div>
                                                <i class="fas fa-trash" style="color: #6b7280; font-size: 24px;"></i>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Disaster Info Screen -->
                                    <div class="app-screen" id="screen-disaster">
                                        <div class="service-card">
                                            <h3><i class="fas fa-home me-2"></i>Evacuation Centers</h3>
                                            <p>Find nearest evacuation centers</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-hurricane me-2"></i>Typhoon Tracker</h3>
                                            <p>Real-time weather updates</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-hands-helping me-2"></i>Relief Distribution</h3>
                                            <p>Check relief goods availability</p>
                                        </div>
                                        <div class="service-card">
                                            <h3><i class="fas fa-book-medical me-2"></i>Emergency Guide</h3>
                                            <p>Disaster preparedness tips</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Active Alerts</h4>
                                        <div class="notification-item unread">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Weather Advisory</strong>
                                                <small style="color: var(--secondary);">1 hour ago</small>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Heavy rainfall expected tonight. Please prepare and stay safe.
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Notifications Screen -->
                                    <div class="app-screen" id="screen-notifications">
                                        <div class="notification-item unread">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Emergency Alert</strong>
                                                <small style="color: var(--secondary);">2 min ago</small>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Flood warning in Area 3. Please stay alert and follow evacuation procedures.
                                            </p>
                                        </div>
                                        <div class="notification-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Document Ready</strong>
                                                <small style="color: var(--secondary);">1 hour ago</small>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Your Barangay Clearance is ready for pickup at the office.
                                            </p>
                                        </div>
                                        <div class="notification-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Event Reminder</strong>
                                                <small style="color: var(--secondary);">5 hours ago</small>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Community cleanup tomorrow at 6 AM. All residents are welcome!
                                            </p>
                                        </div>
                                        <div class="notification-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Scholarship Update</strong>
                                                <small style="color: var(--secondary);">1 day ago</small>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                Your scholarship application has been approved. Please check your student portal.
                                            </p>
                                        </div>
                                        <div class="notification-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <strong style="color: var(--primary);">Incident Resolved</strong>
                                                <small style="color: var(--secondary);">2 days ago</small>
                                            </div>
                                            <p style="font-size: 13px; margin: 0; color: var(--secondary);">
                                                The broken streetlight you reported has been fixed. Thank you!
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- Profile Screen -->
                                    <div class="app-screen" id="screen-profile">
                                        <div class="profile-header">
                                            <div class="profile-avatar">
                                                <?php if ($user_info && !empty($user_info['first_name'])): ?>
                                                    <?php echo strtoupper(substr($user_info['first_name'], 0, 1) . substr($user_info['last_name'], 0, 1)); ?>
                                                <?php else: ?>
                                                    <i class="fas fa-user"></i>
                                                <?php endif; ?>
                                            </div>
                                            <h3 style="margin: 0; color: var(--primary);">
                                                <?php 
                                                if ($user_info && !empty($user_info['first_name'])) {
                                                    echo htmlspecialchars($user_info['first_name'] . ' ' . $user_info['last_name']);
                                                } else if ($user_info) {
                                                    echo htmlspecialchars($user_info['username']);
                                                } else {
                                                    echo 'Guest User';
                                                }
                                                ?>
                                            </h3>
                                            <p style="font-size: 13px; color: var(--secondary); margin-top: 4px;">Resident - Brgy Centro</p>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Account</h4>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-user me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">Edit Profile</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-qrcode me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">My Digital ID</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-history me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">Request History</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <h4 style="font-size: 14px; font-weight: 600; color: var(--secondary); margin: 20px 0 12px; padding: 0 4px;">Preferences</h4>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-bell me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">Notifications</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-lock me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">Privacy & Security</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-question-circle me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">Help & Support</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <div class="menu-item">
                                            <div>
                                                <i class="fas fa-info-circle me-3" style="color: var(--accent);"></i>
                                                <span style="color: var(--primary);">About</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                        <div class="menu-item" style="margin-top: 20px;">
                                            <div>
                                                <i class="fas fa-sign-out-alt me-3" style="color: #e53e3e;"></i>
                                                <span style="color: #e53e3e; font-weight: 600;">Logout</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="color: var(--secondary);"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Bottom Navigation -->
                                <div class="app-bottom-nav">
                                    <a class="nav-item active" onclick="switchTab('home', this)">
                                        <i class="fas fa-home"></i>
                                        <span>Home</span>
                                    </a>
                                    <a class="nav-item" onclick="switchTab('notifications', this)">
                                        <i class="fas fa-bell"></i>
                                        <span>Alerts</span>
                                    </a>
                                    <a class="nav-item" onclick="switchTab('profile', this)">
                                        <i class="fas fa-user"></i>
                                        <span>Profile</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="row mb-5">
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number">1,200+</div>
                <div class="stats-label">Active Users</div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number">4.8/5</div>
                <div class="stats-label">User Rating</div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number">5,000+</div>
                <div class="stats-label">Downloads</div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stats-card">
                <div class="stats-number">24/7</div>
                <div class="stats-label">Support</div>
            </div>
        </div>
    </div>

    <!-- Features Section -->
    <div class="row mb-5">
        <div class="col-12 mb-4">
            <h2 class="section-title text-center">Key Features</h2>
            <p class="text-center" style="color: var(--secondary);">Everything you need in one powerful app</p>
        </div>

        <div class="col-md-4 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-bolt"></i>
                </div>
                <h5 class="fw-bold mb-3">Real-Time Notifications</h5>
                <p style="color: var(--secondary); font-size: 14px;">
                    Receive instant push notifications for emergencies, announcements, 
                    and updates about your requests.
                </p>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <h5 class="fw-bold mb-3">Document Requests</h5>
                <p style="color: var(--secondary); font-size: 14px;">
                    Request barangay certificates and clearances directly from your phone. 
                    Track status in real-time.
                </p>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h5 class="fw-bold mb-3">Report Incidents</h5>
                <p style="color: var(--secondary); font-size: 14px;">
                    Quickly report incidents with photos and location. 
                    Help keep our community safe.
                </p>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-wifi-slash"></i>
                </div>
                <h5 class="fw-bold mb-3">Offline Mode</h5>
                <p style="color: var(--secondary); font-size: 14px;">
                    Access critical information even without internet. 
                    Data syncs automatically when online.
                </p>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h5 class="fw-bold mb-3">Digital ID & QR Code</h5>
                <p style="color: var(--secondary); font-size: 14px;">
                    Carry your barangay ID digitally with a secure QR code. 
                    No physical cards needed.
                </p>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h5 class="fw-bold mb-3">Emergency SOS</h5>
                <p style="color: var(--secondary); font-size: 14px;">
                    One-tap emergency button to alert officials and services. 
                    Automatically shares your location.
                </p>
            </div>
        </div>
    </div>

    <!-- QR Code Section -->
    <div class="row mb-5">
        <div class="col-12 mb-4">
            <h2 class="section-title text-center">Download the App</h2>
            <p class="text-center" style="color: var(--secondary);">Scan the QR code with your phone camera</p>
        </div>

        <div class="col-md-6 mb-4">
            <div class="qr-code-container">
                <h5 class="fw-bold mb-3">
                    <i class="fab fa-android me-2" style="color: #3DDC84;"></i>
                    Android (Google Play)
                </h5>
                <canvas id="qr-android"></canvas>
                <p style="color: var(--secondary); font-size: 13px; margin-top: 16px;">
                    Requires Android 7.0 or higher
                </p>
            </div>
        </div>

        <div class="col-md-6 mb-4">
            <div class="qr-code-container">
                <h5 class="fw-bold mb-3">
                    <i class="fab fa-apple me-2"></i>
                    iOS (App Store)
                </h5>
                <canvas id="qr-ios"></canvas>
                <p style="color: var(--secondary); font-size: 13px; margin-top: 16px;">
                    Requires iOS 13.0 or higher
                </p>
            </div>
        </div>
    </div>

    <!-- System Requirements -->
    <div class="row mb-5">
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-4">
                        <i class="fab fa-android me-2" style="color: #3DDC84;"></i>
                        Android Requirements
                    </h4>
                    <div class="system-requirements">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>Android 7.0 or higher</li>
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>Minimum 2GB RAM</li>
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>100MB free storage</li>
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>Camera for QR scanning</li>
                            <li class="mb-0"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>Internet connection</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-4">
                        <i class="fab fa-apple me-2"></i>
                        iOS Requirements
                    </h4>
                    <div class="system-requirements">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>iOS 13.0 or later</li>
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>iPhone, iPad, iPod touch</li>
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>100MB free storage</li>
                            <li class="mb-2"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>Camera for QR scanning</li>
                            <li class="mb-0"><i class="fas fa-check-circle me-2" style="color: var(--accent);"></i>Internet connection</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FAQ Section -->
    <div class="row mb-5">
        <div class="col-12 mb-4">
            <h2 class="section-title text-center">Frequently Asked Questions</h2>
        </div>

        <div class="col-lg-8 mx-auto">
            <div class="accordion" id="faqAccordion">
                <div class="accordion-item border-0 mb-3 shadow-sm">
                    <h2 class="accordion-header">
                        <button class="accordion-button fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                            Is the mobile app free?
                        </button>
                    </h2>
                    <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                        <div class="accordion-body" style="color: var(--secondary);">
                            Yes! The BarangayLink mobile app is completely free for all residents of Brgy Centro. 
                            There are no hidden fees or in-app purchases.
                        </div>
                    </div>
                </div>

                <div class="accordion-item border-0 mb-3 shadow-sm">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                            Can I use the app without internet?
                        </button>
                    </h2>
                    <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body" style="color: var(--secondary);">
                            Yes! The app has an offline mode that allows you to access critical information and 
                            prepare reports even without internet. Your data will automatically sync when you're back online.
                        </div>
                    </div>
                </div>

                <div class="accordion-item border-0 mb-3 shadow-sm">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                            How do I receive emergency notifications?
                        </button>
                    </h2>
                    <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body" style="color: var(--secondary);">
                            After installing the app, make sure to enable push notifications in your device settings. 
                            The app will send you real-time alerts for emergencies and important announcements.
                        </div>
                    </div>
                </div>

                <div class="accordion-item border-0 mb-3 shadow-sm">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                            Is my personal information secure?
                        </button>
                    </h2>
                    <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                        <div class="accordion-body" style="color: var(--secondary);">
                            Absolutely! We use industry-standard encryption to protect your data. Your personal 
                            information is stored securely and is only accessible to authorized barangay officials.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Support Section -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="background: var(--primary); color: white;">
                <div class="card-body text-center py-5">
                    <h2 class="fw-bold mb-3">Need Help?</h2>
                    <p class="mb-4">Our support team is here to assist you</p>
                    <div class="row justify-content-center g-4">
                        <div class="col-md-4">
                            <i class="fas fa-phone fa-2x mb-2"></i>
                            <p class="mb-0"><?php echo BARANGAY_CONTACT; ?></p>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-envelope fa-2x mb-2"></i>
                            <p class="mb-0"><?php echo BARANGAY_EMAIL; ?></p>
                        </div>
                        <div class="col-md-4">
                            <i class="fas fa-map-marker-alt fa-2x mb-2"></i>
                            <p class="mb-0">Barangay Centro Office</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include QR Code Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
// Mobile App Preview Functions with Navigation History
let navigationHistory = ['home'];

function switchTab(screenName, element) {
    // Remove active class from all nav items and screens
    document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
    document.querySelectorAll('.app-screen').forEach(screen => screen.classList.remove('active'));
    
    // Add active class to clicked nav item
    element.classList.add('active');
    
    // Show corresponding screen
    document.getElementById('screen-' + screenName).classList.add('active');
    
    // Update header title
    const titles = {
        'home': 'Home',
        'notifications': 'Notifications',
        'profile': 'Profile'
    };
    document.getElementById('screen-title').textContent = titles[screenName];
    
    // Reset navigation history for main tabs
    navigationHistory = [screenName];
    document.getElementById('back-btn').style.display = 'none';
}

function showScreen(screenName) {
    // Hide all screens
    document.querySelectorAll('.app-screen').forEach(screen => screen.classList.remove('active'));
    
    // Show the requested screen
    const targetScreen = document.getElementById('screen-' + screenName);
    if (targetScreen) {
        targetScreen.classList.add('active');
        
        // Update header title
        const titles = {
            'home': 'Home',
            'emergency': 'Emergency SOS',
            'documents': 'Document Requests',
            'incidents': 'Report Incident',
            'health': 'Health Services',
            'education': 'Education',
            'business': 'Business Permits',
            'jobs': 'Jobs & Livelihood',
            'community': 'Community',
            'waste': 'Waste Management',
            'disaster': 'Disaster Info',
            'notifications': 'Notifications',
            'profile': 'Profile',
            'doc-detail': 'Request Document'
        };
        document.getElementById('screen-title').textContent = titles[screenName] || 'BarangayLink';
        
        // Add to navigation history
        navigationHistory.push(screenName);
        
        // Show/hide back button
        const mainScreens = ['home', 'notifications', 'profile'];
        if (mainScreens.includes(screenName)) {
            document.getElementById('back-btn').style.display = 'none';
            navigationHistory = [screenName];
        } else {
            document.getElementById('back-btn').style.display = 'block';
        }
        
        // Don't update bottom nav for sub-screens
        if (mainScreens.includes(screenName)) {
            document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
            const navItem = document.querySelector(`.nav-item[onclick*="'${screenName}'"]`);
            if (navItem) navItem.classList.add('active');
        }
    }
}

function goBack() {
    if (navigationHistory.length > 1) {
        // Remove current screen from history
        navigationHistory.pop();
        
        // Get previous screen
        const previousScreen = navigationHistory[navigationHistory.length - 1];
        
        // Remove the screen we're going back to from history (will be re-added by showScreen)
        navigationHistory.pop();
        
        // Navigate to previous screen
        showScreen(previousScreen);
    }
}

// Add smooth scroll animation
document.addEventListener('DOMContentLoaded', function() {
    // Generate QR Codes
    new QRCode(document.getElementById("qr-android"), {
        text: "https://play.google.com/store/apps/details?id=com.barangaylink.app",
        width: 200,
        height: 200,
        colorDark: "#3DDC84",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
    });

    new QRCode(document.getElementById("qr-ios"), {
        text: "https://apps.apple.com/app/barangaylink/",
        width: 200,
        height: 200,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
    });
    
    // Add click animation to service cards
    document.querySelectorAll('.service-card').forEach(card => {
        card.addEventListener('click', function() {
            this.style.transform = 'scale(0.98)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 100);
        });
    });
    
    // Add smooth scrolling for app content
    const appContent = document.querySelector('.app-content');
    if (appContent) {
        appContent.style.scrollBehavior = 'smooth';
    }
});
</script>

<?php include '../../includes/footer.php'; ?>