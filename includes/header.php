<?php
// Fetch user profile photo if logged in
$user_profile_photo = null;
$user_full_name = 'User';
$user_initials = 'U';

if (isLoggedIn()) {
    $current_user_id = getCurrentUserId();
    $stmt = $conn->prepare("
        SELECT res.profile_photo, res.first_name, res.last_name, u.username
        FROM tbl_users u
        LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($profile_data = $result->fetch_assoc()) {
        $user_profile_photo = $profile_data['profile_photo'];
        if (!empty($profile_data['first_name']) && !empty($profile_data['last_name'])) {
            $user_full_name = $profile_data['first_name'] . ' ' . $profile_data['last_name'];
            $user_initials = strtoupper(substr($profile_data['first_name'], 0, 1) . substr($profile_data['last_name'], 0, 1));
        } else {
            $user_full_name = $profile_data['username'];
            $user_initials = strtoupper(substr($profile_data['username'], 0, 1));
        }
    }
    $stmt->close();
}

$user_role = isset($_SESSION['role_name']) ? $_SESSION['role_name'] : (isset($_SESSION['role']) ? $_SESSION['role'] : '');

// Define if user is Tanod or Driver
$is_tanod = in_array($user_role, ['Tanod', 'Barangay Tanod']);
$is_driver = ($user_role === 'Driver');

// Get driver's assigned vehicle ID
$driver_vehicle_id = null;
if ($is_driver && isLoggedIn()) {
    $vehicle_stmt = $conn->prepare("SELECT vehicle_id FROM tbl_vehicles WHERE assigned_driver_id = ? LIMIT 1");
    $vehicle_stmt->bind_param("i", $current_user_id);
    $vehicle_stmt->execute();
    $vehicle_result = $vehicle_stmt->get_result();
    if ($vehicle_row = $vehicle_result->fetch_assoc()) {
        $driver_vehicle_id = $vehicle_row['vehicle_id'];
    }
    $vehicle_stmt->close();
}


$unread_count = 0;
if (isLoggedIn()) {
    $unread_count = getUnreadNotificationCount($conn, getCurrentUserId());
}


// ✅ ADD THIS: Fetch recent notifications for dropdown
$recent_notifications = [];
if (isLoggedIn()) {
    $current_user_id = getCurrentUserId();
    $notif_query = "SELECT * FROM tbl_notifications 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 5";
    $notif_stmt = $conn->prepare($notif_query);
    $notif_stmt->bind_param("i", $current_user_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result();
    while ($notif = $notif_result->fetch_assoc()) {
        $recent_notifications[] = $notif;
    }
    $notif_stmt->close();
}

// Define base URL for consistent navigation
$base_url = '/barangaylink1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Barangay Management</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
   <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/sidebar-layout.css">
    <style>
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            overflow: hidden;
            background: #2d3748;
            color: white;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Sidebar Logo Styling */
        .sidebar-logo {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            padding: 4px;
        }

        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        /* Notification Badge in Sidebar */
        .nav-link .notification-badge {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: #dc3545;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 0.7rem;
            font-weight: bold;
            min-width: 18px;
            text-align: center;
        }
        
        .nav-item {
            position: relative;
        }
        
    </style>
    
    <!-- Extra CSS for specific pages -->
    <?php if (isset($extra_css)): ?>
        <?php echo $extra_css; ?>
    <?php endif; ?>
</head>
<body class="sidebar-open">
    <!-- Sidebar -->
    <aside class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="<?php echo $base_url; ?>/uploads/officials/brgy.png" alt="<?php echo BARANGAY_NAME; ?>">
        </div>
        <span class="sidebar-title">Brgy Centro</span>
    </div>
        
        <nav class="sidebar-nav">
            <?php if ($is_driver): ?>
                <!-- ============================================================ -->
                <!-- DRIVER SIDEBAR                                               -->
                <!-- ============================================================ -->
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'driver') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/calendar.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'calendar.php' && strpos($_SERVER['PHP_SELF'], 'driver') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Calendar</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/officials/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'officials') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog"></i>
                            <span>Officials</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/attendance/my-schedule.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'attendance') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check"></i>
                            <span>Attendance</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Vehicle Management</div>
                    <div class="nav-item">
                        <?php if ($driver_vehicle_id): ?>
                            <a href="<?php echo $base_url; ?>/vehicles/driver/my-vehicle.php?id=<?php echo $driver_vehicle_id; ?>" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'my-vehicle.php') ? 'active' : ''; ?>">
                                <i class="fas fa-car"></i>
                                <span>My Vehicle</span>
                            </a>
                        <?php else: ?>
                            <a href="#" class="nav-link disabled" title="No vehicle assigned">
                                <i class="fas fa-car"></i>
                                <span>My Vehicle</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/trip-log.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'trip-log.php') ? 'active' : ''; ?>">
                            <i class="fas fa-route"></i>
                            <span>Trip Logs</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/fuel-records.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'fuel-records.php') ? 'active' : ''; ?>">
                            <i class="fas fa-gas-pump"></i>
                            <span>Fuel Records</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/maintenance.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'maintenance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-wrench"></i>
                            <span>Maintenance</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/vehicle-reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle-reports.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-bar"></i>
                            <span>Reports</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/driver/profile.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'profile.php' && strpos($_SERVER['PHP_SELF'], 'driver') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
                
            <?php elseif ($is_tanod): ?>
                <!-- ============================================================ -->
                <!-- TANOD SIDEBAR                                                -->
                <!-- ============================================================ -->
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/calendar/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'calendar') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Calendar</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/officials/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'officials') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog"></i>
                            <span>Officials</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/attendance/my-schedule.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'attendance') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check"></i>
                            <span>Attendance</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Services</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/incidents/view-incidents.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'incidents') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Incidents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'notifications') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/residents/profile.php" class="nav-link">
                            <i class="fas fa-user-circle"></i>
                            <span>Profile</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
                
            <?php elseif ($user_role === 'Resident'): ?>
                <!-- ============================================================ -->
                <!-- RESIDENT SIDEBAR                                             -->
                <!-- ============================================================ -->
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/calendar/index.php" class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], '/calendar/') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Calendar</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/officials/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'officials') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog"></i>
                            <span>Officials</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">   
                    <div class="nav-section-title">Services</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/incidents/view-incidents.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'incidents') !== false && strpos($_SERVER['PHP_SELF'], 'disasters') === false ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Incidents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/complaints/view-complaints.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'complaints') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-comments"></i>
                            <span>Complaints</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/blotter/my-blotter.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'blotter') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Blotter</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/requests/my-requests.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'requests') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-file-alt"></i>
                            <span>My Requests</span>
                        </a>
                    </div>
                </div>

                 <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'notifications') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>

                <div class="nav-section">
                    <div class="nav-section-title">Health Services</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/my-health.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'my-health.php') ? 'active' : ''; ?>">
                            <i class="fas fa-heartbeat"></i>
                            <span>My Health Record</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/my-vaccinations.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'my-vaccinations.php') ? 'active' : ''; ?>">
                            <i class="fas fa-syringe"></i>
                            <span>My Vaccinations</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/book-appointment.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'book-appointment.php') ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-plus"></i>
                            <span>Book Appointment</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/request-assistance.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'request-assistance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-hand-holding-medical"></i>
                            <span>Request Assistance</span>
                        </a>
                    </div>
                </div>

                <!-- EDUCATION ASSISTANCE (Resident) -->
                <div class="nav-section">
                    <div class="nav-section-title">Education</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/resident/apply-scholarship.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'apply-scholarship.php') ? 'active' : ''; ?>">
                            <i class="fas fa-file-alt"></i>
                            <span>Apply for Scholarship</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/resident/assistance-requests.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'assistance-requests.php' && strpos($_SERVER['PHP_SELF'], 'education') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-hand-holding-usd"></i>
                            <span>Assistance Requests</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/resident/my-documents.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'my-documents.php') ? 'active' : ''; ?>">
                            <i class="fas fa-folder-open"></i>
                            <span>My Documents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/resident/request-assistance.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'request-assistance.php' && strpos($_SERVER['PHP_SELF'], 'education') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-hand-holding-medical"></i>
                            <span>Request Assistance</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/resident/scholarship-guide.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'scholarship-guide.php') ? 'active' : ''; ?>">
                            <i class="fas fa-book-open"></i>
                            <span>Scholarship Guide</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/resident/student-portal.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'student-portal.php') ? 'active' : ''; ?>">
                            <i class="fas fa-graduation-cap"></i>
                            <span>Student Portal</span>
                        </a>
                    </div>
                </div>
                
                <!-- BUSINESS PERMIT (Resident) -->
                <div class="nav-section">
                    <div class="nav-section-title">Business</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/resident/my-permits.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'business/resident') !== false && strpos($_SERVER['PHP_SELF'], 'my-permits') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-briefcase"></i>
                            <span>My Business Permits</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/resident/apply-permit.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'apply-permit.php' && strpos($_SERVER['PHP_SELF'], 'business') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-plus-circle"></i>
                            <span>Apply for Permit</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/resident/renewal.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'renewal.php' && strpos($_SERVER['PHP_SELF'], 'business') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-sync-alt"></i>
                            <span>Permit Renewal</span>
                        </a>
                    </div>
                </div>

                <!-- JOB BOARD & LIVELIHOOD (Resident) -->
                <div class="nav-section">
                    <div class="nav-section-title">Jobs & Livelihood</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/resident/jobs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'jobs.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-search"></i>
                            <span>Browse Jobs</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/resident/my-applications.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'my-applications.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>My Job Applications</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/resident/trainings.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'trainings.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span>Skills Training</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/resident/livelihood.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'livelihood.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-hands-helping"></i>
                            <span>Livelihood Programs</span>
                        </a>
                    </div>
                </div>

                <!-- WASTE MANAGEMENT (Resident) -->
                <div class="nav-section">
                    <div class="nav-section-title">Waste Management</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/resident/schedule.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'schedule.php' && strpos($_SERVER['PHP_SELF'], 'waste') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-trash-alt"></i>
                            <span>Collection Schedule</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/resident/recycling.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'recycling.php' && strpos($_SERVER['PHP_SELF'], 'waste') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-recycle"></i>
                            <span>Recycling Programs</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/resident/report-issue.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'report-issue.php' && strpos($_SERVER['PHP_SELF'], 'waste') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Report Waste Issue</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Disaster Assistance</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/resident/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'disasters/resident') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-shield-alt"></i>
                            <span>Disaster Info</span>
                        </a>
                    </div>
                </div>

                <div class="nav-item">
                    <a href="<?php echo $base_url; ?>/modules/typhoon_tracker/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'typhoon_tracker') !== false) ? 'active' : ''; ?>">
                        <i class="fas fa-hurricane"></i>
                        <span>Typhoon Tracker</span>
                    </a>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Community</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/forum.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/forum') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-comments"></i>
                            <span>Community Board</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/polls.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/polls') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-poll"></i>
                            <span>Polls & Surveys</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/events.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/events') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-circle"></i>
                            <span>Events</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/announcements.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/announcements') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-bullhorn"></i>
                            <span>Announcements</span>
                        </a>
                    </div>
                </div>
                            
                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/residents/profile.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'profile.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-user-circle"></i>
                            <span>Profile</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- ============================================================ -->
                <!-- ADMIN / STAFF / SUPER ADMIN SIDEBAR                         -->
                <!-- ============================================================ -->
                <div class="nav-section">
                    <div class="nav-section-title">Main</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'dashboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/calendar/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'calendar') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Calendar</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/officials/manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'officials') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog"></i>
                            <span>Officials</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/attendance/admin/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'attendance') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check"></i>
                            <span>Attendance</span>
                        </a>
                    </div>
                </div>
                
                <!-- MANAGEMENT -->
                <div class="nav-section">
                    <div class="nav-section-title">Management</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/residents/manage.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'manage.php' && strpos($_SERVER['PHP_SELF'], 'residents') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            <span> Manage Residents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/incidents/manage-incidents.php" class="nav-link <?php echo (strpos($_SERVER['PHP_SELF'], 'incidents') !== false && strpos($_SERVER['PHP_SELF'], 'disasters') === false) || basename($_SERVER['PHP_SELF']) == 'manage-incidents.php' ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Manage Incidents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/complaints/view-complaints.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'complaints') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-comment-dots"></i>
                            <span> Manage Complaints</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/blotter/manage-blotter.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'blotter') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Manage Blotter Records</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/requests/admin-manage-requests.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'requests/admin') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice"></i>
                            <span>Manage Documents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/staff/manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'staff/manage') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-user-tie"></i>
                            <span>Manage Staff</span>
                        </a>
                    </div>
                     <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'notifications') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-bell"></i>
                            <span>Notifications</span>
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/qrcodes/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'qrcodes') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-id-card"></i>
                            <span>ID Management</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/seniors/manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'seniors/manage') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-user-friends"></i>
                            <span>Senior Citizens</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/vehicles/admin/index.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'vehicles/admin') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-car"></i>
                            <span>Vehicles</span>
                        </a>
                    </div>
                </div>

                <!-- EDUCATION ASSISTANCE (Admin) -->
                <div class="nav-section">
                    <div class="nav-section-title">Education Assistance</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'education') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-graduation-cap"></i>
                            <span>Education Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/add-student.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'add-student.php') ? 'active' : ''; ?>">
                            <i class="fas fa-user-plus"></i>
                            <span>Add Student</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/manage-students.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-students.php' && strpos($_SERVER['PHP_SELF'], 'education') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-user-graduate"></i>
                            <span>Manage Students</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/scholarships.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'scholarships.php' && strpos($_SERVER['PHP_SELF'], 'education') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-award"></i>
                            <span>Scholarships</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/student-records.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'student-records.php') ? 'active' : ''; ?>">
                            <i class="fas fa-notes-medical"></i>
                            <span>Student Records</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/view-application.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'view-application.php') ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check"></i>
                            <span>View Applications</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/view-student.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'view-student.php') ? 'active' : ''; ?>">
                            <i class="fas fa-eye"></i>
                            <span>View Student</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/education/reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'reports.php' && strpos($_SERVER['PHP_SELF'], 'education') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i>
                            <span>Education Reports</span>
                        </a>
                    </div>
                </div>

                <!-- WASTE MANAGEMENT (Admin) - CORRECTED PATHS -->
                <div class="nav-section">
                    <div class="nav-section-title">Waste Management</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/admin/dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], 'waste_management/admin') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-trash-alt"></i>
                            <span>Waste Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/admin/programs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'programs.php' && strpos($_SERVER['PHP_SELF'], 'waste_management/admin') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-recycle"></i>
                            <span>Programs</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/admin/reports-issues.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'reports-issues.php' && strpos($_SERVER['PHP_SELF'], 'waste_management/admin') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Reported Issues</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/admin/recycling.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'recycling.php' && strpos($_SERVER['PHP_SELF'], 'waste_management/admin') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-leaf"></i>
                            <span>Recycling</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/waste_management/admin/schedules.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'schedules.php' && strpos($_SERVER['PHP_SELF'], 'waste_management/admin') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Schedules</span>
                        </a>
                    </div>
                </div>

                <!-- BUSINESS PERMIT (Admin) -->
                <div class="nav-section">
                    <div class="nav-section-title">Business Permits</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/admin/dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], 'business') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-briefcase"></i>
                            <span>Business Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/admin/applications.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'applications.php' && strpos($_SERVER['PHP_SELF'], 'business') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice"></i>
                            <span>Permit Applications</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/admin/renewals.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'renewals.php' && strpos($_SERVER['PHP_SELF'], 'business') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-sync-alt"></i>
                            <span>Renewals</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/business/admin/registry.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'registry.php' && strpos($_SERVER['PHP_SELF'], 'business') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-building"></i>
                            <span>Business Registry</span>
                        </a>
                    </div>
                </div>

                <!-- JOB BOARD & LIVELIHOOD (Admin) -->
                <div class="nav-section">
                    <div class="nav-section-title">Jobs & Livelihood</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/admin/dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-th-large"></i>
                            <span>Job Board Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/admin/manage-jobs.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'manage-jobs.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-briefcase"></i>
                            <span>Manage Job Posts</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/admin/applications.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'applications.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Job Applications</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/admin/trainings.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'trainings.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <span>Skills Training</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/jobboard/admin/livelihood.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'livelihood.php' && strpos($_SERVER['PHP_SELF'], 'jobboard') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-hands-helping"></i>
                            <span>Livelihood Programs</span>
                        </a>
                    </div>
                </div>
                
                <?php if ($user_role === 'Super Admin' || $user_role === 'Staff'): ?>
                <!-- INVENTORY -->
                <div class="nav-section">
                    <div class="nav-section-title">Inventory</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/relief/inventory.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'inventory.php' && strpos($_SERVER['PHP_SELF'], 'relief') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-boxes"></i>
                            <span>Relief Inventory</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/relief/distribution-report.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'distribution-report.php') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-bar"></i>
                            <span>Distribution Reports</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($user_role === 'Super Admin' || $user_role === 'Staff'): ?>
                <!-- FINANCIAL -->
                <div class="nav-section">
                    <div class="nav-section-title">Financial</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/financial/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'financial') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave"></i>
                            <span>Financial Overview</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/financial/budget.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'budget.php' && strpos($_SERVER['PHP_SELF'], 'financial') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-wallet"></i>
                            <span>Budget Management</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/financial/expenses.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'expenses.php' && strpos($_SERVER['PHP_SELF'], 'financial') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-receipt"></i>
                            <span>Expenses</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/financial/revenues.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'revenues.php' && strpos($_SERVER['PHP_SELF'], 'financial') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-coins"></i>
                            <span>Revenue</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/financial/transactions.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'transactions.php' && strpos($_SERVER['PHP_SELF'], 'financial') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-exchange-alt"></i>
                            <span>Transactions</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- DISASTER -->
                <div class="nav-section">
                    <div class="nav-section-title">Disaster</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/damage-assessment.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'damage-assessment.php') ? 'active' : ''; ?>">
                            <i class="fas fa-house-damage"></i>
                            <span>Damage Assessment</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/disaster-incidents.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'disaster-incidents.php') ? 'active' : ''; ?>">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>Disaster Incidents</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/disaster-reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'disaster-reports.php') ? 'active' : ''; ?>">
                            <i class="fas fa-file-alt"></i>
                            <span>Disaster Reports</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/distribute-relief.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'distribute-relief.php') ? 'active' : ''; ?>">
                            <i class="fas fa-hands-helping"></i>
                            <span>Distribute Relief</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/evacuation-centers.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'evacuation-centers.php') ? 'active' : ''; ?>">
                            <i class="fas fa-home"></i>
                            <span>Evacuation Centers</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/disasters/evacuee-registration.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'evacuee-registration.php') ? 'active' : ''; ?>">
                            <i class="fas fa-user-plus"></i>
                            <span>Evacuee Registration</span>
                        </a>
                    </div>
                </div>
                
                <div class="nav-item">
                    <a href="<?php echo $base_url; ?>/modules/typhoon_tracker/index.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'typhoon_tracker') !== false) ? 'active' : ''; ?>">
                        <i class="fas fa-hurricane"></i>
                        <span>Typhoon Tracker</span>
                    </a>
                </div>

                <!-- COMMUNITY -->
                <div class="nav-section">
                    <div class="nav-section-title">Community</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/admin/forum-manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/admin/forum') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-comments"></i>
                            <span>Manage Forum</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/admin/polls-manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/admin/polls') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-poll-h"></i>
                            <span>Manage Polls</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/admin/events-manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/admin/events') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check"></i>
                            <span>Manage Events</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/community/admin/announcements-manage.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'community/admin/announcements') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-megaphone"></i>
                            <span>Post Announcements</span>
                        </a>
                    </div>
                </div>

                <?php if ($user_role === 'Super Admin'): ?>
                <!-- 4Ps PROGRAM -->
                <div class="nav-section">
                    <div class="nav-section-title">4Ps Program</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/4ps/registration.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'registration.php' && strpos($_SERVER['PHP_SELF'], '4ps') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-user-plus"></i>
                            <span>4Ps Registration</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/4ps/beneficiaries-debug.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'beneficiaries-debug.php' && strpos($_SERVER['PHP_SELF'], '4ps') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i>
                            <span>Manage Beneficiaries</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/4ps/reports.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'reports.php' && strpos($_SERVER['PHP_SELF'], '4ps') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i>
                            <span>4Ps Reports</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($user_role === 'Super Admin' || $user_role === 'Staff' || $user_role === 'Admin'): ?>
                <!-- HEALTH SERVICES -->
                <div class="nav-section">
                    <div class="nav-section-title">Health Services</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/dashboard.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], 'health') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-heartbeat"></i>
                            <span>Health Dashboard</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/records.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'records.php' && strpos($_SERVER['PHP_SELF'], 'health') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-notes-medical"></i>
                            <span>Health Records</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/vaccinations.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'vaccinations.php' && strpos($_SERVER['PHP_SELF'], 'health') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-syringe"></i>
                            <span>Vaccinations</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/appointments.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'appointments.php' && strpos($_SERVER['PHP_SELF'], 'health') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check"></i>
                            <span>Appointments</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/medical-assistance.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'medical-assistance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-hand-holding-medical"></i>
                            <span>Medical Assistance</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/health/disease-surveillance.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'disease-surveillance.php') ? 'active' : ''; ?>">
                            <i class="fas fa-virus"></i>
                            <span>Disease Surveillance</span>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- MOBILE APP INFO -->
                <div class="nav-section">
                    <div class="nav-section-title">Mobile App</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/mobile-app/download.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'mobile-app') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-mobile-alt"></i>
                            <span>Mobile App</span>
                        </a>
                    </div>
                </div>
                
                <!-- ACCOUNT -->
                <div class="nav-section">
                    <div class="nav-section-title">Account</div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/residents/profile.php" class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'profile.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-user-circle"></i>
                            <span>Profile</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
    </aside>
    
    <!-- Header -->
    <!-- Header -->
