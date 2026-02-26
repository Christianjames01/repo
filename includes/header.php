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

$is_tanod = in_array($user_role, ['Tanod', 'Barangay Tanod']);
$is_driver = ($user_role === 'Driver');

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

$base_url = '/barangaylink1';

// Helper: detect if any link in a section is active based on path keywords
// Used to auto-expand sections on page load if a child is active
$current_path = $_SERVER['PHP_SELF'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Barangay Management</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $base_url; ?>/assets/css/sidebar-layout.css">

    <style>
        /* ── Avatar ── */
        .user-avatar {
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 16px;
            overflow: hidden;
            background: #2d3748; color: white;
        }
        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        /* ── Sidebar logo ── */
        .sidebar-logo {
            width: 40px; height: 40px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: white; padding: 4px;
        }
        .sidebar-logo img { width: 100%; height: 100%; object-fit: contain; }

        /* ── Notification badge inside nav ── */
        .nav-link .notification-badge {
            position: absolute; right: 15px; top: 50%;
            transform: translateY(-50%);
            background: #dc3545; color: white;
            border-radius: 10px; padding: 2px 6px;
            font-size: 0.7rem; font-weight: bold;
            min-width: 18px; text-align: center;
        }
        .nav-item { position: relative; }

        /* ══════════════════════════════════════
           COLLAPSIBLE NAV SECTIONS
        ══════════════════════════════════════ */

        .nav-section {
            margin-bottom: 4px;
        }

        /* Hide old static nav-section-title divs that aren't inside a toggle */
        .sidebar .nav-section > .nav-section-title {
            display: none !important;
        }

        /* The clickable section header — LIGHT sidebar (Resident) */
        .sidebar .nav-section-toggle {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding: 8px 12px !important;
            margin: 3px 8px !important;
            cursor: pointer !important;
            user-select: none !important;
            border-radius: 7px !important;
            transition: background 0.2s !important;
            background: rgba(0,0,0,0.05) !important;
            border: 1px solid rgba(0,0,0,0.08) !important;
            text-decoration: none !important;
        }
        .sidebar .nav-section-toggle:hover {
            background: rgba(0,0,0,0.09) !important;
            border-color: rgba(0,0,0,0.15) !important;
        }
        .sidebar .nav-section.open > .nav-section-toggle {
            background: rgba(0,0,0,0.08) !important;
            border-color: rgba(0,0,0,0.15) !important;
        }
        .sidebar .nav-section-toggle .nav-section-title {
            margin: 0 !important;
            padding: 0 !important;
            font-size: 0.7rem !important;
            font-weight: 700 !important;
            letter-spacing: 0.07em !important;
            text-transform: uppercase !important;
            color: #374151 !important;
            opacity: 1 !important;
            background: none !important;
            border: none !important;
            line-height: 1 !important;
        }
        .sidebar .nav-section-toggle .toggle-chevron {
            font-size: 0.65rem !important;
            color: #6b7280 !important;
            opacity: 1 !important;
            transition: transform 0.25s ease !important;
            flex-shrink: 0 !important;
        }
        .sidebar .nav-section.open > .nav-section-toggle .toggle-chevron {
            transform: rotate(180deg) !important;
            color: #374151 !important;
        }

        /* ── DARK sidebar overrides (Admin / Super Admin / Staff / Tanod / Driver) ── */
        .sidebar-dark .nav-section-toggle {
            background: rgba(255,255,255,0.07) !important;
            border: 1px solid rgba(255,255,255,0.12) !important;
        }
        .sidebar-dark .nav-section-toggle:hover {
            background: rgba(255,255,255,0.13) !important;
            border-color: rgba(255,255,255,0.2) !important;
        }
        .sidebar-dark .nav-section.open > .nav-section-toggle {
            background: rgba(255,255,255,0.12) !important;
            border-color: rgba(255,255,255,0.22) !important;
        }
        .sidebar-dark .nav-section-toggle .nav-section-title,
        .sidebar-dark .nav-section-toggle span.nav-section-title {
            color: #ffffff !important;
            opacity: 1 !important;
        }
        .sidebar-dark .nav-section-toggle .toggle-chevron,
        .sidebar-dark .nav-section-toggle i.toggle-chevron {
            color: #ffffff !important;
            opacity: 0.8 !important;
        }
        .sidebar-dark .nav-section.open > .nav-section-toggle .toggle-chevron {
            color: #ffffff !important;
            opacity: 1 !important;
        }

        /* Collapsible body */
        .sidebar .nav-section-body {
            overflow: hidden !important;
            max-height: 0 !important;
            transition: max-height 0.32s ease, opacity 0.25s ease !important;
            opacity: 0 !important;
        }
        .sidebar .nav-section.open > .nav-section-body {
            max-height: 2000px !important;
            opacity: 1 !important;
        }

        /* Non-collapsible standalone items */
        .nav-item-standalone {
            padding: 0 8px;
        }

        /* ══════════════════════════════════════
           BELL BADGE — real-time polling styles
        ══════════════════════════════════════ */
        /* The count badge on the header bell */
        #bell-badge {
            position: absolute;
            top: 5px; right: 5px;
            background: #dc3545; color: white;
            border-radius: 50%;
            min-width: 18px; height: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: bold;
            pointer-events: none;
            line-height: 1;
            padding: 0 3px;
        }
        /* Same badge on nav-sidebar Notifications link */
        #sidebar-notif-badge {
            position: absolute; right: 15px; top: 50%;
            transform: translateY(-50%);
            background: #dc3545; color: white;
            border-radius: 10px; padding: 2px 6px;
            font-size: 0.7rem; font-weight: bold;
            min-width: 18px; text-align: center;
            display: none;
        }
        @keyframes bellShake {
            0%,100%{ transform:rotate(0) }
            20%    { transform:rotate(-18deg) }
            40%    { transform:rotate(18deg) }
            60%    { transform:rotate(-10deg) }
            80%    { transform:rotate(10deg) }
        }
        @keyframes badgePop {
            0%,100%{ transform:scale(1) }
            50%    { transform:scale(1.4) }
        }
        .bell-shake { animation: bellShake .65s ease; }
        .badge-pop  { animation: badgePop  .35s ease; }

        /* Toast container */
        #notif-toast-wrap {
            position: fixed;
            top: 68px; right: 18px;
            z-index: 99999;
            display: flex; flex-direction: column; gap: 8px;
            pointer-events: none;
        }
        .notif-toast {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 28px rgba(0,0,0,.16);
            border-left: 4px solid #3182ce;
            padding: 12px 16px;
            min-width: 270px; max-width: 340px;
            display: flex; align-items: flex-start; gap: 10px;
            pointer-events: all; cursor: pointer;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            animation: toastIn .22s ease;
        }
        @keyframes toastIn {
            from { opacity:0; transform:translateX(24px) }
            to   { opacity:1; transform:translateX(0) }
        }
        .notif-toast-icon { font-size: 1.25rem; flex-shrink: 0; line-height: 1.3; }
        .notif-toast-body { flex: 1; min-width: 0; }
        .notif-toast-title { font-size: 13px; font-weight: 700; color: #1a202c; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-toast-msg   { font-size: 12px; color: #718096; line-height: 1.45; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .notif-toast-close { background: none; border: none; cursor: pointer; color: #a0aec0; font-size: 16px; line-height: 1; padding: 0; flex-shrink: 0; margin-top: -1px; }
        .notif-toast-close:hover { color: #4a5568; }
    </style>

    <?php if (isset($extra_css)): ?>
        <?php echo $extra_css; ?>
    <?php endif; ?>
</head>
<body class="sidebar-open">

<!-- ════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════ -->
<aside class="sidebar <?php echo ($user_role !== 'Resident') ? 'sidebar-dark' : 'sidebar-light'; ?>">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="<?php echo $base_url; ?>/uploads/officials/brgy.png" alt="<?php echo BARANGAY_NAME; ?>">
        </div>
        <span class="sidebar-title">Brgy Centro</span>
    </div>

    <nav class="sidebar-nav">

    <?php
    /* ─────────────────────────────────────────────────────
       Helper: open a collapsible nav section.
       $id         – unique id used by JS
       $title      – label text
       $keywords   – array of path substrings; section
                     auto-opens when current page matches any
    ───────────────────────────────────────────────────── */
    function navSectionStart($id, $title, $keywords = []) {
        global $current_path, $user_role;
        $is_open = false;
        foreach ($keywords as $kw) {
            if (strpos($current_path, $kw) !== false) { $is_open = true; break; }
        }
        $open_class = $is_open ? ' open' : '';
        $is_dark = ($user_role !== 'Resident');
        $title_color  = $is_dark ? 'color:#ffffff;opacity:1;' : 'color:#374151;opacity:1;';
        $chevron_color = $is_dark ? 'color:#ffffff;opacity:0.85;' : 'color:#6b7280;opacity:1;';
        $btn_bg   = $is_dark ? 'background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.15);' : 'background:rgba(0,0,0,0.05);border:1px solid rgba(0,0,0,0.1);';

        echo '<div class="nav-section' . $open_class . '" data-section="' . htmlspecialchars($id) . '">';
        echo '  <div class="nav-section-toggle" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;margin:3px 8px;cursor:pointer;border-radius:7px;' . $btn_bg . '">';
        echo '    <span class="nav-section-title" style="font-size:0.7rem;font-weight:700;letter-spacing:0.07em;text-transform:uppercase;' . $title_color . '">' . htmlspecialchars($title) . '</span>';
        echo '    <i class="fas fa-chevron-down toggle-chevron" style="font-size:0.65rem;transition:transform 0.25s ease;' . $chevron_color . '"></i>';
        echo '  </div>';
        echo '  <div class="nav-section-body">';
    }
    function navSectionEnd() {
        echo '  </div>'; // .nav-section-body
        echo '</div>';   // .nav-section
    }
    ?>

    <?php if ($is_driver): ?>
        <!-- ════ DRIVER ════ -->
        <?php navSectionStart('driver-main', 'Main', ['dashboard', 'driver/index']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/index.php" class="nav-link <?php echo (basename($current_path)=='index.php' && strpos($current_path,'driver')!==false)?'active':''; ?>">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/calendar.php" class="nav-link <?php echo basename($current_path)=='calendar.php'?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Calendar</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/officials/index.php" class="nav-link <?php echo strpos($current_path,'officials')!==false?'active':''; ?>">
                    <i class="fas fa-users-cog"></i><span>Officials</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/attendance/my-schedule.php" class="nav-link <?php echo strpos($current_path,'attendance')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-check"></i><span>Attendance</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('driver-vehicle', 'Vehicle Management', ['vehicles/driver','my-vehicle','trip-log','fuel-records','maintenance','vehicle-reports']); ?>
            <div class="nav-item">
                <?php if ($driver_vehicle_id): ?>
                    <a href="<?php echo $base_url; ?>/vehicles/driver/my-vehicle.php?id=<?php echo $driver_vehicle_id; ?>" class="nav-link <?php echo basename($current_path)=='my-vehicle.php'?'active':''; ?>">
                        <i class="fas fa-car"></i><span>My Vehicle</span>
                    </a>
                <?php else: ?>
                    <a href="#" class="nav-link disabled" title="No vehicle assigned">
                        <i class="fas fa-car"></i><span>My Vehicle</span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/trip-log.php" class="nav-link <?php echo basename($current_path)=='trip-log.php'?'active':''; ?>">
                    <i class="fas fa-route"></i><span>Trip Logs</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/fuel-records.php" class="nav-link <?php echo basename($current_path)=='fuel-records.php'?'active':''; ?>">
                    <i class="fas fa-gas-pump"></i><span>Fuel Records</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/maintenance.php" class="nav-link <?php echo basename($current_path)=='maintenance.php'?'active':''; ?>">
                    <i class="fas fa-wrench"></i><span>Maintenance</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/vehicle-reports.php" class="nav-link <?php echo basename($current_path)=='vehicle-reports.php'?'active':''; ?>">
                    <i class="fas fa-chart-bar"></i><span>Reports</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('driver-account', 'Account', ['driver/profile']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/driver/profile.php" class="nav-link <?php echo (basename($current_path)=='profile.php'&&strpos($current_path,'driver')!==false)?'active':''; ?>">
                    <i class="fas fa-user-circle"></i><span>My Profile</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

    <?php elseif ($is_tanod): ?>
        <!-- ════ TANOD ════ -->
        <?php navSectionStart('tanod-main', 'Main', ['dashboard','calendar','officials','attendance']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/dashboard/index.php" class="nav-link <?php echo (basename($current_path)=='index.php'&&strpos($current_path,'dashboard')!==false)?'active':''; ?>">
                    <i class="fas fa-th-large"></i><span>Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/calendar/index.php" class="nav-link <?php echo strpos($current_path,'calendar')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Calendar</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/officials/index.php" class="nav-link <?php echo strpos($current_path,'officials')!==false?'active':''; ?>">
                    <i class="fas fa-users-cog"></i><span>Officials</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/attendance/my-schedule.php" class="nav-link <?php echo strpos($current_path,'attendance')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-check"></i><span>Attendance</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('tanod-services', 'Services', ['incidents','notifications']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/incidents/view-incidents.php" class="nav-link <?php echo strpos($current_path,'incidents')!==false?'active':''; ?>">
                    <i class="fas fa-exclamation-circle"></i><span>Incidents</span>
                </a>
            </div>
            <div class="nav-item" style="position:relative;">
                <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="nav-link <?php echo strpos($current_path,'notifications')!==false?'active':''; ?>">
                    <i class="fas fa-bell"></i><span>Notifications</span>
                    <!-- sidebar badge — updated by JS poller -->
                    <span id="sidebar-notif-badge" class="notification-badge"><?php echo $unread_count > 0 ? ($unread_count > 9 ? '9+' : $unread_count) : ''; ?></span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('tanod-account', 'Account', ['profile']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/residents/profile.php" class="nav-link">
                    <i class="fas fa-user-circle"></i><span>Profile</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

    <?php elseif ($user_role === 'Resident'): ?>
        <!-- ════ RESIDENT — collapsible sections, light theme ════ -->

        <?php navSectionStart('res-main', 'Main', ['dashboard','calendar','officials']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/dashboard/index.php" class="nav-link <?php echo basename($current_path)=='index.php'&&strpos($current_path,'dashboard')!==false?'active':''; ?>">
                    <i class="fas fa-th-large"></i><span>Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/calendar/index.php" class="nav-link <?php echo strpos($current_path,'/calendar/')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Calendar</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/officials/index.php" class="nav-link <?php echo strpos($current_path,'officials')!==false?'active':''; ?>">
                    <i class="fas fa-users-cog"></i><span>Officials</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-services', 'Services', ['incidents','complaints','blotter','requests','notifications']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/incidents/view-incidents.php" class="nav-link <?php echo strpos($current_path,'incidents')!==false&&strpos($current_path,'disasters')===false?'active':''; ?>">
                    <i class="fas fa-exclamation-triangle"></i><span>Incidents</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/complaints/view-complaints.php" class="nav-link <?php echo strpos($current_path,'complaints')!==false?'active':''; ?>">
                    <i class="fas fa-comments"></i><span>Complaints</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/blotter/my-blotter.php" class="nav-link <?php echo strpos($current_path,'blotter')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-list"></i><span>Blotter</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/requests/my-requests.php" class="nav-link <?php echo strpos($current_path,'requests')!==false?'active':''; ?>">
                    <i class="fas fa-file-alt"></i><span>My Requests</span>
                </a>
            </div>
            <div class="nav-item" style="position:relative;">
                <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="nav-link <?php echo strpos($current_path,'notifications')!==false?'active':''; ?>">
                    <i class="fas fa-bell"></i><span>Notifications</span>
                    <!-- sidebar badge — updated by JS poller -->
                    <span id="sidebar-notif-badge" class="notification-badge"><?php echo $unread_count > 0 ? ($unread_count > 9 ? '9+' : $unread_count) : ''; ?></span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-health', 'Health Services', ['health']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/my-health.php" class="nav-link <?php echo basename($current_path)=='my-health.php'?'active':''; ?>">
                    <i class="fas fa-heartbeat"></i><span>My Health Record</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/my-vaccinations.php" class="nav-link <?php echo basename($current_path)=='my-vaccinations.php'?'active':''; ?>">
                    <i class="fas fa-syringe"></i><span>My Vaccinations</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/book-appointment.php" class="nav-link <?php echo basename($current_path)=='book-appointment.php'?'active':''; ?>">
                    <i class="fas fa-calendar-plus"></i><span>Book Appointment</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/request-assistance.php" class="nav-link <?php echo basename($current_path)=='request-assistance.php'&&strpos($current_path,'health')!==false?'active':''; ?>">
                    <i class="fas fa-hand-holding-medical"></i><span>Request Assistance</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-education', 'Education', ['education']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/resident/apply-scholarship.php" class="nav-link <?php echo basename($current_path)=='apply-scholarship.php'?'active':''; ?>">
                    <i class="fas fa-file-alt"></i><span>Apply for Scholarship</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/resident/assistance-requests.php" class="nav-link <?php echo basename($current_path)=='assistance-requests.php'&&strpos($current_path,'education')!==false?'active':''; ?>">
                    <i class="fas fa-hand-holding-usd"></i><span>Assistance Requests</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/resident/my-documents.php" class="nav-link <?php echo basename($current_path)=='my-documents.php'?'active':''; ?>">
                    <i class="fas fa-folder-open"></i><span>My Documents</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/resident/request-assistance.php" class="nav-link <?php echo basename($current_path)=='request-assistance.php'&&strpos($current_path,'education')!==false?'active':''; ?>">
                    <i class="fas fa-hand-holding-medical"></i><span>Request Assistance</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/resident/scholarship-guide.php" class="nav-link <?php echo basename($current_path)=='scholarship-guide.php'?'active':''; ?>">
                    <i class="fas fa-book-open"></i><span>Scholarship Guide</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/resident/student-portal.php" class="nav-link <?php echo basename($current_path)=='student-portal.php'?'active':''; ?>">
                    <i class="fas fa-graduation-cap"></i><span>Student Portal</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-business', 'Business', ['business/resident']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/resident/my-permits.php" class="nav-link <?php echo strpos($current_path,'business/resident')!==false&&strpos($current_path,'my-permits')!==false?'active':''; ?>">
                    <i class="fas fa-briefcase"></i><span>My Business Permits</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/resident/apply-permit.php" class="nav-link <?php echo basename($current_path)=='apply-permit.php'&&strpos($current_path,'business')!==false?'active':''; ?>">
                    <i class="fas fa-plus-circle"></i><span>Apply for Permit</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/resident/renewal.php" class="nav-link <?php echo basename($current_path)=='renewal.php'&&strpos($current_path,'business')!==false?'active':''; ?>">
                    <i class="fas fa-sync-alt"></i><span>Permit Renewal</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-jobs', 'Jobs & Livelihood', ['jobboard']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/resident/jobs.php" class="nav-link <?php echo basename($current_path)=='jobs.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-search"></i><span>Browse Jobs</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/resident/my-applications.php" class="nav-link <?php echo basename($current_path)=='my-applications.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-list"></i><span>My Job Applications</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/resident/trainings.php" class="nav-link <?php echo basename($current_path)=='trainings.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i><span>Skills Training</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/resident/livelihood.php" class="nav-link <?php echo basename($current_path)=='livelihood.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-hands-helping"></i><span>Livelihood Programs</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-waste', 'Waste Management', ['waste']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/resident/schedule.php" class="nav-link <?php echo basename($current_path)=='schedule.php'&&strpos($current_path,'waste')!==false?'active':''; ?>">
                    <i class="fas fa-trash-alt"></i><span>Collection Schedule</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/resident/recycling.php" class="nav-link <?php echo basename($current_path)=='recycling.php'&&strpos($current_path,'waste')!==false?'active':''; ?>">
                    <i class="fas fa-recycle"></i><span>Recycling Programs</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/resident/report-issue.php" class="nav-link <?php echo basename($current_path)=='report-issue.php'&&strpos($current_path,'waste')!==false?'active':''; ?>">
                    <i class="fas fa-exclamation-circle"></i><span>Report Waste Issue</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-disaster', 'Disaster Assistance', ['disasters/resident','typhoon_tracker']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/resident/index.php" class="nav-link <?php echo basename($current_path)=='index.php'&&strpos($current_path,'disasters/resident')!==false?'active':''; ?>">
                    <i class="fas fa-shield-alt"></i><span>Disaster Info</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/typhoon_tracker/index.php" class="nav-link <?php echo strpos($current_path,'typhoon_tracker')!==false?'active':''; ?>">
                    <i class="fas fa-hurricane"></i><span>Typhoon Tracker</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-community', 'Community', ['community']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/forum.php" class="nav-link <?php echo strpos($current_path,'community/forum')!==false?'active':''; ?>">
                    <i class="fas fa-comments"></i><span>Community Board</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/polls.php" class="nav-link <?php echo strpos($current_path,'community/polls')!==false?'active':''; ?>">
                    <i class="fas fa-poll"></i><span>Polls &amp; Surveys</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/events.php" class="nav-link <?php echo strpos($current_path,'community/events')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Events</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/announcements.php" class="nav-link <?php echo strpos($current_path,'community/announcements')!==false?'active':''; ?>">
                    <i class="fas fa-bullhorn"></i><span>Announcements</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('res-account', 'Account', ['profile']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/residents/profile.php" class="nav-link <?php echo strpos($current_path,'profile.php')!==false?'active':''; ?>">
                    <i class="fas fa-user-circle"></i><span>Profile</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

    <?php else: ?>
        <!-- ════ ADMIN / STAFF / SUPER ADMIN ════ -->
        <?php navSectionStart('adm-main', 'Main', ['dashboard','calendar','officials','attendance']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/dashboard/index.php" class="nav-link <?php echo basename($current_path)=='index.php'&&strpos($current_path,'dashboard')!==false?'active':''; ?>">
                    <i class="fas fa-th-large"></i><span>Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/calendar/index.php" class="nav-link <?php echo strpos($current_path,'calendar')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Calendar</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/officials/manage.php" class="nav-link <?php echo strpos($current_path,'officials')!==false?'active':''; ?>">
                    <i class="fas fa-users-cog"></i><span>Officials</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/attendance/admin/index.php" class="nav-link <?php echo strpos($current_path,'attendance')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-check"></i><span>Attendance</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-management', 'Management', ['residents','incidents','complaints','blotter','requests/admin','staff/manage','notifications','qrcodes','seniors','vehicles/admin']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/residents/manage.php" class="nav-link <?php echo basename($current_path)=='manage.php'&&strpos($current_path,'residents')!==false?'active':''; ?>">
                    <i class="fas fa-users"></i><span>Manage Residents</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/incidents/manage-incidents.php" class="nav-link <?php echo strpos($current_path,'incidents')!==false&&strpos($current_path,'disasters')===false?'active':''; ?>">
                    <i class="fas fa-exclamation-circle"></i><span>Manage Incidents</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/complaints/view-complaints.php" class="nav-link <?php echo strpos($current_path,'complaints')!==false?'active':''; ?>">
                    <i class="fas fa-comment-dots"></i><span>Manage Complaints</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/blotter/manage-blotter.php" class="nav-link <?php echo strpos($current_path,'blotter')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-list"></i><span>Manage Blotter Records</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/requests/admin-manage-requests.php" class="nav-link <?php echo strpos($current_path,'requests/admin')!==false?'active':''; ?>">
                    <i class="fas fa-file-invoice"></i><span>Manage Documents</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/staff/manage.php" class="nav-link <?php echo strpos($current_path,'staff/manage')!==false?'active':''; ?>">
                    <i class="fas fa-user-tie"></i><span>Manage Staff</span>
                </a>
            </div>
            <!-- ★ Notifications link with live-updating sidebar badge ★ -->
            <div class="nav-item" style="position:relative;">
                <a href="<?php echo $base_url; ?>/modules/notifications/index.php" class="nav-link <?php echo strpos($current_path,'notifications')!==false?'active':''; ?>">
                    <i class="fas fa-bell"></i><span>Notifications</span>
                    <span id="sidebar-notif-badge" class="notification-badge"
                          style="display:<?php echo $unread_count > 0 ? 'inline-block' : 'none'; ?>;">
                        <?php echo $unread_count > 9 ? '9+' : $unread_count; ?>
                    </span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/qrcodes/index.php" class="nav-link <?php echo strpos($current_path,'qrcodes')!==false?'active':''; ?>">
                    <i class="fas fa-id-card"></i><span>ID Management</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/seniors/manage.php" class="nav-link <?php echo strpos($current_path,'seniors/manage')!==false?'active':''; ?>">
                    <i class="fas fa-user-friends"></i><span>Senior Citizens</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/vehicles/admin/index.php" class="nav-link <?php echo strpos($current_path,'vehicles/admin')!==false?'active':''; ?>">
                    <i class="fas fa-car"></i><span>Vehicles</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-education', 'Education Assistance', ['education']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/index.php" class="nav-link <?php echo basename($current_path)=='index.php'&&strpos($current_path,'education')!==false?'active':''; ?>">
                    <i class="fas fa-graduation-cap"></i><span>Education Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/add-student.php" class="nav-link <?php echo basename($current_path)=='add-student.php'?'active':''; ?>">
                    <i class="fas fa-user-plus"></i><span>Add Student</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/manage-students.php" class="nav-link <?php echo basename($current_path)=='manage-students.php'&&strpos($current_path,'education')!==false?'active':''; ?>">
                    <i class="fas fa-user-graduate"></i><span>Manage Students</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/scholarships.php" class="nav-link <?php echo basename($current_path)=='scholarships.php'&&strpos($current_path,'education')!==false?'active':''; ?>">
                    <i class="fas fa-award"></i><span>Scholarships</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/student-records.php" class="nav-link <?php echo basename($current_path)=='student-records.php'?'active':''; ?>">
                    <i class="fas fa-notes-medical"></i><span>Student Records</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/view-application.php" class="nav-link <?php echo basename($current_path)=='view-application.php'?'active':''; ?>">
                    <i class="fas fa-clipboard-check"></i><span>View Applications</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/view-student.php" class="nav-link <?php echo basename($current_path)=='view-student.php'?'active':''; ?>">
                    <i class="fas fa-eye"></i><span>View Student</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/education/reports.php" class="nav-link <?php echo basename($current_path)=='reports.php'&&strpos($current_path,'education')!==false?'active':''; ?>">
                    <i class="fas fa-chart-line"></i><span>Education Reports</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-waste', 'Waste Management', ['waste_management/admin']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/admin/dashboard.php" class="nav-link <?php echo basename($current_path)=='dashboard.php'&&strpos($current_path,'waste_management/admin')!==false?'active':''; ?>">
                    <i class="fas fa-trash-alt"></i><span>Waste Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/admin/programs.php" class="nav-link <?php echo basename($current_path)=='programs.php'&&strpos($current_path,'waste_management/admin')!==false?'active':''; ?>">
                    <i class="fas fa-recycle"></i><span>Programs</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/admin/reports-issues.php" class="nav-link <?php echo basename($current_path)=='reports-issues.php'&&strpos($current_path,'waste_management/admin')!==false?'active':''; ?>">
                    <i class="fas fa-exclamation-circle"></i><span>Reported Issues</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/admin/recycling.php" class="nav-link <?php echo basename($current_path)=='recycling.php'&&strpos($current_path,'waste_management/admin')!==false?'active':''; ?>">
                    <i class="fas fa-leaf"></i><span>Recycling</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/waste_management/admin/schedules.php" class="nav-link <?php echo basename($current_path)=='schedules.php'&&strpos($current_path,'waste_management/admin')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-alt"></i><span>Schedules</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-business', 'Business Permits', ['business/admin','business']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/admin/dashboard.php" class="nav-link <?php echo basename($current_path)=='dashboard.php'&&strpos($current_path,'business')!==false?'active':''; ?>">
                    <i class="fas fa-briefcase"></i><span>Business Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/admin/applications.php" class="nav-link <?php echo basename($current_path)=='applications.php'&&strpos($current_path,'business')!==false?'active':''; ?>">
                    <i class="fas fa-file-invoice"></i><span>Permit Applications</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/admin/renewals.php" class="nav-link <?php echo basename($current_path)=='renewals.php'&&strpos($current_path,'business')!==false?'active':''; ?>">
                    <i class="fas fa-sync-alt"></i><span>Renewals</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/business/admin/registry.php" class="nav-link <?php echo basename($current_path)=='registry.php'&&strpos($current_path,'business')!==false?'active':''; ?>">
                    <i class="fas fa-building"></i><span>Business Registry</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-jobs', 'Jobs & Livelihood', ['jobboard']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/admin/dashboard.php" class="nav-link <?php echo basename($current_path)=='dashboard.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-th-large"></i><span>Job Board Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/admin/manage-jobs.php" class="nav-link <?php echo basename($current_path)=='manage-jobs.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-briefcase"></i><span>Manage Job Posts</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/admin/applications.php" class="nav-link <?php echo basename($current_path)=='applications.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-clipboard-list"></i><span>Job Applications</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/admin/trainings.php" class="nav-link <?php echo basename($current_path)=='trainings.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-chalkboard-teacher"></i><span>Skills Training</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/jobboard/admin/livelihood.php" class="nav-link <?php echo basename($current_path)=='livelihood.php'&&strpos($current_path,'jobboard')!==false?'active':''; ?>">
                    <i class="fas fa-hands-helping"></i><span>Livelihood Programs</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php if (in_array($user_role, ['Super Admin', 'Staff'])): ?>
        <?php navSectionStart('adm-inventory', 'Inventory', ['relief']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/relief/inventory.php" class="nav-link <?php echo basename($current_path)=='inventory.php'&&strpos($current_path,'relief')!==false?'active':''; ?>">
                    <i class="fas fa-boxes"></i><span>Relief Inventory</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/relief/distribution-report.php" class="nav-link <?php echo basename($current_path)=='distribution-report.php'?'active':''; ?>">
                    <i class="fas fa-chart-bar"></i><span>Distribution Reports</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-financial', 'Financial', ['financial']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/financial/index.php" class="nav-link <?php echo basename($current_path)=='index.php'&&strpos($current_path,'financial')!==false?'active':''; ?>">
                    <i class="fas fa-money-bill-wave"></i><span>Financial Overview</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/financial/budget.php" class="nav-link <?php echo basename($current_path)=='budget.php'&&strpos($current_path,'financial')!==false?'active':''; ?>">
                    <i class="fas fa-wallet"></i><span>Budget Management</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/financial/expenses.php" class="nav-link <?php echo basename($current_path)=='expenses.php'&&strpos($current_path,'financial')!==false?'active':''; ?>">
                    <i class="fas fa-receipt"></i><span>Expenses</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/financial/revenues.php" class="nav-link <?php echo basename($current_path)=='revenues.php'&&strpos($current_path,'financial')!==false?'active':''; ?>">
                    <i class="fas fa-coins"></i><span>Revenue</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/financial/transactions.php" class="nav-link <?php echo basename($current_path)=='transactions.php'&&strpos($current_path,'financial')!==false?'active':''; ?>">
                    <i class="fas fa-exchange-alt"></i><span>Transactions</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>
        <?php endif; ?>

        <?php navSectionStart('adm-disaster', 'Disaster', ['disasters']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/damage-assessment.php" class="nav-link <?php echo basename($current_path)=='damage-assessment.php'?'active':''; ?>">
                    <i class="fas fa-house-damage"></i><span>Damage Assessment</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/disaster-incidents.php" class="nav-link <?php echo basename($current_path)=='disaster-incidents.php'?'active':''; ?>">
                    <i class="fas fa-exclamation-triangle"></i><span>Disaster Incidents</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/disaster-reports.php" class="nav-link <?php echo basename($current_path)=='disaster-reports.php'?'active':''; ?>">
                    <i class="fas fa-file-alt"></i><span>Disaster Reports</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/distribute-relief.php" class="nav-link <?php echo basename($current_path)=='distribute-relief.php'?'active':''; ?>">
                    <i class="fas fa-hands-helping"></i><span>Distribute Relief</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/evacuation-centers.php" class="nav-link <?php echo basename($current_path)=='evacuation-centers.php'?'active':''; ?>">
                    <i class="fas fa-home"></i><span>Evacuation Centers</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/disasters/evacuee-registration.php" class="nav-link <?php echo basename($current_path)=='evacuee-registration.php'?'active':''; ?>">
                    <i class="fas fa-user-plus"></i><span>Evacuee Registration</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <!-- Standalone: Typhoon Tracker -->
        <div class="nav-item nav-item-standalone">
            <a href="<?php echo $base_url; ?>/modules/typhoon_tracker/index.php" class="nav-link <?php echo strpos($current_path,'typhoon_tracker')!==false?'active':''; ?>">
                <i class="fas fa-hurricane"></i><span>Typhoon Tracker</span>
            </a>
        </div>

        <?php navSectionStart('adm-community', 'Community', ['community/admin']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/admin/forum-manage.php" class="nav-link <?php echo strpos($current_path,'community/admin/forum')!==false?'active':''; ?>">
                    <i class="fas fa-comments"></i><span>Manage Forum</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/admin/polls-manage.php" class="nav-link <?php echo strpos($current_path,'community/admin/polls')!==false?'active':''; ?>">
                    <i class="fas fa-poll-h"></i><span>Manage Polls</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/admin/events-manage.php" class="nav-link <?php echo strpos($current_path,'community/admin/events')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-check"></i><span>Manage Events</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/community/admin/announcements-manage.php" class="nav-link <?php echo strpos($current_path,'community/admin/announcements')!==false?'active':''; ?>">
                    <i class="fas fa-megaphone"></i><span>Post Announcements</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php if ($user_role === 'Super Admin'): ?>
        <?php navSectionStart('adm-4ps', '4Ps Program', ['4ps']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/4ps/registration.php" class="nav-link <?php echo basename($current_path)=='registration.php'&&strpos($current_path,'4ps')!==false?'active':''; ?>">
                    <i class="fas fa-user-plus"></i><span>4Ps Registration</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/4ps/beneficiaries-debug.php" class="nav-link <?php echo basename($current_path)=='beneficiaries-debug.php'&&strpos($current_path,'4ps')!==false?'active':''; ?>">
                    <i class="fas fa-users"></i><span>Manage Beneficiaries</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/4ps/reports.php" class="nav-link <?php echo basename($current_path)=='reports.php'&&strpos($current_path,'4ps')!==false?'active':''; ?>">
                    <i class="fas fa-chart-line"></i><span>4Ps Reports</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>
        <?php endif; ?>

        <?php if (in_array($user_role, ['Super Admin', 'Staff', 'Admin'])): ?>
        <?php navSectionStart('adm-health', 'Health Services', ['health']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/dashboard.php" class="nav-link <?php echo basename($current_path)=='dashboard.php'&&strpos($current_path,'health')!==false?'active':''; ?>">
                    <i class="fas fa-heartbeat"></i><span>Health Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/records.php" class="nav-link <?php echo basename($current_path)=='records.php'&&strpos($current_path,'health')!==false?'active':''; ?>">
                    <i class="fas fa-notes-medical"></i><span>Health Records</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/vaccinations.php" class="nav-link <?php echo basename($current_path)=='vaccinations.php'&&strpos($current_path,'health')!==false?'active':''; ?>">
                    <i class="fas fa-syringe"></i><span>Vaccinations</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/appointments.php" class="nav-link <?php echo basename($current_path)=='appointments.php'&&strpos($current_path,'health')!==false?'active':''; ?>">
                    <i class="fas fa-calendar-check"></i><span>Appointments</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/medical-assistance.php" class="nav-link <?php echo basename($current_path)=='medical-assistance.php'?'active':''; ?>">
                    <i class="fas fa-hand-holding-medical"></i><span>Medical Assistance</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/health/disease-surveillance.php" class="nav-link <?php echo basename($current_path)=='disease-surveillance.php'?'active':''; ?>">
                    <i class="fas fa-virus"></i><span>Disease Surveillance</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>
        <?php endif; ?>

        <?php navSectionStart('adm-mobile', 'Mobile App', ['mobile-app']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/mobile-app/download.php" class="nav-link <?php echo strpos($current_path,'mobile-app')!==false?'active':''; ?>">
                    <i class="fas fa-mobile-alt"></i><span>Mobile App</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

        <?php navSectionStart('adm-account', 'Account', ['profile']); ?>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/residents/profile.php" class="nav-link <?php echo strpos($current_path,'profile.php')!==false?'active':''; ?>">
                    <i class="fas fa-user-circle"></i><span>Profile</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?php echo $base_url; ?>/modules/auth/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i><span>Logout</span>
                </a>
            </div>
        <?php navSectionEnd(); ?>

    <?php endif; ?>
    </nav>
</aside>

<!-- ════════════════════════════════════════════════════════
     HEADER
════════════════════════════════════════════════════════ -->
<header class="header">
    <div class="header-left">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="header-right">
        <!-- ★ Notification bell — real-time badge ★ -->
        <div class="notification-dropdown" style="position:relative;margin-right:20px;">
            <button class="notification-bell" id="notificationBell"
                    style="background:none;border:none;cursor:pointer;position:relative;padding:10px;">
                <i class="fas fa-bell" id="bell-icon" style="font-size:20px;color:#4a5568;"></i>
                <!-- ★ id="bell-badge" is what the JS poller targets ★ -->
                <span id="bell-badge"
                      style="display:<?php echo $unread_count > 0 ? 'flex' : 'none'; ?>;">
                    <?php echo $unread_count > 9 ? '9+' : $unread_count; ?>
                </span>
            </button>

            <div class="notification-panel" id="notificationPanel"
                 style="display:none;position:absolute;right:0;top:100%;width:350px;max-height:400px;
                        overflow-y:auto;background:white;border-radius:8px;
                        box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:1000;margin-top:5px;">
                <div style="padding:15px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <h6 style="margin:0;font-weight:600;">Notifications</h6>
                    <?php if ($unread_count > 0): ?>
                        <span id="panel-unread-label"
                              style="background:#edf2f7;padding:2px 8px;border-radius:12px;font-size:12px;color:#4a5568;">
                            <?php echo $unread_count; ?> new
                        </span>
                    <?php endif; ?>
                </div>

                <div style="max-height:300px;overflow-y:auto;">
                    <?php if (!empty($recent_notifications)): ?>
                        <?php foreach ($recent_notifications as $notif): ?>
                            <?php
                            $icon='bell'; $color='#6b7280'; $link='#';
                            switch($notif['type']) {
                                case 'complaint_filed':            $icon='file-alt';             $color='#f59e0b'; $link=$base_url.'/modules/complaints/complaint-details.php?id='.$notif['reference_id']; break;
                                case 'complaint_status_update':    $icon='sync-alt';             $color='#3b82f6'; $link=$base_url.'/modules/complaints/complaint-details.php?id='.$notif['reference_id']; break;
                                case 'complaint_resolved':         $icon='check-circle';         $color='#10b981'; $link=$base_url.'/modules/complaints/complaint-details.php?id='.$notif['reference_id']; break;
                                case 'complaint_closed':           $icon='times-circle';         $color='#6b7280'; $link=$base_url.'/modules/complaints/complaint-details.php?id='.$notif['reference_id']; break;
                                case 'complaint_assignment':       $icon='user-shield';          $color='#8b5cf6'; $link=$base_url.'/modules/complaints/complaint-details.php?id='.$notif['reference_id']; break;
                                case 'document_request_submitted': $icon='file-invoice';         $color='#3b82f6'; $link=$base_url.'/modules/requests/view-request.php?id='.$notif['reference_id']; break;
                                case 'request_status_update':      $icon='check-circle';         $color='#10b981'; $link=$base_url.'/modules/requests/view-request.php?id='.$notif['reference_id']; break;
                                case 'payment_confirmed':          $icon='money-bill-wave';      $color='#10b981'; $link=$base_url.'/modules/requests/view-request.php?id='.$notif['reference_id']; break;
                                case 'incident_reported':          $icon='exclamation-triangle'; $color='#ef4444'; $link=$base_url.'/modules/incidents/incident-details.php?id='.$notif['reference_id']; break;
                                case 'incident_status_update':     $icon='sync-alt';             $color='#3b82f6'; $link=$base_url.'/modules/incidents/incident-details.php?id='.$notif['reference_id']; break;
                                case 'incident_resolved':          $icon='check-circle';         $color='#10b981'; $link=$base_url.'/modules/incidents/incident-details.php?id='.$notif['reference_id']; break;
                                case 'blotter_filed':              $icon='file-alt';             $color='#f59e0b'; $link=$base_url.'/modules/blotter/view-blotter.php?id='.$notif['reference_id']; break;
                                case 'blotter_status_update':      $icon='sync-alt';             $color='#3b82f6'; $link=$base_url.'/modules/blotter/view-blotter.php?id='.$notif['reference_id']; break;
                                case 'blotter_hearing_scheduled':  $icon='gavel';               $color='#8b5cf6'; $link=$base_url.'/modules/blotter/view-blotter.php?id='.$notif['reference_id']; break;
                                case 'blotter_resolved':           $icon='check-circle';         $color='#10b981'; $link=$base_url.'/modules/blotter/view-blotter.php?id='.$notif['reference_id']; break;
                                case 'appointment_booked':
                                case 'appointment_confirmed':      $icon='calendar-check';       $color='#10b981'; $link=($user_role==='Resident')?$base_url.'/modules/health/book-appointment.php':$base_url.'/modules/health/appointments.php'; break;
                                case 'appointment_cancelled':      $icon='calendar-times';       $color='#ef4444'; $link=($user_role==='Resident')?$base_url.'/modules/health/book-appointment.php':$base_url.'/modules/health/appointments.php'; break;
                                case 'medical_assistance_request':
                                case 'medical_assistance':         $icon='hand-holding-medical'; $color='#6366f1'; $link=($user_role==='Resident')?$base_url.'/modules/health/request-assistance.php':$base_url.'/modules/health/medical-assistance.php'; break;
                                case 'announcement':               $icon='bullhorn';             $color='#8b5cf6'; $link='#'; break;
                                // ★ NEW: email reply from resident
                                case 'email_reply':                $icon='envelope';             $color='#3182ce'; $link=$base_url.'/modules/notifications/notification-detail.php?id='.$notif['notification_id']; break;
                            }
                            $bg_color = $notif['is_read'] ? '#ffffff' : '#f0f9ff';
                            $time_diff = time() - strtotime($notif['created_at']);
                            if ($time_diff < 60)        $time_ago = "Just now";
                            elseif ($time_diff < 3600)  $time_ago = floor($time_diff/60)." min ago";
                            elseif ($time_diff < 86400) $time_ago = floor($time_diff/3600)." hr ago";
                            else                        $time_ago = date('M d, h:i A', strtotime($notif['created_at']));
                            // email_reply always links to notification-detail, not the raw reference_id
                            if ($notif['type'] === 'email_reply') {
                                $link = $base_url.'/modules/notifications/notification-detail.php?id='.intval($notif['notification_id']);
                            }
                            ?>
                            <a href="<?php echo $base_url; ?>/modules/notifications/mark_read_redirect.php?id=<?php echo intval($notif['notification_id']); ?>&redirect=<?php echo urlencode($link); ?>"
                               style="display:block;padding:12px 15px;border-bottom:1px solid #f3f4f6;text-decoration:none;color:inherit;background:<?php echo $bg_color; ?>;transition:background 0.2s;"
                               onmouseover="this.style.background='#f9fafb'"
                               onmouseout="this.style.background='<?php echo $bg_color; ?>'">
                                <div style="display:flex;gap:12px;">
                                    <div style="flex-shrink:0;">
                                        <div style="width:40px;height:40px;border-radius:50%;background:<?php echo $color; ?>20;display:flex;align-items:center;justify-content:center;">
                                            <i class="fas fa-<?php echo $icon; ?>" style="color:<?php echo $color; ?>;font-size:16px;"></i>
                                        </div>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-weight:600;color:#1f2937;font-size:14px;margin-bottom:2px;"><?php echo htmlspecialchars($notif['title']); ?></div>
                                        <div style="color:#6b7280;font-size:13px;line-height:1.4;margin-bottom:4px;"><?php echo htmlspecialchars(substr($notif['message'],0,60)).(strlen($notif['message'])>60?'...':''); ?></div>
                                        <div style="color:#9ca3af;font-size:12px;"><i class="far fa-clock" style="margin-right:4px;"></i><?php echo $time_ago; ?></div>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                        <div style="flex-shrink:0;"><div style="width:8px;height:8px;background:#3b82f6;border-radius:50%;"></div></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:40px 20px;text-align:center;color:#9ca3af;">
                            <i class="far fa-bell-slash" style="font-size:40px;margin-bottom:10px;opacity:0.5;"></i>
                            <p style="margin:0;">No notifications</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($recent_notifications)): ?>
                <div style="padding:10px 15px;border-top:1px solid #e2e8f0;text-align:center;">
                    <a href="<?php echo $base_url; ?>/modules/notifications/index.php"
                       style="color:#3b82f6;text-decoration:none;font-size:13px;font-weight:500;">
                       View All Notifications
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- end notification dropdown -->

        <div class="user-profile">
            <div class="user-avatar">
                <?php if (!empty($user_profile_photo)): ?>
                    <img src="<?php echo $base_url; ?>/uploads/profiles/<?php echo htmlspecialchars($user_profile_photo); ?>"
                         alt="Profile Photo"
                         onerror="this.style.display='none';this.parentElement.innerHTML='<?php echo $user_initials; ?>';">
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

<!-- ════════════════════════════════════════════════════════
     TOAST CONTAINER  (injected by JS poller)
════════════════════════════════════════════════════════ -->
<div id="notif-toast-wrap"></div>

<!-- ════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Sidebar toggle ── */
    var sidebarToggle = document.getElementById('sidebarToggle');
    if (localStorage.getItem('sidebarClosed') === 'true') {
        document.body.classList.remove('sidebar-open');
        document.body.classList.add('sidebar-closed');
    }
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            var isClosed = document.body.classList.toggle('sidebar-closed');
            document.body.classList.toggle('sidebar-open', !isClosed);
            localStorage.setItem('sidebarClosed', isClosed ? 'true' : 'false');
        });
    }

    /* ── Collapsible nav sections ── */
    document.querySelectorAll('.nav-section-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var section = this.closest('.nav-section');
            section.classList.toggle('open');
            var id = section.dataset.section;
            if (id) {
                var openSections = JSON.parse(sessionStorage.getItem('openNavSections') || '{}');
                openSections[id] = section.classList.contains('open');
                sessionStorage.setItem('openNavSections', JSON.stringify(openSections));
            }
        });
    });
    var stored = JSON.parse(sessionStorage.getItem('openNavSections') || '{}');
    document.querySelectorAll('.nav-section[data-section]').forEach(function (section) {
        var id = section.dataset.section;
        if (stored[id] === true) section.classList.add('open');
    });

    /* ── Notification bell dropdown ── */
    var bell  = document.getElementById('notificationBell');
    var panel = document.getElementById('notificationPanel');
    if (bell && panel) {
        var panelOpen = false;
        bell.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            panelOpen = !panelOpen;
            panel.style.display = panelOpen ? 'block' : 'none';
        });
        document.addEventListener('click', function (e) {
            if (panelOpen && !bell.contains(e.target) && !panel.contains(e.target)) {
                panelOpen = false;
                panel.style.display = 'none';
            }
        });
        panel.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    /* ════════════════════════════════════════
       REAL-TIME NOTIFICATION POLLER
       Polls notification_count.php every 30 s.
       Updates bell badge + sidebar badge + toast.
    ════════════════════════════════════════ */
    var BASE_URL   = '<?php echo $base_url; ?>';
    var COUNT_URL  = BASE_URL + '/modules/notifications/notification_count.php';
    var NOTIF_URL  = BASE_URL + '/modules/notifications/index.php?filter=unread';
    var POLL_MS    = 30000; // 30 seconds
    var lastCount  = <?php echo intval($unread_count); ?>; // seed with server-rendered value

    var bellBadge      = document.getElementById('bell-badge');
    var bellIcon       = document.getElementById('bell-icon');
    var sidebarBadge   = document.getElementById('sidebar-notif-badge');
    var panelLabel     = document.getElementById('panel-unread-label');
    var toastWrap      = document.getElementById('notif-toast-wrap');

    /* Update every badge element in one go */
    function applyCount(n) {
        /* Header bell badge */
        if (bellBadge) {
            if (n > 0) {
                bellBadge.textContent   = n > 99 ? '99+' : n;
                bellBadge.style.display = 'flex';
            } else {
                bellBadge.style.display = 'none';
            }
        }
        /* Sidebar Notifications badge */
        if (sidebarBadge) {
            if (n > 0) {
                sidebarBadge.textContent   = n > 9 ? '9+' : n;
                sidebarBadge.style.display = 'inline-block';
            } else {
                sidebarBadge.style.display = 'none';
            }
        }
        /* Dropdown header "X new" pill */
        if (panelLabel) {
            if (n > 0) {
                panelLabel.textContent   = n + ' new';
                panelLabel.style.display = '';
            } else {
                panelLabel.style.display = 'none';
            }
        }
    }

    /* Shake bell + pop badge on genuinely new notifications */
    function animateNewNotif() {
        if (bellIcon) {
            bellIcon.classList.remove('bell-shake');
            void bellIcon.offsetWidth; // reflow
            bellIcon.classList.add('bell-shake');
            setTimeout(function () { bellIcon.classList.remove('bell-shake'); }, 700);
        }
        if (bellBadge) {
            bellBadge.classList.remove('badge-pop');
            void bellBadge.offsetWidth;
            bellBadge.classList.add('badge-pop');
            setTimeout(function () { bellBadge.classList.remove('badge-pop'); }, 400);
        }
    }

    /* Show a toast popup */
    function showToast(title, msg) {
        if (!toastWrap) return;
        var t = document.createElement('div');
        t.className = 'notif-toast';
        t.innerHTML =
            '<span class="notif-toast-icon">✉️</span>' +
            '<div class="notif-toast-body">' +
            '  <div class="notif-toast-title">' + escH(title) + '</div>' +
            '  <div class="notif-toast-msg">'   + escH(msg)   + '</div>' +
            '</div>' +
            '<button class="notif-toast-close" title="Dismiss">&times;</button>';

        t.querySelector('.notif-toast-close').addEventListener('click', function (e) {
            e.stopPropagation(); removeToast(t);
        });
        t.addEventListener('click', function () {
            window.location.href = NOTIF_URL;
        });
        toastWrap.appendChild(t);
        setTimeout(function () { removeToast(t); }, 7000);
    }
    function removeToast(el) {
        el.style.opacity   = '0';
        el.style.transform = 'translateX(28px)';
        el.style.transition= 'opacity .3s, transform .3s';
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 350);
    }
    function escH(s) {
        return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* Poll */
    function pollCount() {
        fetch(COUNT_URL, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var n = parseInt(d.unread_count || d.total_unread || 0, 10);
                if (n > lastCount) {
                    var diff = n - lastCount;
                    animateNewNotif();
                    showToast(
                        diff === 1 ? 'New Notification' : diff + ' New Notifications',
                        'You have ' + n + ' unread notification' + (n !== 1 ? 's.' : '.')
                    );
                }
                applyCount(n);
                lastCount = n;
            })
            .catch(function () { /* network error — skip silently */ });
    }

    /* Kick off: first check after 3 s, then every 30 s */
    setTimeout(pollCount, 3000);
    setInterval(pollCount, POLL_MS);

}); // end DOMContentLoaded
</script>

<!-- Main Content -->
<main class="main-content">