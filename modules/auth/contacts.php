<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - BarangayLink</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
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

        /* Emergency Section */
        .emergency-section {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            padding: 48px;
            border-radius: 16px;
            text-align: center;
            margin-bottom: 60px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.3);
        }

        .emergency-section::before {
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
            0%, 100% {
                transform: translate(-50%, -50%) scale(1);
                opacity: 0.3;
            }
            50% {
                transform: translate(-50%, -50%) scale(1.5);
                opacity: 0;
            }
        }

        .emergency-content {
            position: relative;
            z-index: 1;
        }

        .emergency-section h3 {
            font-size: 32px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            font-weight: 800;
        }

        .emergency-section > p {
            margin-bottom: 24px;
            font-size: 16px;
            opacity: 0.95;
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

        .emergency-number i {
            font-size: 28px;
        }

        .emergency-number a {
            color: white;
            text-decoration: none;
        }

        /* Contact Grid */
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            margin-bottom: 60px;
        }

        .contact-cards {
            display: grid;
            gap: 28px;
        }

        .contact-card {
            background: white;
            border-radius: 16px;
            padding: 36px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.4s ease;
            border: 2px solid #e2e8f0;
        }

        .contact-card:hover {
            box-shadow: 0 12px 32px rgba(30, 58, 138, 0.15);
            transform: translateY(-8px);
            border-color: #1e3a8a;
        }

        .contact-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        .contact-icon {
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

        .contact-card h3 {
            color: #0f172a;
            font-size: 24px;
            font-weight: 700;
        }

        .contact-item {
            display: flex;
            align-items: start;
            gap: 14px;
            margin-bottom: 16px;
            padding: 12px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .contact-item:hover {
            background: #f8fafc;
            transform: translateX(4px);
        }

        .contact-item i {
            color: #1e3a8a;
            margin-top: 3px;
            min-width: 20px;
            font-size: 18px;
        }

        .contact-item strong {
            display: block;
            color: #0f172a;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .contact-item a {
            color: #1e3a8a;
            text-decoration: none;
            font-weight: 500;
        }

        .contact-item a:hover {
            text-decoration: underline;
        }

        /* Contact Form Section */
        .contact-form-section {
            background: white;
            border-radius: 16px;
            padding: 48px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 2px solid #e2e8f0;
        }

        .contact-form-section h3 {
            color: #0f172a;
            font-size: 32px;
            margin-bottom: 12px;
            font-weight: 800;
        }

        .contact-form-section > p {
            color: #64748b;
            margin-bottom: 32px;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            color: #0f172a;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 15px;
        }

        .form-group label .required {
            color: #dc2626;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: inherit;
            color: #1f2937;
        }

        .form-control:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1);
        }

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }

        .btn-submit {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            border: none;
            padding: 16px 40px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(30, 58, 138, 0.4);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 28px;
            display: none;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            font-weight: 500;
        }

        .alert.show {
            display: flex;
        }

        .alert-success {
            background: linear-gradient(135deg, #dcfce7, #d1fae5);
            color: #166534;
            border-left: 4px solid #16a34a;
        }

        .alert-error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Map Section */
        .map-section {
            background: white;
            border-radius: 16px;
            padding: 48px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 2px solid #e2e8f0;
        }

        .map-section h3 {
            color: #0f172a;
            font-size: 32px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .map-container {
            width: 100%;
            height: 450px;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .custom-popup {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            padding: 5px;
        }

        .custom-popup h3 {
            margin: 0 0 12px 0;
            color: #0f172a;
            font-size: 18px;
        }

        .custom-popup p {
            margin: 6px 0;
            color: #64748b;
            font-size: 14px;
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
        @media (max-width: 968px) {
            .contact-grid {
                grid-template-columns: 1fr;
            }
        }

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

            .contact-form-section {
                padding: 32px 24px;
            }

            .emergency-numbers {
                flex-direction: column;
                gap: 20px;
            }

            .emergency-section {
                padding: 32px 24px;
            }

            .map-section {
                padding: 32px 24px;
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
                <li><a href="announcements.php">Announcements</a></li>
                <li><a href="contacts.php" class="active">Contact</a></li>
                <li><a href="login.php">Login</a></li>
            </ul>
        </div>
    </nav>

    <!-- Page Header -->
    <section class="page-header">
        <div class="page-header-content">
            <h2>Contact Us</h2>
            <p>Get in touch with us - we're here to help</p>
            <div class="breadcrumb">
                <a href="index.php"><i class="fas fa-home"></i> Home</a>
                <span>/</span>
                <span>Contact</span>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container">
        <!-- Emergency Section -->
        <div class="emergency-section">
            <div class="emergency-content">
                <h3><i class="fas fa-exclamation-triangle"></i> Emergency Hotlines</h3>
                <p>Available 24/7 for urgent concerns</p>
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
        </div>

        <!-- Contact Grid -->
        <div class="contact-grid">
            <!-- Contact Cards -->
            <div class="contact-cards">
                <!-- Office Contact -->
                <div class="contact-card">
                    <div class="contact-card-header">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <h3>Office Contact</h3>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-mobile-alt"></i>
                        <div>
                            <strong>Mobile Number</strong>
                            <a href="tel:+639487970726">+63 948 797 0726</a>
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <div>
                            <strong>Email Address</strong>
                            <a href="mailto:barangaycentro@gmail.com">barangaycentro@gmail.com</a>
                        </div>
                    </div>
                </div>

                <!-- Office Hours -->
                <div class="contact-card">
                    <div class="contact-card-header">
                        <div class="contact-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3>Office Hours</h3>
                    </div>
                    <div class="contact-item">
                        <i class="far fa-calendar-alt"></i>
                        <div>
                            <strong>Monday - Friday</strong>
                            8:00 AM - 5:00 PM
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="far fa-calendar-alt"></i>
                        <div>
                            <strong>Saturday</strong>
                            8:00 AM - 12:00 PM
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="far fa-calendar-times"></i>
                        <div>
                            <strong>Sunday & Holidays</strong>
                            Closed
                        </div>
                    </div>
                </div>

                <!-- Location -->
                <div class="contact-card">
                    <div class="contact-card-header">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h3>Our Location</h3>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-building"></i>
                        <div>
                            <strong>Barangay Centro Hall</strong>
                            San Juan, Agdao District, Davao City
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-directions"></i>
                        <div>
                            <strong>Landmarks</strong>
                            Near Agdao Public Market
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Form -->
            <div class="contact-form-section">
                <h3>Send Us a Message</h3>
                <p>Have a question or concern? Fill out the form below and we'll get back to you as soon as possible.</p>

                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <span>Your message has been sent successfully! We'll get back to you soon.</span>
                </div>

                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Please fill out all required fields correctly.</span>
                </div>

                <form id="contactForm">
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="Enter your full name" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="your.email@example.com" required>
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="+63 XXX XXX XXXX">
                    </div>

                    <div class="form-group">
                        <label>Subject <span class="required">*</span></label>
                        <input type="text" name="subject" class="form-control" placeholder="What is this about?" required>
                    </div>

                    <div class="form-group">
                        <label>Message <span class="required">*</span></label>
                        <textarea name="message" class="form-control" placeholder="Type your message here..." required></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i>
                        Send Message
                    </button>
                </form>
            </div>
        </div>

        <!-- Map Section -->
        <div class="map-section">
            <h3><i class="fas fa-map-marked-alt"></i> Find Us on the Map</h3>
            <div class="map-container" id="map"></div>
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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
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

        document.getElementById('contactForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            
            const name = formData.get('name');
            const email = formData.get('email');
            const subject = formData.get('subject');
            const message = formData.get('message');
            
            if (!name || !email || !subject || !message) {
                showAlert('error');
                return;
            }
            
            console.log('Form submitted:', {
                name, email, subject, message,
                phone: formData.get('phone')
            });
            
            showAlert('success');
            form.reset();
        });

        function showAlert(type) {
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');
            
            successAlert.classList.remove('show');
            errorAlert.classList.remove('show');
            
            if (type === 'success') {
                successAlert.classList.add('show');
                setTimeout(() => {
                    successAlert.classList.remove('show');
                }, 5000);
            } else {
                errorAlert.classList.add('show');
                setTimeout(() => {
                    errorAlert.classList.remove('show');
                }, 5000);
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

        // Initialize OpenStreetMap with Leaflet
        window.addEventListener('load', function() {
            // Coordinates for Barangay Centro, San Juan, Agdao, Davao City
            const latitude = 7.0902;
            const longitude = 125.6386;
            
            // Create the map
            const map = L.map('map').setView([latitude, longitude], 16);
            
            // Add OpenStreetMap tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);
            
            // Create custom icon
            const customIcon = L.divIcon({
                className: 'custom-marker',
                html: '<div style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); width: 40px; height: 40px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(0,0,0,0.3); border: 3px solid white;"><i class="fas fa-building" style="color: white; font-size: 16px; transform: rotate(45deg);"></i></div>',
                iconSize: [40, 40],
                iconAnchor: [20, 40],
                popupAnchor: [0, -40]
            });
            
            // Add marker
            const marker = L.marker([latitude, longitude], { icon: customIcon }).addTo(map);
            
            // Add popup
            const popupContent = `
                <div class="custom-popup">
                    <h3><i class="fas fa-building"></i> Barangay Centro Hall</h3>
                    <p><strong>Address:</strong><br>
                    Brgy. Centro, San Juan<br>
                    Agdao District, Davao City</p>
                    <p><strong>Phone:</strong> +63 948 797 0726</p>
                    <p><strong>Email:</strong> barangaycentro@gmail.com</p>
                    <p style="margin-top: 10px;">
                        <a href="https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=16/${latitude}/${longitude}" 
                           target="_blank" 
                           style="color: #1e3a8a; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-external-link-alt"></i> View on OpenStreetMap
                        </a>
                    </p>
                </div>
            `;
            
            marker.bindPopup(popupContent).openPopup();
            
            // Add click event to marker
            marker.on('click', function() {
                map.setView([latitude, longitude], 17);
            });
        });
    </script>
</body>
</html>