<header class="header">
    <div class="header-left">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
   <div class="header-right">
        <!-- ✅ ADD NOTIFICATION DROPDOWN -->
        <div class="notification-dropdown" style="position: relative; margin-right: 20px;">
            <button class="notification-bell" id="notificationBell" style="background: none; border: none; cursor: pointer; position: relative; padding: 10px;">
                <i class="fas fa-bell" style="font-size: 20px; color: #4a5568;"></i>
                <?php if ($unread_count > 0): ?>
                    <span class="notification-count" style="position: absolute; top: 5px; right: 5px; background: #dc3545; color: white; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: bold;">
                        <?php echo $unread_count > 9 ? '9+' : $unread_count; ?>
                    </span>
                <?php endif; ?>
            </button>
            
            <div class="notification-panel" id="notificationPanel" style="display: none; position: absolute; right: 0; top: 100%; width: 350px; max-height: 400px; overflow-y: auto; background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; margin-top: 5px;">
                <div style="padding: 15px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                    <h6 style="margin: 0; font-weight: 600;">Notifications</h6>
                    <?php if ($unread_count > 0): ?>
                        <span style="background: #edf2f7; padding: 2px 8px; border-radius: 12px; font-size: 12px; color: #4a5568;">
                            <?php echo $unread_count; ?> new
                        </span>
                    <?php endif; ?>
                </div>
                
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php if (!empty($recent_notifications)): ?>
                        <?php foreach ($recent_notifications as $notif): ?>
                          <?php

                              $icon = 'bell';
                                $color = '#6b7280';
                                $link = '#';

                                switch($notif['type']) {
                                    // COMPLAINT CASES
                                    case 'complaint_filed':
                                        $icon = 'file-alt';
                                        $color = '#f59e0b';
                                        $link = $base_url . '/modules/complaints/complaint-details.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'complaint_status_update':
                                        $icon = 'sync-alt';
                                        $color = '#3b82f6';
                                        $link = $base_url . '/modules/complaints/complaint-details.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'complaint_resolved':
                                        $icon = 'check-circle';
                                        $color = '#10b981';
                                        $link = $base_url . '/modules/complaints/complaint-details.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'complaint_closed':
                                        $icon = 'times-circle';
                                        $color = '#6b7280';
                                        $link = $base_url . '/modules/complaints/complaint-details.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'complaint_assignment':
                                        $icon = 'user-shield';
                                        $color = '#8b5cf6';
                                        $link = $base_url . '/modules/complaints/complaint-details.php?id=' . $notif['reference_id'];
                                        break;
                                    
                                    // DOCUMENT REQUEST CASES
                                    case 'document_request_submitted':
                                        $icon = 'file-invoice';
                                        $color = '#3b82f6';
                                        $link = $base_url . '/modules/requests/view-request.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'request_status_update':
                                        $icon = 'check-circle';
                                        $color = '#10b981';
                                        $link = $base_url . '/modules/requests/view-request.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'payment_confirmed':
                                        $icon = 'money-bill-wave';
                                        $color = '#10b981';
                                        $link = $base_url . '/modules/requests/view-request.php?id=' . $notif['reference_id'];
                                        break;
                                    
                                    // INCIDENT CASES
                                    case 'incident_reported':
                                        $icon = 'exclamation-triangle';
                                        $color = '#ef4444';
                                        $link = $base_url . '/modules/incidents/incident-details.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'incident_status_update':
                                        $icon = 'sync-alt';
                                        $color = '#3b82f6';
                                        $link = $base_url . '/modules/incidents/incident-details.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'incident_resolved':
                                        $icon = 'check-circle';
                                        $color = '#10b981';
                                        $link = $base_url . '/modules/incidents/incident-details.php?id=' . $notif['reference_id'];
                                        break;
                                    
                                    // BLOTTER CASES
                                    case 'blotter_filed':
                                        $icon = 'file-alt';
                                        $color = '#f59e0b';
                                        $link = $base_url . '/modules/blotter/view-blotter.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'blotter_status_update':
                                        $icon = 'sync-alt';
                                        $color = '#3b82f6';
                                        $link = $base_url . '/modules/blotter/view-blotter.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'blotter_hearing_scheduled':
                                        $icon = 'gavel';
                                        $color = '#8b5cf6';
                                        $link = $base_url . '/modules/blotter/view-blotter.php?id=' . $notif['reference_id'];
                                        break;
                                    case 'blotter_resolved':
                                        $icon = 'check-circle';
                                        $color = '#10b981';
                                        $link = $base_url . '/modules/blotter/view-blotter.php?id=' . $notif['reference_id'];
                                        break;

                                    // APPOINTMENT CASES
                                    case 'appointment_booked':
                                    case 'appointment_confirmed':
                                        $icon = 'calendar-check';
                                        $color = '#10b981';
                                        $link = ($user_role === 'Resident')
                                            ? $base_url . '/modules/health/book-appointment.php'
                                            : $base_url . '/modules/health/appointments.php';
                                        break;
                                    case 'appointment_cancelled':
                                        $icon = 'calendar-times';
                                        $color = '#ef4444';
                                        $link = ($user_role === 'Resident')
                                            ? $base_url . '/modules/health/book-appointment.php'
                                            : $base_url . '/modules/health/appointments.php';
                                        break;

                                    // MEDICAL ASSISTANCE CASES
                                    case 'medical_assistance_request':
                                    case 'medical_assistance':
                                        $icon = 'hand-holding-medical';
                                        $color = '#6366f1';
                                        $link = ($user_role === 'Resident')
                                            ? $base_url . '/modules/health/request-assistance.php'
                                            : $base_url . '/modules/health/medical-assistance.php';
                                        break;

                                    // ANNOUNCEMENT
                                    case 'announcement':
                                        $icon = 'bullhorn';
                                        $color = '#8b5cf6';
                                        $link = '#';
                                        break;
                                }

                                $bg_color = $notif['is_read'] ? '#ffffff' : '#f0f9ff';
                            // Calculate time ago
                            $time_diff = time() - strtotime($notif['created_at']);
                            if ($time_diff < 60) {
                                $time_ago = "Just now";
                            } elseif ($time_diff < 3600) {
                                $time_ago = floor($time_diff / 60) . " min ago";
                            } elseif ($time_diff < 86400) {
                                $time_ago = floor($time_diff / 3600) . " hr ago";
                            } else {
                                $time_ago = date('M d, h:i A', strtotime($notif['created_at']));
                            }
                            ?>
                            <a href="<?php echo $base_url; ?>/modules/notifications/mark_read_redirect.php?id=<?php echo intval($notif['notification_id']); ?>&redirect=<?php echo urlencode($link); ?>" 
                                style="display: block; padding: 12px 15px; border-bottom: 1px solid #f3f4f6; text-decoration: none; color: inherit; background: <?php echo $bg_color; ?>; transition: background 0.2s;" 
                                onmouseover="this.style.background='#f9fafb'" 
                                onmouseout="this.style.background='<?php echo $bg_color; ?>'">
                                    <div style="display: flex; gap: 12px;">
                                        <div style="flex-shrink: 0;">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo $color; ?>20; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-<?php echo $icon; ?>" style="color: <?php echo $color; ?>; font-size: 16px;"></i>
                                            </div>
                                        </div>
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="font-weight: 600; color: #1f2937; font-size: 14px; margin-bottom: 2px;">
                                                <?php echo htmlspecialchars($notif['title']); ?>
                                            </div>
                                            <div style="color: #6b7280; font-size: 13px; line-height: 1.4; margin-bottom: 4px;">
                                                <?php echo htmlspecialchars(substr($notif['message'], 0, 60)) . (strlen($notif['message']) > 60 ? '...' : ''); ?>
                                            </div>
                                            <div style="color: #9ca3af; font-size: 12px;">
                                                <i class="far fa-clock" style="margin-right: 4px;"></i><?php echo $time_ago; ?>
                                            </div>
                                        </div>
                                        <?php if (!$notif['is_read']): ?>
                                            <div style="flex-shrink: 0;">
                                                <div style="width: 8px; height: 8px; background: #3b82f6; border-radius: 50%;"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 40px 20px; text-align: center; color: #9ca3af;">
                            <i class="far fa-bell-slash" style="font-size: 40px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <p style="margin: 0;">No notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($recent_notifications)): ?>
                <div style="padding: 10px 15px; border-top: 1px solid #e2e8f0; text-align: center;">
                    <a href="<?php echo $base_url; ?>/modules/notifications/index.php" style="color: #3b82f6; text-decoration: none; font-size: 13px; font-weight: 500;">
                        View All Notifications
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="user-profile">
            <div class="user-avatar">
                <?php if (!empty($user_profile_photo)): ?>
                    <img src="<?php echo $base_url; ?>/uploads/profiles/<?php echo htmlspecialchars($user_profile_photo); ?>" 
                         alt="Profile Photo" 
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo $user_initials; ?>';">
                <?php else: ?>
                    <?php echo $user_initials; ?>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user_role); ?></div>
            </div>
        </div>
    </div>
</header>


<script>
// Bell notification toggle - Fixed version
document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notificationBell');
    const panel = document.getElementById('notificationPanel');

    if (bell && panel) {
        let isOpen = false;

        // Click on bell to toggle
        bell.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            isOpen = !isOpen;
            
            if (isOpen) {
                panel.style.display = 'block';
            } else {
                panel.style.display = 'none';
            }
            
            console.log('Panel is now:', isOpen ? 'open' : 'closed');
        });

        // Close when clicking outside
        document.addEventListener('click', function(e) {
            // Only close if clicking outside both bell and panel AND panel is open
            if (isOpen && !bell.contains(e.target) && !panel.contains(e.target)) {
                isOpen = false;
                panel.style.display = 'none';
                console.log('Panel closed by outside click');
            }
        });

        // Prevent panel clicks from bubbling up
        panel.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    } else {
        console.error('Bell or panel not found!');
    }
});
</script>

    <!-- Main Content -->
    <main class="main-content">