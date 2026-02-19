<?php
// Include database connection
require_once('../../config/database.php');

// Fetch barangay officials
$barangay_officials = [];
$sql = "SELECT *, CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name) as full_name 
        FROM tbl_barangay_officials 
        WHERE official_type='barangay' AND is_active=1 
        ORDER BY display_order";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $barangay_officials[] = $row;
    }
}

// Fetch SK officials
$sk_officials = [];
$sql = "SELECT *, CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name) as full_name 
        FROM tbl_barangay_officials 
        WHERE official_type='sk' AND is_active=1 
        ORDER BY display_order";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sk_officials[] = $row;
    }
}

// Combine all officials
$all_officials = array_merge($barangay_officials, $sk_officials);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Information - BarangayLink</title>
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

        /* Navigation Bar */
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

        /* Page Header */
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

        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 80px 20px;
        }

        /* Overview Section */
        .overview-section {
            background: white;
            border-radius: 16px;
            padding: 48px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 60px;
            animation: fadeIn 1s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .overview-header {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 36px;
            flex-wrap: wrap;
        }

        .brgy-seal {
            width: 140px;
            height: 140px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 8px 24px rgba(30, 58, 138, 0.3);
            padding: 15px;
            border: 4px solid #1e3a8a;
        }

        .brgy-seal img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brgy-title {
            flex: 1;
        }

        .brgy-title h2 {
            font-size: 40px;
            color: #0f172a;
            margin-bottom: 12px;
            font-weight: 800;
        }

        .brgy-title p {
            font-size: 18px;
            color: #64748b;
        }

        .overview-content {
            line-height: 1.8;
            color: #475569;
            font-size: 16px;
        }

        .overview-content p {
            margin-bottom: 16px;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 60px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(30, 58, 138, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .stat-card:hover::before {
            left: 100%;
        }

        .stat-card:hover {
            box-shadow: 0 12px 32px rgba(30, 58, 138, 0.15);
            transform: translateY(-8px);
        }

        .stat-icon {
            font-size: 48px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 16px;
        }

        .stat-number {
            font-size: 36px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .stat-label {
            color: #64748b;
            font-size: 15px;
            font-weight: 500;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 32px;
            margin-bottom: 60px;
        }

        .info-card {
            background: white;
            border-radius: 16px;
            padding: 36px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.4s ease;
            border: 2px solid #e2e8f0;
        }

        .info-card:hover {
            box-shadow: 0 12px 32px rgba(30, 58, 138, 0.15);
            transform: translateY(-8px);
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

        .info-card h3 {
            color: #0f172a;
            font-size: 24px;
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

        /* Section Title */
        .section-title {
            text-align: center;
            margin-bottom: 48px;
        }

        .section-title h2 {
            font-size: 40px;
            color: #3b82f6;
            margin-bottom: 12px;
            font-weight: 800;
            position: relative;
            display: inline-block;
        }

        .section-title h2::after {
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

        /* Officials Section */
        .officials-section {
            margin-bottom: 60px;
        }

        .officials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 32px;
        }

        .official-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.4s ease;
            border: 2px solid #e2e8f0;
        }

        .official-card:hover {
            box-shadow: 0 12px 32px rgba(30, 58, 138, 0.15);
            transform: translateY(-8px);
            border-color: #1e3a8a;
        }

        .official-photo {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            margin: 0 auto 20px;
            box-shadow: 0 8px 24px rgba(30, 58, 138, 0.3);
            overflow: hidden;
        }

        .official-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .official-name {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .official-position {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .official-committee {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 12px;
        }

        .official-term {
            display: inline-block;
            padding: 6px 16px;
            background: linear-gradient(135deg, #dcfce7, #d1fae5);
            color: #166534;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* Captain Highlight */
        .official-card.captain {
            border: 3px solid #fbbf24;
            grid-column: 1 / -1;
            max-width: 450px;
            margin: 0 auto;
            background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);
        }

        .official-card.captain .official-photo {
            width: 150px;
            height: 150px;
            font-size: 64px;
        }

        .official-card.captain .official-name {
            font-size: 26px;
        }

        .official-card.captain .official-position {
            font-size: 16px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .empty-state i {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .empty-state p {
            font-size: 18px;
        }

        /* Footer */
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

        /* Scroll to Top */
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

        /* Responsive */
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }

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

            .nav-links.active {
                max-height: 500px;
            }

            .nav-links a {
                padding: 18px 20px;
                border-top: 1px solid rgba(255,255,255,0.1);
            }

            .page-header h2 {
                font-size: 36px;
            }

            .brgy-title h2 {
                font-size: 28px;
            }

            .overview-section {
                padding: 32px 24px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .official-card.captain {
                grid-column: 1;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="../../index.php" class="logo">
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
                <li><a href="index.php">Home</a></li>
                <li><a href="brgy-info.php" class="active">Barangay Information</a></li>
                <li><a href="services.php">Services</a></li>
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="contacts.php">Contact</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <div class="page-header-content">
            <h2>About Barangay Centro</h2>
            <p>Get to know our community, our leaders, and our mission</p>
            <div class="breadcrumb">
                <a href="../../index.php"><i class="fas fa-home"></i> Home</a>
                <span>/</span>
                <span>Barangay Information</span>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container">
        <!-- Overview Section -->
        <div class="overview-section">
            <div class="overview-header">
                <div class="brgy-seal">
                    <img src="../../assets/images/brgy.png" alt="Barangay Centro Seal">
                </div>
                <div class="brgy-title">
                    <h2>Barangay Centro</h2>
                    <p>San Juan, Agdao, Davao City, Philippines</p>
                </div>
            </div>
            <div class="overview-content">
                <p>Barangay Centro is a vibrant and progressive community located in the heart of Agdao, Davao City. Established as a barangay in 1975, we have grown into a thriving residential and commercial area that prides itself on community spirit, sustainable development, and quality public service.</p>
                <p>Our barangay is committed to providing efficient and transparent governance to all residents. Through continuous improvements in infrastructure, health services, education support, and disaster preparedness, we strive to create a safe, clean, and prosperous environment for everyone.</p>
                <p>With a population of approximately 8,500 residents across 1,200 households, Barangay Centro is home to diverse families, businesses, and institutions working together toward shared goals of peace, progress, and community welfare.</p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number">8,500+</div>
                <div class="stat-label">Total Population</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-home"></i>
                </div>
                <div class="stat-number">1,200+</div>
                <div class="stat-label">Households</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-map"></i>
                </div>
                <div class="stat-number">2.5</div>
                <div class="stat-label">Square Kilometers</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="stat-number">15</div>
                <div class="stat-label">Puroks/Sitios</div>
            </div>
        </div>

        <!-- Contact Information Grid -->
        <div class="info-grid">
            <!-- Contact Details -->
            <div class="info-card">
                <div class="info-card-header">
                    <div class="info-icon">
                        <i class="fas fa-phone"></i>
                    </div>
                    <h3>Contact Information</h3>
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
                    <h3>Office Hours</h3>
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
                    <h3>Location & Address</h3>
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

        <!-- Barangay Officials Section -->
        <?php if (!empty($all_officials)): ?>
        <div class="officials-section">
            <div class="section-title">
                <h2>Barangay Officials</h2>
                <p>Meet the dedicated leaders serving our community</p>
            </div>

            <div class="officials-grid">
                <?php foreach ($all_officials as $official): ?>
                    <?php 
                    $isCaptain = (stripos($official['position'], 'Punong Barangay') !== false || 
                                 stripos($official['position'], 'Barangay Captain') !== false);
                    $cardClass = $isCaptain ? 'official-card captain' : 'official-card';
                    ?>
                    <div class="<?php echo $cardClass; ?>">
                        <div class="official-photo">
                            <?php if ($official['photo']): ?>
                                <img src="../../<?php echo htmlspecialchars($official['photo']); ?>" alt="<?php echo htmlspecialchars($official['full_name']); ?>">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="official-name">Hon. <?php echo htmlspecialchars($official['full_name']); ?></div>
                        <div class="official-position"><?php echo htmlspecialchars($official['position']); ?></div>
                        <?php if ($official['committee']): ?>
                            <div class="official-committee"><?php echo htmlspecialchars($official['committee']); ?></div>
                        <?php endif; ?>
                        <div class="official-term">
                            Term: <?php echo date('Y', strtotime($official['term_start'])); ?> - <?php echo date('Y', strtotime($official['term_end'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="officials-section">
            <div class="section-title">
                <h2>Barangay Officials</h2>
                <p>Meet the dedicated leaders serving our community</p>
            </div>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <p>No officials have been added yet. Please check back later.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
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
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
    </script>
</body>
</html>