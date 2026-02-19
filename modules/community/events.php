<?php
// modules/community/events.php - Community Events for Residents
session_start();
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = getCurrentUserId();
$page_title = "Community Events";

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle RSVP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rsvp'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF validation failed');
    }
    
    $event_id = intval($_POST['event_id']);
    
    // Verify event exists and is available for registration
    $event_check = $conn->prepare("
        SELECT event_id, status, event_date 
        FROM tbl_events 
        WHERE event_id = ? AND status IN ('upcoming', 'ongoing')
    ");
    $event_check->bind_param("i", $event_id);
    $event_check->execute();
    $event_exists = $event_check->get_result()->fetch_assoc();
    $event_check->close();
    
    if (!$event_exists) {
        $_SESSION['error_message'] = "Event not found or no longer available for registration.";
        header("Location: events.php");
        exit();
    }
    
    // Get user info using prepared statement
    $user_stmt = $conn->prepare("
        SELECT u.user_id, u.username, r.resident_id
        FROM tbl_users u
        LEFT JOIN tbl_resident r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?
    ");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();
    
    if (!$user_data) {
        $_SESSION['error_message'] = "User not found.";
        header("Location: events.php");
        exit();
    }
    
    // If no resident record exists, create one automatically
    if (!$user_data['resident_id']) {
        $create_resident = $conn->prepare("
            INSERT INTO tbl_resident (first_name, last_name)
            VALUES (?, '')
        ");
        $username = $user_data['username'];
        $create_resident->bind_param("s", $username);
        $create_resident->execute();
        $new_resident_id = $conn->insert_id;
        $create_resident->close();
        
        // Link resident to user
        $link_stmt = $conn->prepare("UPDATE tbl_users SET resident_id = ? WHERE user_id = ?");
        $link_stmt->bind_param("ii", $new_resident_id, $user_id);
        $link_stmt->execute();
        $link_stmt->close();
        
        $user_data['resident_id'] = $new_resident_id;
    }
    
    // Check if already registered using prepared statement
    $check = $conn->prepare("SELECT attendee_id FROM tbl_event_attendees WHERE event_id = ? AND resident_id = ?");
    $check->bind_param("ii", $event_id, $user_data['resident_id']);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    
    if (!$existing) {
        // Create new RSVP using INSERT IGNORE to prevent race conditions
        $stmt = $conn->prepare("
            INSERT IGNORE INTO tbl_event_attendees (event_id, resident_id) 
            VALUES (?, ?)
        ");
        $stmt->bind_param("ii", $event_id, $user_data['resident_id']);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "RSVP submitted successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to register. You may already be registered for this event.";
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = "You are already registered for this event!";
    }
    
    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    header("Location: events.php");
    exit();
}

// Handle Cancel RSVP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_rsvp'])) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF validation failed');
    }
    
    $event_id = intval($_POST['event_id']);
    
    // Verify event exists
    $event_check = $conn->prepare("SELECT event_id FROM tbl_events WHERE event_id = ?");
    $event_check->bind_param("i", $event_id);
    $event_check->execute();
    if (!$event_check->get_result()->fetch_assoc()) {
        $_SESSION['error_message'] = "Event not found.";
        header("Location: events.php");
        exit();
    }
    $event_check->close();
    
    // Get resident info using prepared statement
    $user_stmt = $conn->prepare("
        SELECT r.resident_id
        FROM tbl_users u
        LEFT JOIN tbl_resident r ON u.resident_id = r.resident_id
        WHERE u.user_id = ?
    ");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();
    
    if ($user_data && $user_data['resident_id']) {
        // Delete RSVP - only allow canceling own registration
        $stmt = $conn->prepare("DELETE FROM tbl_event_attendees WHERE event_id = ? AND resident_id = ? LIMIT 1");
        $stmt->bind_param("ii", $event_id, $user_data['resident_id']);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Your RSVP has been cancelled.";
        } else {
            $_SESSION['error_message'] = "RSVP not found.";
        }
        $stmt->close();
    }
    
    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    header("Location: events.php");
    exit();
}

// Get current user's resident_id using prepared statement
$user_stmt = $conn->prepare("
    SELECT r.resident_id
    FROM tbl_users u
    LEFT JOIN tbl_resident r ON u.resident_id = r.resident_id
    WHERE u.user_id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_data = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$resident_id = $user_data['resident_id'] ?? 0;

// If no resident record exists, create one automatically
if (!$resident_id) {
    // Get username for the resident record
    $username_stmt = $conn->prepare("SELECT username FROM tbl_users WHERE user_id = ?");
    $username_stmt->bind_param("i", $user_id);
    $username_stmt->execute();
    $username_result = $username_stmt->get_result()->fetch_assoc();
    $username_stmt->close();
    
    $username = $username_result['username'] ?? 'User';
    
    $create_resident = $conn->prepare("
        INSERT INTO tbl_resident (first_name, last_name)
        VALUES (?, '')
    ");
    $create_resident->bind_param("s", $username);
    $create_resident->execute();
    $resident_id = $conn->insert_id;
    $create_resident->close();
    
    // Link resident to user
    $link_stmt = $conn->prepare("UPDATE tbl_users SET resident_id = ? WHERE user_id = ?");
    $link_stmt->bind_param("ii", $resident_id, $user_id);
    $link_stmt->execute();
    $link_stmt->close();
}

// Get upcoming events - show only upcoming and ongoing events (filter out completed and cancelled)
$upcoming_stmt = $conn->prepare("
    SELECT e.*, 
           CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, '')) as organizer_name,
           COUNT(DISTINCT ea.attendee_id) as confirmed_count,
           (SELECT COUNT(*) FROM tbl_event_attendees WHERE event_id = e.event_id AND resident_id = ?) as is_registered
    FROM tbl_events e
    LEFT JOIN tbl_resident r ON e.organizer_id = r.resident_id
    LEFT JOIN tbl_event_attendees ea ON e.event_id = ea.event_id
    WHERE e.status = 'upcoming' OR e.status = 'ongoing'
    GROUP BY e.event_id
    ORDER BY e.event_date ASC, e.start_time ASC
");
$upcoming_stmt->bind_param("i", $resident_id);
$upcoming_stmt->execute();
$upcoming_events = $upcoming_stmt->get_result();

// Get my registered events using prepared statement - only show upcoming and ongoing
$my_events_stmt = $conn->prepare("
    SELECT e.*, 
           ea.registered_at,
           CONCAT(COALESCE(r.first_name, ''), ' ', COALESCE(r.last_name, '')) as organizer_name
    FROM tbl_events e
    INNER JOIN tbl_event_attendees ea ON e.event_id = ea.event_id
    LEFT JOIN tbl_resident r ON e.organizer_id = r.resident_id
    WHERE ea.resident_id = ?
    AND (e.status = 'upcoming' OR e.status = 'ongoing')
    ORDER BY e.event_date ASC, e.start_time ASC
");
$my_events_stmt->bind_param("i", $resident_id);
$my_events_stmt->execute();
$my_events = $my_events_stmt->get_result();

// Get count for display
$my_events_count = $my_events->num_rows;

include '../../includes/header.php';
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #fafafa;
        color: #1a1a1a;
    }
    
    .page-header {
        background: white;
        padding: 2rem;
        border-radius: 4px;
        margin-bottom: 2rem;
        border: 1px solid #e0e0e0;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .page-header p {
        color: #757575;
        margin: 0;
        font-size: 0.875rem;
    }
    
    .nav-pills {
        background: white;
        padding: 0.25rem;
        border-radius: 4px;
        display: inline-flex;
        gap: 0.25rem;
        border: 1px solid #e0e0e0;
        margin-bottom: 2rem;
    }
    
    .nav-pills .nav-link {
        border-radius: 3px;
        padding: 0.625rem 1.25rem;
        color: #616161;
        font-weight: 500;
        transition: all 0.15s;
        background: transparent;
        border: none;
        cursor: pointer;
        font-size: 0.875rem;
    }
    
    .nav-pills .nav-link:hover {
        background: #f5f5f5;
        color: #1a1a1a;
    }
    
    .nav-pills .nav-link.active {
        background: #1a1a1a;
        color: white;
    }
    
    .event-card {
        background: white;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 1.5rem;
        border: 1px solid #e0e0e0;
        transition: border-color 0.2s, transform 0.2s;
    }
    
    .event-card:hover {
        border-color: #bdbdbd;
        transform: translateY(-2px);
    }
    
    .event-image {
        width: 100%;
        height: 200px;
        background: #f5f5f5;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
    }
    
    .event-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .event-image-placeholder {
        color: #bdbdbd;
        font-size: 3.5rem;
    }
    
    .event-date-badge {
        position: absolute;
        top: 1rem;
        left: 1rem;
        background: white;
        padding: 0.625rem;
        border-radius: 3px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        min-width: 60px;
        border: 1px solid #e0e0e0;
    }
    
    .event-date-badge .day {
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1;
        color: #1a1a1a;
    }
    
    .event-date-badge .month {
        font-size: 0.6875rem;
        color: #757575;
        text-transform: uppercase;
        font-weight: 600;
        margin-top: 0.25rem;
        letter-spacing: 0.5px;
    }
    
    .event-type-badge {
        display: inline-block;
        padding: 0.25rem 0.625rem;
        border-radius: 2px;
        font-size: 0.75rem;
        font-weight: 500;
        background: #f5f5f5;
        color: #616161;
        border: 1px solid #e0e0e0;
    }
    
    .registered-badge {
        padding: 0.375rem 0.75rem;
        border-radius: 3px;
        font-size: 0.8125rem;
        font-weight: 500;
        background: #1a1a1a;
        color: white;
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }
    
    .event-info-item {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        margin-bottom: 0.625rem;
        color: #616161;
        font-size: 0.8125rem;
    }
    
    .event-info-item i {
        width: 18px;
        text-align: center;
        color: #9e9e9e;
    }
    
    .btn {
        padding: 0.625rem 1.125rem;
        font-size: 0.8125rem;
        font-weight: 500;
        border-radius: 3px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary {
        background: #1a1a1a;
        color: white;
    }
    
    .btn-primary:hover {
        background: #333;
    }
    
    .btn-danger {
        background: white;
        color: #d32f2f;
        border: 1px solid #e0e0e0;
    }
    
    .btn-danger:hover {
        background: #ffebee;
        border-color: #d32f2f;
    }
    
    .btn-sm {
        padding: 0.375rem 0.875rem;
        font-size: 0.75rem;
    }
    
    .w-100 {
        width: 100%;
    }
    
    .alert {
        padding: 0.875rem 1rem;
        border-radius: 3px;
        margin-bottom: 1.5rem;
        border: 1px solid;
        font-size: 0.875rem;
    }
    
    .alert-success {
        background: #f1f8f4;
        color: #1e4620;
        border-color: #c3e6cb;
    }
    
    .alert-error {
        background: #fef5f5;
        color: #5f2120;
        border-color: #f5c6cb;
    }
    
    .alert-info {
        background: #f5f8fb;
        color: #0c3c60;
        border-color: #b8daff;
    }
    
    .row {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 1.25rem;
    }
    
    .event-title {
        margin-bottom: 0.625rem;
        font-size: 1.0625rem;
        font-weight: 600;
        color: #1a1a1a;
    }
    
    .event-description {
        color: #616161;
        margin-bottom: 0.875rem;
        font-size: 0.8125rem;
        line-height: 1.5;
    }
    
    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        animation: fadeIn 0.2s;
    }
    
    .modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .modal-content {
        background-color: white;
        border-radius: 4px;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        animation: slideIn 0.3s;
    }
    
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 {
        margin: 0;
        font-size: 1.125rem;
        font-weight: 600;
        color: #1a1a1a;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #9e9e9e;
        cursor: pointer;
        padding: 0;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 3px;
        transition: all 0.15s;
    }
    
    .modal-close:hover {
        background: #f5f5f5;
        color: #1a1a1a;
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .modal-body p {
        margin: 0;
        color: #616161;
        font-size: 0.9375rem;
        line-height: 1.5;
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e0e0e0;
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }
    
    .btn-cancel {
        background: white;
        color: #616161;
        border: 1px solid #e0e0e0;
    }
    
    .btn-cancel:hover {
        background: #f5f5f5;
        border-color: #bdbdbd;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideIn {
        from { 
            transform: translateY(-20px);
            opacity: 0;
        }
        to { 
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    @media (max-width: 768px) {
        .row {
            grid-template-columns: 1fr;
        }
        
        .modal-content {
            max-width: 90%;
            margin: 1rem;
        }
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fas fa-calendar-alt" style="margin-right: 0.5rem;"></i>Community Events</h1>
        <p>Discover and join upcoming events in our community</p>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle" style="margin-right: 0.5rem;"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle" style="margin-right: 0.5rem;"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-pills">
        <li class="nav-item">
            <button class="nav-link active" onclick="showTab('all-events', this)">
                <i class="fas fa-calendar" style="margin-right: 0.375rem;"></i>All Events
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" onclick="showTab('my-events', this)">
                <i class="fas fa-check-circle" style="margin-right: 0.375rem;"></i>My Events (<?php echo $my_events_count; ?>)
            </button>
        </li>
    </ul>

    <div id="tab-content">
        <!-- All Events Tab -->
        <div id="all-events" class="tab-pane active">
            <div class="row">
                <?php if ($upcoming_events->num_rows > 0): ?>
                    <?php while ($event = $upcoming_events->fetch_assoc()): 
                        $event_datetime = $event['event_date'] . ' ' . $event['start_time'];
                        $end_datetime = $event['event_date'] . ' ' . $event['end_time'];
                        $start_date = new DateTime($event_datetime);
                        $end_date = new DateTime($end_datetime);
                        $has_image = !empty($event['event_image']);
                    ?>
                        <div class="event-card">
                            <div style="position: relative;">
                                <div class="event-image">
                                    <?php if ($has_image): ?>
                                        <img src="<?php echo BASE_URL; ?>/uploads/events/<?php echo htmlspecialchars($event['event_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($event['title']); ?>"
                                             onerror="this.parentElement.innerHTML='<div class=\'event-image-placeholder\'><i class=\'fas fa-calendar-alt\'></i></div>'">
                                    <?php else: ?>
                                        <div class="event-image-placeholder">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="event-date-badge">
                                    <div class="day"><?php echo $start_date->format('d'); ?></div>
                                    <div class="month"><?php echo $start_date->format('M'); ?></div>
                                </div>
                            </div>
                            
                            <div style="padding: 1.25rem;">
                                <div style="margin-bottom: 0.875rem;">
                                    <span class="event-type-badge">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $event['event_type']))); ?>
                                    </span>
                                </div>
                                
                                <h4 class="event-title">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </h4>
                                <p class="event-description">
                                    <?php 
                                    $description = htmlspecialchars($event['description']);
                                    echo mb_substr($description, 0, 120); 
                                    ?>
                                    <?php if (mb_strlen($description) > 120): ?>...<?php endif; ?>
                                </p>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>
                                        <?php echo htmlspecialchars($start_date->format('M d, Y g:i A')); ?> - 
                                        <?php echo htmlspecialchars($end_date->format('g:i A')); ?>
                                    </span>
                                </div>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($event['location']); ?></span>
                                </div>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo intval($event['confirmed_count']); ?> attending</span>
                                </div>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-user"></i>
                                    <span>Organized by <?php echo htmlspecialchars(!empty(trim($event['organizer_name'])) ? $event['organizer_name'] : 'Unknown'); ?></span>
                                </div>
                                
                                <?php if ($event['is_registered'] > 0): ?>
                                    <div style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid #e0e0e0;">
                                        <div style="display: flex; gap: 0.625rem; align-items: center; flex-wrap: wrap;">
                                            <span class="registered-badge">
                                                <i class="fas fa-check-circle"></i>Registered
                                            </span>
                                            <button type="button" class="btn btn-danger btn-sm" 
                                                    onclick="showCancelModal(<?php echo intval($event['event_id']); ?>, '<?php echo htmlspecialchars(addslashes($event['title'])); ?>')">
                                                <i class="fas fa-times" style="margin-right: 0.25rem;"></i>Cancel
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid #e0e0e0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="event_id" value="<?php echo intval($event['event_id']); ?>">
                                        <button type="submit" name="rsvp" class="btn btn-primary w-100">
                                            <i class="fas fa-check" style="margin-right: 0.375rem;"></i>Register for Event
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                            No upcoming events at the moment. Check back soon!
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- My Events Tab -->
        <div id="my-events" class="tab-pane" style="display: none;">
            <div class="row">
                <?php if ($my_events->num_rows > 0): ?>
                    <?php 
                    $my_events->data_seek(0);
                    while ($event = $my_events->fetch_assoc()): 
                        $event_datetime = $event['event_date'] . ' ' . $event['start_time'];
                        $end_datetime = $event['event_date'] . ' ' . $event['end_time'];
                        $start_date = new DateTime($event_datetime);
                        $end_date = new DateTime($end_datetime);
                        $has_image = !empty($event['event_image']);
                    ?>
                        <div class="event-card">
                            <div style="position: relative;">
                                <div class="event-image">
                                    <?php if ($has_image): ?>
                                        <img src="<?php echo BASE_URL; ?>/uploads/events/<?php echo htmlspecialchars($event['event_image']); ?>" 
                                             alt="<?php echo htmlspecialchars($event['title']); ?>"
                                             onerror="this.parentElement.innerHTML='<div class=\'event-image-placeholder\'><i class=\'fas fa-calendar-alt\'></i></div>'">
                                    <?php else: ?>
                                        <div class="event-image-placeholder">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="event-date-badge">
                                    <div class="day"><?php echo $start_date->format('d'); ?></div>
                                    <div class="month"><?php echo $start_date->format('M'); ?></div>
                                </div>
                            </div>
                            
                            <div style="padding: 1.25rem;">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.875rem; flex-wrap: wrap; gap: 0.625rem;">
                                    <span class="event-type-badge">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $event['event_type']))); ?>
                                    </span>
                                    <span class="registered-badge">
                                        <i class="fas fa-check-circle"></i>Registered
                                    </span>
                                </div>
                                
                                <h5 class="event-title">
                                    <?php echo htmlspecialchars($event['title']); ?>
                                </h5>
                                
                                <p class="event-description">
                                    <?php 
                                    $description = htmlspecialchars($event['description']);
                                    echo mb_substr($description, 0, 120); 
                                    ?>
                                    <?php if (mb_strlen($description) > 120): ?>...<?php endif; ?>
                                </p>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-clock"></i>
                                    <span>
                                        <?php echo htmlspecialchars($start_date->format('M d, Y g:i A')); ?> - 
                                        <?php echo htmlspecialchars($end_date->format('g:i A')); ?>
                                    </span>
                                </div>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($event['location']); ?></span>
                                </div>
                                
                                <div class="event-info-item">
                                    <i class="fas fa-user"></i>
                                    <span>Organized by <?php echo htmlspecialchars(!empty(trim($event['organizer_name'])) ? $event['organizer_name'] : 'Unknown'); ?></span>
                                </div>
                                
                                <form method="POST" style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid #e0e0e0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="event_id" value="<?php echo intval($event['event_id']); ?>">
                                    <button type="button" class="btn btn-danger w-100"
                                            onclick="showCancelModal(<?php echo intval($event['event_id']); ?>, '<?php echo htmlspecialchars(addslashes($event['title'])); ?>')">
                                        <i class="fas fa-times" style="margin-right: 0.375rem;"></i>Cancel Registration
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                            You haven't registered for any events yet. Browse available events in the "All Events" tab.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Cancel RSVP Modal -->
<div id="cancelModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-circle" style="margin-right: 0.5rem; color: #d32f2f;"></i>Cancel Registration</h3>
            <button class="modal-close" onclick="closeCancelModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to cancel your registration for <strong id="eventTitle"></strong>?</p>
            <p style="margin-top: 0.75rem; font-size: 0.8125rem; color: #757575;">This action cannot be undone.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeCancelModal()">
                No, Keep Registration
            </button>
            <form method="POST" style="display: inline;" id="cancelForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="event_id" id="cancelEventId">
                <button type="submit" name="cancel_rsvp" class="btn btn-danger">
                    <i class="fas fa-times" style="margin-right: 0.375rem;"></i>Yes, Cancel RSVP
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function showTab(tabId, button) {
    document.querySelectorAll('.tab-pane').forEach(tab => {
        tab.style.display = 'none';
        tab.classList.remove('active');
    });
    
    document.querySelectorAll('.nav-link').forEach(btn => {
        btn.classList.remove('active');
    });
    
    document.getElementById(tabId).style.display = 'block';
    document.getElementById(tabId).classList.add('active');
    
    button.classList.add('active');
}

function showCancelModal(eventId, eventTitle) {
    document.getElementById('cancelEventId').value = eventId;
    document.getElementById('eventTitle').textContent = eventTitle;
    document.getElementById('cancelModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCancelModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCancelModal();
    }
});
</script>

<?php 
$upcoming_stmt->close();
$my_events_stmt->close();
include '../../includes/footer.php'; 
?>