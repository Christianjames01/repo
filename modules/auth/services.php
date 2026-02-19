<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - BarangayLink</title>
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

        /* Services Intro */
        .services-intro {
            text-align: center;
            margin-bottom: 60px;
            animation: fadeIn 1s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .services-intro h3 {
            font-size: 40px;
            margin-bottom: 12px;
            color: #3b82f6;
            font-weight: 800;
            position: relative;
            display: inline-block;
        }

        .services-intro h3::after {
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

        .services-intro p {
            color: #64748b;
            font-size: 18px;
            margin-top: 16px;
        }

        /* Service Category */
        .service-category {
            margin-bottom: 80px;
        }

        .category-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 24px 32px;
            border-radius: 16px 16px 0 0;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
        }

        .category-header i {
            font-size: 36px;
            color: #fbbf24;
        }

        .category-header h3 {
            font-size: 28px;
            font-weight: 700;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            background: white;
            padding: 40px;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .service-card {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 32px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
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
            transform: translateY(-8px);
            border-color: #1e3a8a;
            box-shadow: 0 20px 40px rgba(30, 58, 138, 0.15);
        }

        .service-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }

        .service-card:hover .service-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .service-icon i {
            font-size: 36px;
            color: white;
        }

        .service-card h4 {
            color: #0f172a;
            font-size: 22px;
            margin-bottom: 12px;
            font-weight: 700;
        }

        .service-card > p {
            color: #64748b;
            font-size: 15px;
            margin-bottom: 20px;
            flex-grow: 1;
            line-height: 1.6;
        }

        .requirements-box {
            background: linear-gradient(135deg, #fff9e6 0%, #fffbeb 100%);
            border-left: 4px solid #fbbf24;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
        }

        .requirements-box h5 {
            color: #b45309;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
        }

        .requirements-box ul {
            margin-left: 20px;
            color: #78350f;
        }

        .requirements-box li {
            margin-bottom: 6px;
        }

        .service-details {
            margin: 20px 0;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 14px;
            color: #475569;
            padding: 8px;
            border-radius: 8px;
            transition: background 0.3s;
        }

        .detail-item:hover {
            background: #f1f5f9;
        }

        .detail-item i {
            color: #1e3a8a;
            min-width: 20px;
        }

        .service-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            flex: 1;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #1e3a8a;
            border: 2px solid #1e3a8a;
        }

        .btn-secondary:hover {
            background: #1e3a8a;
            color: white;
        }

        /* Info Box */
        .info-box {
            background: linear-gradient(135deg, #dbeafe 0%, #dbeafe 100%);
            border-left: 4px solid #3b82f6;
            padding: 32px;
            margin: 60px 0;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.1);
        }

        .info-box h4 {
            color: #0c4a6e;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 700;
        }

        .info-box p {
            color: #075985;
            margin-bottom: 12px;
            font-size: 16px;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 32px;
            border-radius: 20px 20px 0 0;
            position: relative;
        }

        .modal-header h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .modal-header p {
            opacity: 0.95;
            font-size: 15px;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 32px;
        }

        .modal-section {
            margin-bottom: 28px;
        }

        .modal-section h4 {
            color: #1e3a8a;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-section h4 i {
            color: #3b82f6;
        }

        .modal-section ul {
            list-style: none;
            padding: 0;
        }

        .modal-section li {
            padding: 10px 0;
            padding-left: 32px;
            position: relative;
            color: #475569;
            line-height: 1.6;
        }

        .modal-section li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #3b82f6;
            font-weight: bold;
            font-size: 18px;
        }

        .modal-section p {
            color: #475569;
            line-height: 1.8;
            margin-bottom: 12px;
        }

        .modal-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-top: 16px;
        }

        .modal-info-item {
            background: #f8fafc;
            padding: 16px;
            border-radius: 12px;
            border-left: 4px solid #3b82f6;
        }

        .modal-info-item strong {
            display: block;
            color: #1e3a8a;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .modal-info-item span {
            color: #64748b;
            font-size: 16px;
            font-weight: 600;
        }

        .modal-footer {
            padding: 24px 32px;
            background: #f8fafc;
            border-radius: 0 0 20px 20px;
            display: flex;
            gap: 12px;
        }

        .modal-footer .btn {
            flex: 1;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                max-height: 90vh;
            }

            .modal-header {
                padding: 24px;
            }

            .modal-body {
                padding: 24px;
            }

            .modal-info-grid {
                grid-template-columns: 1fr;
            }

            .modal-footer {
                flex-direction: column;
            }
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

            .services-intro h3 {
                font-size: 32px;
            }

            .services-grid {
                grid-template-columns: 1fr;
                padding: 25px;
            }

            .service-buttons {
                flex-direction: column;
            }

            .category-header {
                flex-direction: column;
                text-align: center;
                padding: 20px;
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
                <li><a href="services.php" class="active">Services</a></li>
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="contacts.php">Contact</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <div class="page-header-content">
            <h2>Barangay Services</h2>
            <p>Convenient, efficient, and accessible services for all residents</p>
            <div class="breadcrumb">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <span>/</span>
                <span>Services</span>
            </div>
        </div>
    </section>

    <!-- Services Content -->
    <div class="container">
        <div class="services-intro">
            <h3>What We Offer</h3>
            <p>Browse through our comprehensive list of barangay services. We've digitized our processes to make accessing government services easier and more convenient for all residents.</p>
        </div>

        <!-- Document Services -->
        <div class="service-category">
            <div class="category-header">
                <i class="fas fa-file-alt"></i>
                <h3>Document Services</h3>
            </div>
            <div class="services-grid">
                <!-- Barangay Clearance -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-file-certificate"></i>
                    </div>
                    <h4>Barangay Clearance</h4>
                    <p>Required for various transactions such as job applications, business permits, and other official purposes.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>Valid ID (Original & Photocopy)</li>
                            <li>Cedula or Community Tax Certificate</li>
                            <li>1x1 ID Picture (2 pcs)</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Processing Time: 1-2 days</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: ₱50.00</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="login.php" class="btn btn-primary">Apply Now</a>
                        <button onclick="showServiceModal('clearance')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>

                <!-- Certificate of Residency -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <h4>Certificate of Residency</h4>
                    <p>Certifies that you are a bonafide resident of the barangay. Needed for various government transactions.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>Valid ID (Original & Photocopy)</li>
                            <li>Proof of Residency (utility bill, lease contract)</li>
                            <li>1x1 ID Picture (2 pcs)</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Processing Time: 1-2 days</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: ₱30.00</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="login.php" class="btn btn-primary">Apply Now</a>
                        <button onclick="showServiceModal('residency')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>

                <!-- Certificate of Indigency -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <h4>Certificate of Indigency</h4>
                    <p>For low-income families needing medical, educational, or legal assistance.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>Valid ID</li>
                            <li>Proof of low income (optional)</li>
                            <li>Purpose of certificate</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Processing Time: Same day</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: Free</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="login.php" class="btn btn-primary">Apply Now</a>
                        <button onclick="showServiceModal('indigency')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>

                <!-- Business Permit -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <h4>Business Permit Clearance</h4>
                    <p>Required barangay clearance for new or renewing business permits.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>DTI/SEC Registration</li>
                            <li>Valid ID of owner</li>
                            <li>Location sketch/map</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Processing Time: 3-5 days</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: ₱500.00</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="login.php" class="btn btn-primary">Apply Now</a>
                        <button onclick="showServiceModal('business')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Public Safety Services -->
        <div class="service-category">
            <div class="category-header">
                <i class="fas fa-shield-alt"></i>
                <h3>Public Safety & Security</h3>
            </div>
            <div class="services-grid">
                <!-- Blotter Report -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h4>Blotter Report</h4>
                    <p>File incident reports, complaints, or concerns. Our barangay officials will assist in mediation and resolution.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>Valid ID of complainant</li>
                            <li>Detailed incident description</li>
                            <li>Supporting evidence (if available)</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Available: 24/7</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: Free</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="#" class="btn btn-primary">File Report</a>
                        <button onclick="showServiceModal('blotter')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>

                <!-- Building Permit -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <h4>Building Permit Clearance</h4>
                    <p>Required barangay clearance for construction and building permits.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>Property Title/Tax Declaration</li>
                            <li>Building Plans</li>
                            <li>Valid ID of owner</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Processing Time: 3-5 days</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: ₱100.00</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="login.php" class="btn btn-primary">Apply Now</a>
                        <button onclick="showServiceModal('building')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Administrative Services -->
        <div class="service-category">
            <div class="category-header">
                <i class="fas fa-tasks"></i>
                <h3>Administrative Services</h3>
            </div>
            <div class="services-grid">
                <!-- Appointment Scheduling -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h4>Appointment Scheduling</h4>
                    <p>Schedule your visit to the barangay hall for consultations, document processing, or meetings with officials.</p>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Available: Mon-Sat, 8:00 AM - 5:00 PM</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-users"></i>
                            <span>Walk-ins welcome, appointments prioritized</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="#" class="btn btn-primary">Book Appointment</a>
                        <button onclick="showServiceModal('appointment')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>

                <!-- Barangay ID -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <h4>Barangay ID</h4>
                    <p>Apply for or renew your barangay identification card. Valid ID for local transactions.</p>
                    
                    <div class="requirements-box">
                        <h5><i class="fas fa-clipboard-check"></i> Requirements:</h5>
                        <ul>
                            <li>Proof of Residency</li>
                            <li>Birth Certificate (for first time)</li>
                            <li>2x2 ID Picture (2 pcs)</li>
                        </ul>
                    </div>
                    
                    <div class="service-details">
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Processing Time: 7-10 days</span>
                        </div>
                        <div class="detail-item">
                            <i class="fas fa-peso-sign"></i>
                            <span>Fee: ₱50.00</span>
                        </div>
                    </div>
                    
                    <div class="service-buttons">
                        <a href="login.php" class="btn btn-primary">Apply Now</a>
                        <button onclick="showServiceModal('barangayid')" class="btn btn-secondary">Learn More</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Important Information -->
        <div class="info-box">
            <h4><i class="fas fa-info-circle"></i> Important Reminders</h4>
            <p><strong>Office Hours:</strong> Monday to Friday, 8:00 AM - 5:00 PM | Saturday, 8:00 AM - 12:00 PM</p>
            <p><strong>Payment Methods:</strong> Cash payments accepted at the Barangay Hall. Gcash and other e-wallets coming soon.</p>
            <p><strong>Processing Times:</strong> May vary depending on the completeness of requirements and volume of requests.</p>
            <p><strong>For Urgent Concerns:</strong> Please visit the barangay hall directly or call our hotline at +63 948 797 0726</p>
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

    <!-- Service Details Modal -->
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Service Details</h3>
                <p id="modalSubtitle">Complete information about this service</p>
                <button class="modal-close" onclick="closeServiceModal()">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be dynamically inserted here -->
            </div>
            <div class="modal-footer">
                <a href="login.php" class="btn btn-primary">Apply Now</a>
                <button onclick="closeServiceModal()" class="btn btn-secondary">Close</button>
            </div>
        </div>
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

        // Service Modal Functions
        const serviceDetails = {
            clearance: {
                title: 'Barangay Clearance',
                subtitle: 'Required for job applications and official transactions',
                description: 'A Barangay Clearance is an official document issued by the barangay certifying that the person named therein is a resident of the barangay and has no derogatory records or pending cases within the barangay\'s jurisdiction. This is commonly required for employment, business transactions, and various government dealings.',
                purpose: [
                    'Employment application requirements',
                    'Business permit processing',
                    'Loan applications',
                    'School enrollment',
                    'Passport and visa applications',
                    'Court proceedings'
                ],
                requirements: [
                    'Valid Government-issued ID (original and photocopy)',
                    'Cedula or Community Tax Certificate',
                    '1x1 ID Pictures (2 pieces)',
                    'Proof of residency (if first time requesting)'
                ],
                process: [
                    'Visit the barangay hall or apply online',
                    'Submit complete requirements',
                    'Pay the processing fee',
                    'Wait for approval',
                    'Claim your clearance'
                ],
                fee: '₱50.00',
                processing: '1-2 business days',
                validity: '6 months'
            },
            residency: {
                title: 'Certificate of Residency',
                subtitle: 'Proof of residence in Barangay Centro',
                description: 'A Certificate of Residency is an official document certifying that you are a bonafide resident of Barangay Centro. This document is often required for various government transactions, scholarships, and other official purposes where proof of residence is needed.',
                purpose: [
                    'Scholarship applications',
                    'Voter registration',
                    'Government assistance programs',
                    'Educational requirements',
                    'Senior citizen applications',
                    'PWD ID applications'
                ],
                requirements: [
                    'Valid Government-issued ID (original and photocopy)',
                    'Proof of residency (utility bill, lease contract, etc.)',
                    '1x1 ID Pictures (2 pieces)',
                    'Barangay clearance (if available)'
                ],
                process: [
                    'Prepare all required documents',
                    'Visit barangay hall or apply online',
                    'Fill out application form',
                    'Submit requirements and pay fee',
                    'Receive certificate upon approval'
                ],
                fee: '₱30.00',
                processing: '1-2 business days',
                validity: '1 year'
            },
            indigency: {
                title: 'Certificate of Indigency',
                subtitle: 'For low-income families needing assistance',
                description: 'A Certificate of Indigency is issued to residents who need to prove their low-income status for medical assistance, educational scholarships, legal aid, or other social services. This certificate helps ensure that those in need can access government and private assistance programs.',
                purpose: [
                    'Medical and hospitalization assistance',
                    'Educational scholarships and grants',
                    'Legal aid services',
                    'Burial assistance',
                    'Disaster relief',
                    'Government social services'
                ],
                requirements: [
                    'Valid Government-issued ID',
                    'Proof of low income (optional but helpful)',
                    'Letter stating the purpose of certificate',
                    'Supporting documents for the specific assistance needed'
                ],
                process: [
                    'Visit the barangay hall',
                    'Explain your situation to barangay officials',
                    'Submit valid ID and purpose letter',
                    'Certificate is issued on the same day',
                    'Use for intended purpose only'
                ],
                fee: 'FREE',
                processing: 'Same day',
                validity: '3-6 months (depending on purpose)'
            },
            business: {
                title: 'Business Permit Clearance',
                subtitle: 'Required for business operations',
                description: 'A Barangay Business Permit Clearance is required before you can operate any business within Barangay Centro. This clearance certifies that your business location is compliant with barangay regulations and that there are no objections from the community regarding your business operations.',
                purpose: [
                    'New business permit applications',
                    'Business permit renewal',
                    'Business location verification',
                    'Compliance with local regulations',
                    'Mayor\'s permit requirement'
                ],
                requirements: [
                    'DTI or SEC Registration Certificate',
                    'Valid ID of business owner',
                    'Location sketch/vicinity map',
                    'Lease contract or proof of ownership',
                    'Previous business permit (for renewal)',
                    'Barangay clearance of owner'
                ],
                process: [
                    'Secure DTI/SEC registration',
                    'Prepare location documents',
                    'Submit application to barangay',
                    'Pay processing fee',
                    'Wait for barangay inspection',
                    'Claim clearance upon approval'
                ],
                fee: '₱500.00',
                processing: '3-5 business days',
                validity: '1 year (must renew annually)'
            },
            blotter: {
                title: 'Blotter Report',
                subtitle: 'File incident reports and complaints',
                description: 'A Barangay Blotter Report is an official record of incidents, disputes, or complaints within the barangay. Filing a blotter report is often the first step in resolving community disputes through barangay mediation and conciliation. It serves as an official record that can be used in legal proceedings if needed.',
                purpose: [
                    'Record of incidents and disputes',
                    'Requirement for legal proceedings',
                    'Documentation for insurance claims',
                    'Evidence for court cases',
                    'Mediation and conflict resolution',
                    'Protection of rights'
                ],
                requirements: [
                    'Valid ID of complainant',
                    'Detailed written statement of incident',
                    'Supporting evidence (photos, documents, etc.)',
                    'Contact information',
                    'Information about the other party (if known)'
                ],
                process: [
                    'Visit barangay hall or file online',
                    'Provide detailed account of incident',
                    'Submit supporting documents',
                    'Receive blotter report number',
                    'Attend scheduled mediation if applicable'
                ],
                fee: 'FREE',
                processing: 'Immediate recording, mediation scheduled within 3-7 days',
                validity: 'Permanent record'
            },
            building: {
                title: 'Building Permit Clearance',
                subtitle: 'Required for construction projects',
                description: 'A Barangay Building Permit Clearance is required before any construction, renovation, or demolition work can begin. This clearance ensures that the proposed construction complies with barangay regulations, has community approval, and won\'t cause problems for neighbors.',
                purpose: [
                    'New construction projects',
                    'Major renovations and repairs',
                    'Building permit applications',
                    'Compliance verification',
                    'Community clearance'
                ],
                requirements: [
                    'Property title or tax declaration',
                    'Building plans and specifications',
                    'Valid ID of property owner',
                    'Location sketch',
                    'Engineer\'s/Architect\'s seal (for major projects)',
                    'Neighbor\'s consent (if applicable)'
                ],
                process: [
                    'Prepare building plans',
                    'Secure property documents',
                    'Submit application with requirements',
                    'Pay processing fee',
                    'Wait for barangay inspection',
                    'Claim clearance if approved'
                ],
                fee: '₱100.00',
                processing: '3-5 business days',
                validity: 'Duration of approved construction period'
            },
            appointment: {
                title: 'Appointment Scheduling',
                subtitle: 'Book your visit in advance',
                description: 'Schedule an appointment with barangay officials to ensure prompt service and avoid long waiting times. Walk-ins are welcome, but appointments are prioritized. You can schedule appointments for document processing, consultations, or meetings with barangay officials.',
                purpose: [
                    'Faster document processing',
                    'Priority service',
                    'Consultation with officials',
                    'Avoid long queues',
                    'Better time management',
                    'Guaranteed service schedule'
                ],
                requirements: [
                    'Valid contact information',
                    'Purpose of visit',
                    'Preferred date and time',
                    'Valid ID (bring on appointment day)'
                ],
                process: [
                    'Login to your account',
                    'Select service needed',
                    'Choose preferred date and time',
                    'Receive confirmation',
                    'Arrive on scheduled time with requirements'
                ],
                fee: 'FREE',
                processing: 'Immediate confirmation',
                validity: 'Specific to scheduled date'
            },
            barangayid: {
                title: 'Barangay ID',
                subtitle: 'Official identification card',
                description: 'The Barangay ID is an official identification card issued to residents of Barangay Centro. It serves as proof of residency and can be used for various local transactions. While not a primary government ID, it is accepted for many local services and transactions within the barangay.',
                purpose: [
                    'Proof of residency',
                    'Access to barangay services',
                    'Senior citizen discounts (with proper marking)',
                    'Barangay event access',
                    'Emergency contact information',
                    'Voter verification'
                ],
                requirements: [
                    'Proof of residency (utility bill, lease, etc.)',
                    'Birth certificate (for first-time applicants)',
                    '2x2 ID pictures (2 pieces)',
                    'Barangay clearance',
                    'Previous barangay ID (for renewal)'
                ],
                process: [
                    'Submit complete requirements',
                    'Fill out application form',
                    'Have your photo taken',
                    'Pay processing fee',
                    'Wait for ID production',
                    'Claim ID with valid identification'
                ],
                fee: '₱50.00',
                processing: '7-10 business days',
                validity: 'Until change of address or update needed'
            }
        };

        function showServiceModal(serviceType) {
            const modal = document.getElementById('serviceModal');
            const details = serviceDetails[serviceType];
            
            if (!details) return;
            
            document.getElementById('modalTitle').textContent = details.title;
            document.getElementById('modalSubtitle').textContent = details.subtitle;
            
            const modalBody = document.getElementById('modalBody');
            modalBody.innerHTML = `
                <div class="modal-section">
                    <p>${details.description}</p>
                </div>
                
                <div class="modal-info-grid">
                    <div class="modal-info-item">
                        <strong>Processing Fee</strong>
                        <span>${details.fee}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Processing Time</strong>
                        <span>${details.processing}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Validity Period</strong>
                        <span>${details.validity}</span>
                    </div>
                    <div class="modal-info-item">
                        <strong>Service Type</strong>
                        <span>Document Service</span>
                    </div>
                </div>
                
                <div class="modal-section">
                    <h4><i class="fas fa-bullseye"></i> Common Uses</h4>
                    <ul>
                        ${details.purpose.map(item => `<li>${item}</li>`).join('')}
                    </ul>
                </div>
                
                <div class="modal-section">
                    <h4><i class="fas fa-clipboard-check"></i> Requirements</h4>
                    <ul>
                        ${details.requirements.map(item => `<li>${item}</li>`).join('')}
                    </ul>
                </div>
                
                <div class="modal-section">
                    <h4><i class="fas fa-list-ol"></i> Application Process</h4>
                    <ul>
                        ${details.process.map(item => `<li>${item}</li>`).join('')}
                    </ul>
                </div>
            `;
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeServiceModal() {
            const modal = document.getElementById('serviceModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('serviceModal');
            if (event.target === modal) {
                closeServiceModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeServiceModal();
            }
        });
    </script>
</body>
</html>