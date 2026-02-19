<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - BarangayLink</title>
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

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 48px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .filter-tab {
            padding: 12px 28px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .filter-tab:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            border-color: #1e3a8a;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }

        /* Announcements Grid */
        .announcements-grid {
            display: grid;
            gap: 32px;
        }

        .announcement-card {
            background: white;
            border-radius: 16px;
            padding: 36px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-left: 6px solid #1e3a8a;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }

        .announcement-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(30, 58, 138, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .announcement-card:hover::before {
            left: 100%;
        }

        .announcement-card:hover {
            transform: translateX(8px);
            box-shadow: 0 12px 24px rgba(30, 58, 138, 0.15);
            border-left-width: 8px;
        }

        .announcement-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
        }

        .announcement-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .announcement-card:hover .announcement-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .announcement-meta {
            flex: 1;
        }

        .announcement-title {
            font-size: 26px;
            color: #0f172a;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1.3;
        }

        .announcement-date {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .announcement-date i {
            color: #1e3a8a;
        }

        .announcement-content {
            color: #475569;
            font-size: 16px;
            line-height: 1.8;
            margin-bottom: 24px;
        }

        .announcement-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .announcement-badge {
            display: inline-block;
            padding: 6px 16px;
            background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%);
            color: #166534;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        .announcement-badge.important {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }

        .announcement-badge.event {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .empty-state i {
            font-size: 80px;
            color: #cbd5e1;
            margin-bottom: 24px;
        }

        .empty-state h3 {
            font-size: 28px;
            color: #64748b;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .empty-state p {
            color: #94a3b8;
            font-size: 16px;
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 48px 20px;
            text-align: center;
            margin-top: 80px;
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

            .announcement-title {
                font-size: 22px;
            }

            .announcement-card {
                padding: 24px;
            }

            .announcement-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <img src="/barangaylink1/assets/images/logos.png" alt="Barangay Logo">
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
                <li><a href="brgy-info.php">Barangay Information</a></li>
                <li><a href="services.php">Services</a></li>
                <li><a href="announcements.php" class="active">Announcements</a></li>
                <li><a href="contacts.php">Contact</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <div class="page-header-content">
            <h2>Announcements</h2>
            <p>Stay informed with the latest news and updates from your barangay</p>
            <div class="breadcrumb">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <span>/</span>
                <span>Announcements</span>
            </div>
        </div>
    </section>

    <!-- Announcements Content -->
    <div class="container">
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <div class="filter-tab active" onclick="filterAnnouncements('all')">
                <i class="fas fa-list"></i>
                <span>All Announcements</span>
            </div>
            <div class="filter-tab" onclick="filterAnnouncements('recent')">
                <i class="fas fa-clock"></i>
                <span>Recent</span>
            </div>
            <div class="filter-tab" onclick="filterAnnouncements('important')">
                <i class="fas fa-exclamation-circle"></i>
                <span>Important</span>
            </div>
        </div>

        <!-- Announcements Grid -->
        <div id="announcementsContainer" class="announcements-grid">
            <!-- Announcement 1 -->
            <div class="announcement-card" data-category="recent important">
                <div class="announcement-header">
                    <div class="announcement-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="announcement-meta">
                        <h3 class="announcement-title">Welcome to BarangayLink</h3>
                        <div class="announcement-date">
                            <i class="far fa-calendar"></i>
                            <span>February 13, 2026</span>
                        </div>
                    </div>
                </div>
                <div class="announcement-content">
                    <p>We are pleased to announce the launch of our new digital barangay management system. This platform will make accessing barangay services more convenient for all residents. You can now request documents, file reports, and schedule appointments online.</p>
                </div>
                <div class="announcement-badges">
                    <span class="announcement-badge important">Important</span>
                </div>
            </div>

            <!-- Announcement 2 -->
            <div class="announcement-card" data-category="recent">
                <div class="announcement-header">
                    <div class="announcement-icon">
                        <i class="fas fa-broom"></i>
                    </div>
                    <div class="announcement-meta">
                        <h3 class="announcement-title">Community Clean-Up Drive</h3>
                        <div class="announcement-date">
                            <i class="far fa-calendar"></i>
                            <span>January 25, 2026</span>
                        </div>
                    </div>
                </div>
                <div class="announcement-content">
                    <p>Join us this Saturday at 6:00 AM for our monthly community clean-up drive. Let's work together to keep our barangay clean and beautiful. All residents are encouraged to participate. Please bring gloves and face masks.</p>
                </div>
                <div class="announcement-badges">
                    <span class="announcement-badge event">Community Event</span>
                </div>
            </div>

            <!-- Announcement 3 -->
            <div class="announcement-card" data-category="all">
                <div class="announcement-header">
                    <div class="announcement-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="announcement-meta">
                        <h3 class="announcement-title">Barangay Assembly Meeting</h3>
                        <div class="announcement-date">
                            <i class="far fa-calendar"></i>
                            <span>February 1, 2026</span>
                        </div>
                    </div>
                </div>
                <div class="announcement-content">
                    <p>The quarterly barangay assembly meeting is scheduled for February 1, 2026, at 3:00 PM at the barangay hall. All residents are invited to attend and participate in community discussions. Topics include budget allocation, upcoming projects, and community concerns.</p>
                </div>
                <div class="announcement-badges">
                    <span class="announcement-badge event">Meeting</span>
                </div>
            </div>

            <!-- Announcement 4 -->
            <div class="announcement-card" data-category="all important">
                <div class="announcement-header">
                    <div class="announcement-icon">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <div class="announcement-meta">
                        <h3 class="announcement-title">Free Medical Mission</h3>
                        <div class="announcement-date">
                            <i class="far fa-calendar"></i>
                            <span>January 30, 2026</span>
                        </div>
                    </div>
                </div>
                <div class="announcement-content">
                    <p>Free medical and dental check-ups will be available on January 30, 2026, from 8:00 AM to 4:00 PM at the barangay covered court. Bring your health records and a valid ID. First come, first served. Services include general consultation, dental check-up, blood pressure monitoring, and blood sugar testing.</p>
                </div>
                <div class="announcement-badges">
                    <span class="announcement-badge important">Important</span>
                    <span class="announcement-badge event">Health</span>
                </div>
            </div>

            <!-- Announcement 5 -->
            <div class="announcement-card" data-category="all">
                <div class="announcement-header">
                    <div class="announcement-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="announcement-meta">
                        <h3 class="announcement-title">Scholarship Program Application</h3>
                        <div class="announcement-date">
                            <i class="far fa-calendar"></i>
                            <span>January 15, 2026</span>
                        </div>
                    </div>
                </div>
                <div class="announcement-content">
                    <p>Applications for the Barangay Scholarship Program for School Year 2026-2027 are now open. Eligible students must be bonafide residents of Brgy Centro with a general average of 85% or higher. Application forms are available at the barangay hall. Deadline: February 15, 2026.</p>
                </div>
                <div class="announcement-badges">
                    <span class="announcement-badge">Scholarship</span>
                </div>
            </div>
        </div>

        <!-- Empty State (hidden by default) -->
        <div id="emptyState" class="empty-state" style="display: none;">
            <i class="fas fa-inbox"></i>
            <h3>No Announcements Found</h3>
            <p>There are currently no announcements matching your filter.</p>
        </div>
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

        function filterAnnouncements(category) {
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.closest('.filter-tab').classList.add('active');

            const cards = document.querySelectorAll('.announcement-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const categories = card.getAttribute('data-category');
                if (category === 'all' || categories.includes(category)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const emptyState = document.getElementById('emptyState');
            const container = document.getElementById('announcementsContainer');
            if (visibleCount === 0) {
                container.style.display = 'none';
                emptyState.style.display = 'block';
            } else {
                container.style.display = 'grid';
                emptyState.style.display = 'none';
            }
        }

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