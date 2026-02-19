<?php
// Include database connection
require_once('../../config/database.php');

// Fetch latest announcements
$announcements = [];
$sql = "SELECT * FROM tbl_announcements WHERE is_active=1 ORDER BY created_at DESC LIMIT 3";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BarangayLink - Serving Brgy Centro Digitally</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background-color: #f8fafc;
        }

        /* ===========================
           NAVIGATION BAR (matching brgy-info.php)
        =========================== */
        .navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 0;
            box-shadow: 0 4px 20px rgba(30, 58, 138, 0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 0;
            transition: transform 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .logo img {
            width: 50px;
            height: 50px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }

        .logo-text h1 {
            font-size: 26px;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .logo-text p {
            font-size: 13px;
            opacity: 0.95;
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 5px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 25px 20px;
            display: block;
            transition: all 0.3s ease;
            font-weight: 500;
            position: relative;
            overflow: hidden;
        }

        .nav-links a::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 3px;
            background: #fbbf24;
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }

        .nav-links a:hover::before,
        .nav-links a.active::before {
            width: 80%;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background-color: rgba(255,255,255,0.15);
        }

        .menu-toggle {
            display: none;
            font-size: 28px;
            cursor: pointer;
            padding: 10px;
            transition: transform 0.3s ease;
        }

        .menu-toggle:hover {
            transform: rotate(90deg);
        }

        /* ===========================
           PAGE HEADER (matching brgy-info.php)
        =========================== */
        .page-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 50%, #60a5fa 100%);
            color: white;
            padding: 80px 20px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBackground 20s linear infinite;
        }

        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        .page-header-content {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .page-header h2 {
            font-size: 48px;
            margin-bottom: 16px;
            font-weight: 800;
            text-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .page-header p {
            font-size: 20px;
            opacity: 0.95;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .breadcrumb {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            font-size: 14px;
        }

        .breadcrumb a {
            color: white;
            text-decoration: none;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        .breadcrumb a:hover {
            opacity: 1;
        }

        /* ===========================
           QUICK SERVICES
        =========================== */
        .quick-services {
            padding: 80px 20px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }

        .section-title {
            text-align: center;
            margin-bottom: 60px;
            animation: fadeIn 1s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .section-title h3 {
            font-size: 40px;
            margin-bottom: 12px;
            color: #3b82f6;
            font-weight: 800;
            position: relative;
            display: inline-block;
        }

        .section-title h3::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: linear-gradient(90deg, #fbbf24, #f59e0b);
            border-radius: 2px;
        }

        .section-title p {
            color: #64748b;
            font-size: 18px;
            margin-top: 16px;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .service-card {
            background: white;
            border-radius: 16px;
            padding: 40px 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            color: inherit;
            display: block;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 2px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }

        .service-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(30, 58, 138, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .service-card:hover::before {
            left: 100%;
        }

        .service-card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 20px 40px rgba(30, 58, 138, 0.2);
            border-color: #1e3a8a;
        }

        .service-card i {
            font-size: 56px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            display: inline-block;
            transition: transform 0.3s ease;
        }

        .service-card:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .service-card h4 {
            font-size: 22px;
            margin-bottom: 12px;
            color: #0f172a;
            font-weight: 700;
        }

        .service-card p {
            color: #64748b;
            font-size: 15px;
            line-height: 1.6;
        }

        /* ===========================
           ANNOUNCEMENTS
        =========================== */
        .announcements {
            padding: 80px 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #dbeafe 100%);
        }

        .announcement-list {
            display: grid;
            gap: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .announcement-item {
            background: white;
            padding: 32px;
            border-radius: 16px;
            border-left: 6px solid #3b82f6;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.4s ease;
        }

        .announcement-item:hover {
            transform: translateX(8px);
            box-shadow: 0 12px 24px rgba(30, 58, 138, 0.15);
            border-left-width: 8px;
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .announcement-header h4 {
            color: #0f172a;
            font-size: 24px;
            font-weight: 700;
        }

        .announcement-date {
            color: #64748b;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .announcement-item p {
            color: #475569;
            line-height: 1.8;
            font-size: 16px;
        }

        .view-all-btn {
            text-align: center;
            margin-top: 48px;
        }

        .view-all-btn a {
            display: inline-block;
            padding: 16px 40px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }

        .view-all-btn a:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.4);
        }

        /* ===========================
           BARANGAY INFO CARDS
        =========================== */
        .barangay-info {
            padding: 80px 20px;
            background: white;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 32px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .info-card {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            padding: 36px;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            transition: all 0.4s ease;
        }

        .info-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 32px rgba(30, 58, 138, 0.15);
            border-color: #1e3a8a;
        }

        .info-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
        }

        .info-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 30px;
        }

        .info-card h4 {
            color: #0f172a;
            font-size: 22px;
            font-weight: 700;
        }

        .info-item {
            display: flex;
            align-items: start;
            gap: 14px;
            margin-bottom: 16px;
            padding: 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }

        .info-item i {
            color: #1e3a8a;
            margin-top: 4px;
            min-width: 20px;
            font-size: 18px;
        }

        .info-item strong {
            color: #0f172a;
            display: block;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .info-item a {
            color: #1e3a8a;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .info-item a:hover {
            color: #3b82f6;
            text-decoration: underline;
        }

        /* ===========================
           EMERGENCY CONTACT
        =========================== */
        .emergency-contact {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            padding: 60px 20px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .emergency-contact::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            animation: pulse 3s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.3; }
            50% { transform: translate(-50%, -50%) scale(1.5); opacity: 0; }
        }

        .emergency-content {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .emergency-content h3 {
            font-size: 32px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            font-weight: 800;
        }

        .emergency-numbers {
            display: flex;
            justify-content: center;
            gap: 48px;
            flex-wrap: wrap;
        }

        .emergency-number {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 700;
            padding: 16px 28px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .emergency-number:hover {
            background: rgba(255,255,255,0.25);
            transform: scale(1.05);
        }

        .emergency-number i { font-size: 28px; }

        .emergency-number a {
            color: white;
            text-decoration: none;
        }

        /* ===========================
           FOOTER
        =========================== */
        footer {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 48px 20px;
            text-align: center;
        }

        footer p {
            opacity: 0.9;
            margin-bottom: 8px;
            font-size: 15px;
        }

        /* ===========================
           SCROLL TO TOP
        =========================== */
        .scroll-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.4);
            z-index: 999;
        }

        .scroll-top.show {
            opacity: 1;
            visibility: visible;
        }

        .scroll-top:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.5);
        }

        /* ===========================
           RESPONSIVE
        =========================== */
        @media (max-width: 768px) {
            .menu-toggle { display: block; }

            .nav-links {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                flex-direction: column;
                gap: 0;
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.4s ease;
                box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            }

            .nav-links.active { max-height: 500px; }

            .nav-links a {
                padding: 18px 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }

            .page-header h2 { font-size: 36px; }
            .page-header p { font-size: 18px; }

            .section-title h3 { font-size: 32px; }

            .services-grid { grid-template-columns: 1fr; }

            .info-grid { grid-template-columns: 1fr; }

            .emergency-numbers {
                flex-direction: column;
                gap: 24px;
            }
        }
    </style>
</head>
<body>

    <!-- ===========================
         NAVIGATION BAR
    =========================== -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <img src="../../assets/images/logos.png" alt="Barangay Logo">
                <div class="logo-text">
                    <h1>BarangayLink</h1>
                    <p>Brgy Centro</p>
                </div>
            </a>
            <div class="menu-toggle" onclick="toggleMenu()">
                <i class="fas fa-bars"></i>
            </div>
            <ul class="nav-links" id="navLinks">
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="brgy-info.php">Barangay Information</a></li>
                <li><a href="services.php">Services</a></li>
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="contacts.php">Contact</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- ===========================
         PAGE HEADER (matching brgy-info.php style)
    =========================== -->
    <section class="page-header">
        <div class="page-header-content">
            <h2>Welcome to BarangayLink</h2>
            <p>Experience efficient, transparent, and easy access to barangay services</p>
            <div class="breadcrumb">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <span>/</span>
                <span>Brgy Centro</span>
            </div>
        </div>
    </section>

    <!-- ===========================
         QUICK SERVICES SECTION
    =========================== -->
    <section class="quick-services">
        <div class="section-title">
            <h3>Quick Services</h3>
            <p>Access essential barangay services with just a few clicks</p>
        </div>
        <div class="services-grid">
            <a href="services.php" class="service-card">
                <i class="fas fa-file-certificate"></i>
                <h4>Barangay Clearance</h4>
                <p>Request and process your barangay clearance online</p>
            </a>
            <a href="services.php" class="service-card">
                <i class="fas fa-home"></i>
                <h4>Certificate of Residency</h4>
                <p>Apply for your certificate of residency digitally</p>
            </a>
            <a href="services.php" class="service-card">
                <i class="fas fa-clipboard-list"></i>
                <h4>Blotter Report</h4>
                <p>File incident reports and complaints securely</p>
            </a>
            <a href="services.php" class="service-card">
                <i class="fas fa-calendar-check"></i>
                <h4>Appointment Scheduling</h4>
                <p>Schedule your visits and appointments in advance</p>
            </a>
        </div>
    </section>

    <!-- ===========================
         ANNOUNCEMENTS SECTION
    =========================== -->
    <section class="announcements">
        <div class="section-title">
            <h3>Latest Announcements</h3>
            <p>Stay updated with the latest news and community updates</p>
        </div>
        <div class="announcement-list">
            <?php if (!empty($announcements)): ?>
                <?php foreach ($announcements as $announcement): ?>
                    <div class="announcement-item">
                        <div class="announcement-header">
                            <h4><?php echo htmlspecialchars($announcement['title']); ?></h4>
                            <span class="announcement-date">
                                <i class="far fa-calendar"></i>
                                <?php echo date('F j, Y', strtotime($announcement['created_at'])); ?>
                            </span>
                        </div>
                        <p><?php echo htmlspecialchars($announcement['content']); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="announcement-item">
                    <div class="announcement-header">
                        <h4>Welcome to BarangayLink</h4>
                        <span class="announcement-date"><i class="far fa-calendar"></i> February 13, 2026</span>
                    </div>
                    <p>We are pleased to announce the launch of our new digital barangay management system. This platform will make accessing barangay services more convenient for all residents.</p>
                </div>
                <div class="announcement-item">
                    <div class="announcement-header">
                        <h4>Community Clean-Up Drive</h4>
                        <span class="announcement-date"><i class="far fa-calendar"></i> January 25, 2026</span>
                    </div>
                    <p>Join us this Saturday at 6:00 AM for our monthly community clean-up drive. Let's work together to keep our barangay clean and beautiful. All residents are encouraged to participate.</p>
                </div>
                <div class="announcement-item">
                    <div class="announcement-header">
                        <h4>Barangay Assembly Meeting</h4>
                        <span class="announcement-date"><i class="far fa-calendar"></i> February 1, 2026</span>
                    </div>
                    <p>The quarterly barangay assembly meeting is scheduled for February 1, 2026, at 3:00 PM at the barangay hall. All residents are invited to attend and participate in community discussions.</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="view-all-btn">
            <a href="announcements.php">View All Announcements <i class="fas fa-arrow-right"></i></a>
        </div>
    </section>

    <!-- ===========================
         BARANGAY INFORMATION SECTION
    =========================== -->
    <section class="barangay-info">
        <div class="section-title">
            <h3>Barangay Information</h3>
            <p>Get to know your barangay</p>
        </div>
        <div class="info-grid">
            <!-- Contact Details -->
            <div class="info-card">
                <div class="info-card-header">
                    <div class="info-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <h4>Contact Information</h4>
                </div>
                <div class="info-item">
                    <i class="fas fa-mobile-alt"></i>
                    <div>
                        <strong>Mobile Number</strong>
                        <a href="tel:+639487970726">+63 948 797 0726</a>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <strong>Email Address</strong>
                        <a href="mailto:barangaycentro@gmail.com">barangaycentro@gmail.com</a>
                    </div>
                </div>
            </div>

            <!-- Office Hours -->
            <div class="info-card">
                <div class="info-card-header">
                    <div class="info-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h4>Office Hours</h4>
                </div>
                <div class="info-item">
                    <i class="far fa-calendar-alt"></i>
                    <div>
                        <strong>Monday - Friday</strong>
                        8:00 AM - 5:00 PM
                    </div>
                </div>
                <div class="info-item">
                    <i class="far fa-calendar-alt"></i>
                    <div>
                        <strong>Saturday</strong>
                        8:00 AM - 12:00 PM
                    </div>
                </div>
                <div class="info-item">
                    <i class="far fa-calendar-times"></i>
                    <div>
                        <strong>Sunday & Holidays</strong>
                        Closed
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="info-card">
                <div class="info-card-header">
                    <div class="info-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <h4>Location & Address</h4>
                </div>
                <div class="info-item">
                    <i class="fas fa-building"></i>
                    <div>
                        <strong>Barangay Hall</strong>
                        San Juan, Brgy Centro, Agdao
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-city"></i>
                    <div>
                        <strong>City/Municipality</strong>
                        Davao City
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-map"></i>
                    <div>
                        <strong>Province</strong>
                        Davao del Sur
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ===========================
         EMERGENCY CONTACT SECTION
    =========================== -->
    <section class="emergency-contact">
        <div class="emergency-content">
            <h3><i class="fas fa-exclamation-triangle"></i> Emergency Hotlines</h3>
            <div class="emergency-numbers">
                <div class="emergency-number">
                    <i class="fas fa-phone-volume"></i>
                    <a href="tel:+639487970726">Barangay: +63 948 797 0726</a>
                </div>
                <div class="emergency-number">
                    <i class="fas fa-ambulance"></i>
                    <a href="tel:911">Emergency: 911</a>
                </div>
            </div>
        </div>
    </section>

    <!-- ===========================
         FOOTER
    =========================== -->
    <footer>
        <p>&copy; 2026 BarangayLink - Brgy Centro. All rights reserved.</p>
        <p>Developed with dedication to serve our community.</p>
        <p>Version 1.0</p>
    </footer>

    <!-- Scroll to Top Button -->
    <div class="scroll-top" id="scrollTop" onclick="scrollToTop()">
        <i class="fas fa-arrow-up"></i>
    </div>

    <script>
        function toggleMenu() {
            const navLinks = document.getElementById('navLinks');
            navLinks.classList.toggle('active');
        }

        document.addEventListener('click', function(event) {
            const navbar = document.querySelector('.navbar');
            const navLinks = document.getElementById('navLinks');
            if (!navbar.contains(event.target)) {
                navLinks.classList.remove('active');
            }
        });

        window.addEventListener('scroll', function() {
            const scrollTop = document.getElementById('scrollTop');
            if (window.pageYOffset > 300) {
                scrollTop.classList.add('show');
            } else {
                scrollTop.classList.remove('show');
            }
        });

        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>