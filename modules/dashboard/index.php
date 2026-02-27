<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';
requireLogin();

$page_title = 'Dashboard';
$user_role = getCurrentUserRole();
$stats = [];
$success_message = '';
$error_message = '';

// Handle quick actions from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user_role !== 'Admin' && $user_role !== 'Staff' && $user_role !== 'Super Admin') {
        $error_message = "You don't have permission to perform this action.";
    } elseif (isset($_POST['action'])) {

        if ($_POST['action'] === 'add_announcement') {
            try {
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $priority = isset($_POST['priority']) ? $_POST['priority'] : 'normal';
                $created_by = $_SESSION['user_id'];
                $stmt = $conn->prepare("INSERT INTO tbl_announcements (title, content, priority, created_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $title, $content, $priority, $created_by);
                if ($stmt->execute()) $success_message = "Announcement posted successfully!";
                else $error_message = "Error posting announcement: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'edit_announcement') {
            try {
                $id = intval($_POST['id']);
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $priority = isset($_POST['priority']) ? $_POST['priority'] : 'normal';
                $stmt = $conn->prepare("UPDATE tbl_announcements SET title = ?, content = ?, priority = ? WHERE id = ?");
                $stmt->bind_param("sssi", $title, $content, $priority, $id);
                if ($stmt->execute()) $success_message = "Announcement updated successfully!";
                else $error_message = "Error updating announcement: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'toggle_announcement') {
            try {
                $id = intval($_POST['id']);
                $is_active = intval($_POST['is_active']);
                $stmt = $conn->prepare("UPDATE tbl_announcements SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $id);
                if ($stmt->execute()) $success_message = $is_active ? "Announcement activated!" : "Announcement hidden!";
                else $error_message = "Error toggling announcement: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'delete_announcement') {
            try {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("DELETE FROM tbl_announcements WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) $success_message = "Announcement deleted successfully!";
                else $error_message = "Error deleting announcement: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'toggle_media') {
            try {
                $id = intval($_POST['id']);
                $is_active = intval($_POST['is_active']);
                $stmt = $conn->prepare("UPDATE tbl_barangay_media SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $id);
                if ($stmt->execute()) $success_message = $is_active ? "Media activated!" : "Media hidden!";
                else $error_message = "Error toggling media: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'delete_media') {
            try {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("SELECT file_path FROM tbl_barangay_media WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $media = $result->fetch_assoc();
                $stmt->close();
                if ($media && $media['file_path']) {
                    $file_to_delete = '../../' . $media['file_path'];
                    if (file_exists($file_to_delete)) unlink($file_to_delete);
                }
                $stmt = $conn->prepare("DELETE FROM tbl_barangay_media WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) $success_message = "Media deleted successfully!";
                else $error_message = "Error deleting media: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'add_activity') {
            try {
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $activity_date = $_POST['activity_date'];
                $location = isset($_POST['location']) ? trim($_POST['location']) : '';
                $created_by = $_SESSION['user_id'];
                $stmt = $conn->prepare("INSERT INTO tbl_barangay_activities (title, description, activity_date, location, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $title, $description, $activity_date, $location, $created_by);
                if ($stmt->execute()) $success_message = "Activity added successfully!";
                else $error_message = "Error adding activity: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'edit_activity') {
            try {
                $id = intval($_POST['id']);
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $activity_date = $_POST['activity_date'];
                $location = isset($_POST['location']) ? trim($_POST['location']) : '';
                $stmt = $conn->prepare("UPDATE tbl_barangay_activities SET title = ?, description = ?, activity_date = ?, location = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $title, $description, $activity_date, $location, $id);
                if ($stmt->execute()) $success_message = "Activity updated successfully!";
                else $error_message = "Error updating activity: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'toggle_activity') {
            try {
                $id = intval($_POST['id']);
                $is_active = intval($_POST['is_active']);
                $stmt = $conn->prepare("UPDATE tbl_barangay_activities SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $id);
                if ($stmt->execute()) $success_message = $is_active ? "Activity activated!" : "Activity hidden!";
                else $error_message = "Error toggling activity: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }

        } elseif ($_POST['action'] === 'delete_activity') {
            try {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("DELETE FROM tbl_barangay_activities WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) $success_message = "Activity deleted successfully!";
                else $error_message = "Error deleting activity: " . $stmt->error;
                $stmt->close();
            } catch (Exception $e) { $error_message = "Error: " . $e->getMessage(); }
        }

        if ($success_message || $error_message) {
            $_SESSION['temp_success'] = $success_message;
            $_SESSION['temp_error'] = $error_message;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error'])) { $error_message = $_SESSION['temp_error']; unset($_SESSION['temp_error']); }

// ── STATS: Resident ──
if ($user_role === 'Resident') {
    $resident_id = getCurrentResidentId();
    $columns = [
        "total_incidents" => "tbl_incidents",
        "total_complaints" => "tbl_complaints"
    ];
    if (tableExists($conn, 'tbl_document_requests')) {
        $columns["total_documents"] = "tbl_document_requests";
    }
    $sqlParts = [];
    foreach ($columns as $alias => $table) {
        $sqlParts[] = "(SELECT COUNT(*) FROM $table WHERE resident_id = ?) AS $alias";
    }
    $sql = "SELECT " . implode(", ", $sqlParts);
    $stmt = $conn->prepare($sql);
    $types = str_repeat("i", count($columns));
    $params = array_fill(0, count($columns), $resident_id);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();

// ── STATS: Admin/Staff ──
} else {
    $columns = [
        "pending_incidents" => "SELECT COUNT(*) FROM tbl_incidents WHERE status NOT IN ('Resolved','Closed')",
        "pending_complaints" => "SELECT COUNT(*) FROM tbl_complaints WHERE TRIM(status) = 'Pending'",
        "total_complaints" => "SELECT COUNT(*) FROM tbl_complaints",
        "unverified_residents" => tableExists($conn,'tbl_residents') ? "SELECT COUNT(*) FROM tbl_residents WHERE is_verified=0" : "SELECT 0",
        "total_residents" => tableExists($conn,'tbl_residents') ? "SELECT COUNT(*) FROM tbl_residents" : "SELECT 0"
    ];
    if (tableExists($conn, 'tbl_requests')) {
        $columns["pending_requests"] = "SELECT COUNT(*) FROM tbl_requests WHERE status IN ('Pending')";
    }
    $sqlParts = [];
    foreach ($columns as $alias => $query) {
        $sqlParts[] = "($query) AS $alias";
    }
    $sql = "SELECT " . implode(", ", $sqlParts);
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
}

// ── FETCH: All Complaints (Admin/Staff) ──
$all_complaints = [];
if ($user_role !== 'Resident' && tableExists($conn, 'tbl_complaints')) {
    $date_columns = ['created_at', 'date_filed', 'date_created'];
    $date_column = 'created_at';
    foreach ($date_columns as $col) { if (columnExists($conn, 'tbl_complaints', $col)) { $date_column = $col; break; } }
    $complaints_stmt = $conn->prepare("
        SELECT c.complaint_id, c.complaint_number, c.subject, c.description, c.category,
               c.priority, c.status, c.resident_id, c.assigned_to,
               c.$date_column as complaint_date,
               CONCAT(r.first_name, ' ', r.last_name) as complainant_name,
               u.username as assigned_to_name
        FROM tbl_complaints c
        LEFT JOIN tbl_residents r ON c.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON c.assigned_to = u.user_id
        ORDER BY c.$date_column DESC LIMIT 50
    ");
    $complaints_stmt->execute();
    $complaints_result = $complaints_stmt->get_result();
    while ($row = $complaints_result->fetch_assoc()) { $all_complaints[] = $row; }
    $complaints_stmt->close();
}

// ── FETCH: Pending Requests (Admin/Staff) ──
$pending_document_requests = [];
if ($user_role !== 'Resident' && tableExists($conn, 'tbl_requests')) {
    $stmt = $conn->prepare("
        SELECT r.*, res.first_name, res.last_name, res.email, rt.request_type_name, rt.fee
        FROM tbl_requests r
        INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        WHERE r.status = 'Pending'
        ORDER BY r.request_date DESC LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $pending_document_requests[] = $row; }
    $stmt->close();
}

// ── FETCH: Pending Complaints (Admin/Staff) ──
$pending_complaints = [];
if ($user_role !== 'Resident' && tableExists($conn, 'tbl_complaints')) {
    $date_columns = ['created_at', 'date_filed', 'date_created'];
    $date_column = 'created_at';
    foreach ($date_columns as $col) { if (columnExists($conn, 'tbl_complaints', $col)) { $date_column = $col; break; } }
    $stmt = $conn->prepare("
        SELECT c.complaint_id, c.complaint_number, c.subject, c.description, c.category,
               c.priority, c.status, c.resident_id, c.assigned_to,
               c.$date_column as complaint_date,
               CONCAT(r.first_name, ' ', r.last_name) as complainant_name,
               u.username as assigned_to_name
        FROM tbl_complaints c
        LEFT JOIN tbl_residents r ON c.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON c.assigned_to = u.user_id
        WHERE TRIM(c.status) = 'Pending'
        ORDER BY c.$date_column DESC LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $pending_complaints[] = $row; }
    $stmt->close();
}

// ── FETCH: Announcements (ALL roles) ──
// FIX: Sort purely by created_at DESC so newest always appears first,
//      regardless of priority level.
$announcements = [];
if (tableExists($conn, 'tbl_announcements')) {
    $stmt = $conn->prepare("
        SELECT * FROM tbl_announcements
        WHERE is_active = 1
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $announcements[] = $row; }
    $stmt->close();
}

// ── FETCH: Video (ALL roles) ──
$video_url = null;
$video_data = null;
if (tableExists($conn, 'tbl_barangay_media')) {
    $stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'video' AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $video_data = $result->fetch_assoc();
    if ($video_data) $video_url = $video_data['video_url'];
    $stmt->close();
}

// ── FETCH: Gallery (ALL roles) ──
$gallery_photos = [];
if (tableExists($conn, 'tbl_barangay_media')) {
    $stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'photo' AND is_active = 1 ORDER BY created_at DESC LIMIT 12");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $gallery_photos[] = $row; }
    $stmt->close();
}

// ── FETCH: Activities (ALL roles) ──
// FIX: Sort by activity_date ASC so the soonest upcoming event appears first.
$activities = [];
if (tableExists($conn, 'tbl_barangay_activities')) {
    $check_column = $conn->query("SHOW COLUMNS FROM tbl_barangay_activities LIKE 'location'");
    $has_location = $check_column->num_rows > 0;
    $stmt = $conn->prepare("
    SELECT * FROM tbl_barangay_activities
    WHERE is_active = 1
    ORDER BY created_at DESC
    LIMIT 8
");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!$has_location && !isset($row['location'])) $row['location'] = '';
        $activities[] = $row;
    }
    $stmt->close();
}

// ── FETCH: All Residents (Admin/Staff) ──
$all_residents = [];
$verified_residents = [];
$unverified_residents_arr = [];
if ($user_role !== 'Resident' && tableExists($conn, 'tbl_residents')) {
    $residents_stmt = $conn->prepare("SELECT * FROM tbl_residents ORDER BY created_at DESC LIMIT 50");
    $residents_stmt->execute();
    $residents_result = $residents_stmt->get_result();
    while ($row = $residents_result->fetch_assoc()) { $all_residents[] = $row; }
    $residents_stmt->close();
    $verified_residents = array_filter($all_residents, function($r) { return $r['is_verified'] == 1; });
    $unverified_residents_arr = array_filter($all_residents, function($r) { return $r['is_verified'] == 0; });
}

// ── FETCH: Pending Incidents (Admin/Staff) ──
$pending_incidents = [];
if ($user_role !== 'Resident' && tableExists($conn, 'tbl_incidents')) {
    $incidents_stmt = $conn->prepare("
        SELECT i.*, CONCAT(r.first_name, ' ', r.last_name) as reporter_name, u.username as responder_name
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON i.responder_id = u.user_id
        WHERE i.status NOT IN ('Resolved', 'Closed')
        ORDER BY i.date_reported DESC LIMIT 10
    ");
    $incidents_stmt->execute();
    $incidents_result = $incidents_stmt->get_result();
    while ($row = $incidents_result->fetch_assoc()) { $pending_incidents[] = $row; }
    $incidents_stmt->close();
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';

// Role label map
$role_greetings = [
    'Super Admin' => 'Super Admin',
    'Admin'       => 'Administrator',
    'Staff'       => 'Staff Member',
    'Resident'    => 'Resident',
];
$role_label = $role_greetings[$user_role] ?? $user_role;

// Role badge colors
$role_badge_colors = [
    'Super Admin' => 'badge-superadmin',
    'Admin'       => 'badge-admin',
    'Staff'       => 'badge-staff',
    'Resident'    => 'badge-resident',
];
$role_badge = $role_badge_colors[$user_role] ?? 'badge-resident';
?>

<!-- ═══════════════════════════════════════════
     DASHBOARD HERO — Visible to ALL roles
═══════════════════════════════════════════ -->
<div class="db-hero">
    <!-- Background decorative rings -->
    <div class="db-hero__ring db-hero__ring--1"></div>
    <div class="db-hero__ring db-hero__ring--2"></div>
    <div class="db-hero__ring db-hero__ring--3"></div>

    <div class="db-hero__inner">
        <div class="db-hero__left">
            <div class="db-hero__avatar">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
            </div>
            <div class="db-hero__text">
                <div class="db-hero__role-badge <?php echo $role_badge; ?>">
                    <span class="db-hero__role-dot"></span>
                    <?php echo htmlspecialchars($role_label); ?>
                </div>
                <h1 class="db-hero__title">
                    Good <?php
                        $h = (int)date('G');
                        echo $h < 12 ? 'Morning' : ($h < 17 ? 'Afternoon' : 'Evening');
                    ?>, <?php echo htmlspecialchars($_SESSION['username']); ?>
                </h1>
                <p class="db-hero__sub">Here's what's happening in your barangay today</p>
            </div>
        </div>

        <div class="db-hero__right">
            <div class="db-hero__datetime">
                <div class="db-hero__date">
                    <i class="fas fa-calendar-day"></i>
                    <span><?php echo date('F j, Y'); ?></span>
                </div>
                <div class="db-hero__time" id="db-live-time"><?php echo date('g:i:s A'); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Alerts -->
<?php if ($success_message): ?>
<div class="db-alert db-alert--success">
    <div class="db-alert__icon"><i class="fas fa-check-circle"></i></div>
    <span><?php echo htmlspecialchars($success_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="db-alert db-alert--error">
    <div class="db-alert__icon"><i class="fas fa-exclamation-circle"></i></div>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════
     STATS CARDS — Resident View
═══════════════════════════════════════════ -->
<?php if ($user_role === 'Resident'): ?>
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--blue">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['total_incidents'] ?? 0; ?></div>
            <div class="db-stat-card__label">My Incidents</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['total_complaints'] ?? 0; ?></div>
            <div class="db-stat-card__label">My Complaints</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
    </div>
    <?php if (isset($stats['total_documents'])): ?>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal">
            <i class="fas fa-file-alt"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['total_documents'] ?? 0; ?></div>
            <div class="db-stat-card__label">My Requests</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
    </div>
    <?php endif; ?>
    <!-- Quick links for residents -->
    <a href="../incidents/report-incident.php" class="db-quicklink-card">
        <i class="fas fa-plus-circle"></i>
        <span>Report Incident</span>
        <i class="fas fa-arrow-right db-quicklink-card__arrow"></i>
    </a>
    <a href="../complaints/file-complaint.php" class="db-quicklink-card db-quicklink-card--amber">
        <i class="fas fa-comment-dots"></i>
        <span>File Complaint</span>
        <i class="fas fa-arrow-right db-quicklink-card__arrow"></i>
    </a>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════
     STATS CARDS — Admin/Staff View
═══════════════════════════════════════════ -->
<?php if ($user_role !== 'Resident'): ?>
<div class="db-stats-row">
    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('pendingIncidentsSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--blue">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['pending_incidents'] ?? 0; ?></div>
            <div class="db-stat-card__label">Pending Incidents</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--blue"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>

    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('pendingComplaintsSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--amber">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['pending_complaints'] ?? 0; ?></div>
            <div class="db-stat-card__label">Pending Complaints</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--amber"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>

    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('allComplaintsSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--rose">
            <i class="fas fa-comments"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['total_complaints'] ?? 0; ?></div>
            <div class="db-stat-card__label">All Complaints</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--rose"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>

    <?php if (isset($stats['pending_requests'])): ?>
    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('pendingRequestsSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--indigo">
            <i class="fas fa-file-invoice"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['pending_requests']; ?></div>
            <div class="db-stat-card__label">Pending Requests</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--indigo"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
    <?php endif; ?>

    <div class="db-stat-card db-stat-card--clickable" onclick="toggleSection('allResidentsSection', this)">
        <div class="db-stat-card__icon db-stat-card__icon--teal">
            <i class="fas fa-users"></i>
        </div>
        <div class="db-stat-card__body">
            <div class="db-stat-card__num"><?php echo $stats['total_residents'] ?? 0; ?></div>
            <div class="db-stat-card__label">Total Residents</div>
        </div>
        <div class="db-stat-card__sparkline db-stat-card__sparkline--teal"></div>
        <div class="db-stat-card__hint"><i class="fas fa-eye"></i></div>
    </div>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════
     MAIN CONTENT GRID — Two columns on large screens
═══════════════════════════════════════════ -->
<div class="db-grid">

    <!-- ── LEFT / MAIN COLUMN ── -->
    <div class="db-grid__main">

        <!-- ── ALL RESIDENTS (Admin/Staff, toggled) ── -->
        <?php if ($user_role !== 'Resident'): ?>
        <div class="db-panel" id="allResidentsSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-users"></i></span>
                    <h2>All Residents</h2>
                </div>
                <div class="db-panel__actions">
                    <button class="db-btn db-btn--ghost db-btn--sm" onclick="filterResidents('all')">All (<?php echo count($all_residents); ?>)</button>
                    <button class="db-btn db-btn--ghost db-btn--sm" onclick="filterResidents('1')">
                        <i class="fas fa-check-circle" style="color:var(--db-success)"></i> Verified (<?php echo count($verified_residents); ?>)
                    </button>
                    <button class="db-btn db-btn--ghost db-btn--sm" onclick="filterResidents('0')">
                        <i class="fas fa-clock" style="color:var(--db-amber)"></i> Pending (<?php echo count($unverified_residents_arr); ?>)
                    </button>
                    <a href="../residents/manage.php" class="db-btn db-btn--primary db-btn--sm">
                        <i class="fas fa-cog"></i> Manage
                    </a>
                </div>
            </div>
            <?php if (!empty($all_residents)): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>Name</th><th>Email</th><th>Contact</th><th>Address</th><th>Status</th><th>Joined</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="allResidentsTableBody">
                        <?php foreach ($all_residents as $resident): ?>
                        <tr data-verified="<?php echo $resident['is_verified']; ?>">
                            <td><span class="db-id">#<?php echo str_pad($resident['resident_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong></td>
                            <td><span class="db-text-sm"><?php echo htmlspecialchars($resident['email'] ?? 'N/A'); ?></span></td>
                            <td><?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?></td>
                            <td><span class="db-text-sm"><?php $a = $resident['address'] ?? 'N/A'; echo htmlspecialchars(strlen($a) > 28 ? substr($a,0,28).'…' : $a); ?></span></td>
                            <td>
                                <?php if ($resident['is_verified']): ?>
                                    <span class="db-badge db-badge--success"><i class="fas fa-check-circle"></i> Verified</span>
                                <?php else: ?>
                                    <span class="db-badge db-badge--warning"><i class="fas fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($resident['created_at'])); ?></span></td>
                            <td>
                                <div class="db-btn-group">
                                    <a href="../residents/view.php?id=<?php echo $resident['resident_id']; ?>" class="db-icon-btn db-icon-btn--info" title="View"><i class="fas fa-eye"></i></a>
                                    <a href="../residents/edit.php?id=<?php echo $resident['resident_id']; ?>" class="db-icon-btn db-icon-btn--primary" title="Edit"><i class="fas fa-edit"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer">
                <a href="../residents/manage.php" class="db-btn db-btn--outline db-btn--sm"><i class="fas fa-users"></i> View All Residents</a>
            </div>
            <?php else: ?>
            <div class="db-empty"><i class="fas fa-users"></i><p>No residents registered yet</p></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <!-- ── PENDING INCIDENTS (Admin/Staff, toggled) ── -->
        <?php if ($user_role !== 'Resident'): ?>
        <div class="db-panel" id="pendingIncidentsSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-exclamation-triangle"></i></span>
                    <h2>Pending Incidents</h2>
                </div>
                <a href="../incidents/view-incidents.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
            </div>
            <?php if (!empty($pending_incidents)): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr><th>Ref #</th><th>Date</th><th>Reporter</th><th>Type</th><th>Severity</th><th>Status</th><th>Responder</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending_incidents as $incident):
                        $icon_map = ['Crime'=>'fa-user-secret','Fire'=>'fa-fire','Accident'=>'fa-car-crash','Health Emergency'=>'fa-ambulance','Violation'=>'fa-gavel','Natural Disaster'=>'fa-cloud-showers-heavy'];
                        $iicon = $icon_map[$incident['incident_type']] ?? 'fa-exclamation-triangle';
                        $sev_map = ['Low'=>'db-badge--success','Medium'=>'db-badge--warning','High'=>'db-badge--danger','Critical'=>'db-badge--dark'];
                        $stat_map = ['Pending'=>'db-badge--warning','Under Investigation'=>'db-badge--info','In Progress'=>'db-badge--primary','Resolved'=>'db-badge--success','Closed'=>'db-badge--muted'];
                    ?>
                    <tr>
                        <td><span class="db-id"><?php echo htmlspecialchars($incident['reference_no']); ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($incident['date_reported'])); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?></strong></td>
                        <td><i class="fas <?php echo $iicon; ?> me-1"></i><?php echo htmlspecialchars($incident['incident_type']); ?></td>
                        <td><span class="db-badge <?php echo $sev_map[$incident['severity']] ?? 'db-badge--muted'; ?>"><?php echo htmlspecialchars($incident['severity']); ?></span></td>
                        <td><span class="db-badge <?php echo $stat_map[$incident['status']] ?? 'db-badge--muted'; ?>"><?php echo htmlspecialchars($incident['status']); ?></span></td>
                        <td><?php echo !empty($incident['responder_name']) ? htmlspecialchars($incident['responder_name']) : '<span class="db-text-muted">Unassigned</span>'; ?></td>
                        <td><a href="../incidents/incident-details.php?id=<?php echo $incident['incident_id']; ?>" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer"><a href="../incidents/view-incidents.php" class="db-btn db-btn--outline db-btn--sm">View All Incidents</a></div>
            <?php else: ?>
            <div class="db-empty"><i class="fas fa-check-circle" style="color:var(--db-success)"></i><p>No pending incidents — all clear!</p></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <!-- ── ALL COMPLAINTS (Admin/Staff, toggled) ── -->
        <?php if ($user_role !== 'Resident'): ?>
        <div class="db-panel" id="allComplaintsSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-comments"></i></span>
                    <h2>All Complaints</h2>
                </div>
                <a href="../complaints/view-complaints.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> Manage</a>
            </div>
            <?php if (!empty($all_complaints)): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr><th>Complaint #</th><th>Date</th><th>Complainant</th><th>Subject</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_complaints as $c):
                        $cat_icon = ['Noise'=>'fa-volume-up','Garbage'=>'fa-trash','Property'=>'fa-home','Infrastructure'=>'fa-road','Public Safety'=>'fa-shield-alt','Services'=>'fa-concierge-bell'];
                        $ci = $cat_icon[$c['category']] ?? 'fa-comment';
                        $pri_map = ['Low'=>'db-badge--success','Medium'=>'db-badge--warning','High'=>'db-badge--danger','Urgent'=>'db-badge--urgent'];
                        $st_map = ['Pending'=>'db-badge--warning','In Progress'=>'db-badge--primary','Resolved'=>'db-badge--success','Closed'=>'db-badge--muted'];
                        $subj = $c['subject']; $subj_short = strlen($subj)>38 ? substr($subj,0,38).'…' : $subj;
                    ?>
                    <tr>
                        <td><span class="db-id"><?php echo htmlspecialchars($c['complaint_number']); ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($c['complaint_date'])); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($c['complainant_name'] ?? 'Unknown'); ?></strong></td>
                        <td><span title="<?php echo htmlspecialchars($subj); ?>"><?php echo htmlspecialchars($subj_short); ?></span></td>
                        <td><i class="fas <?php echo $ci; ?> me-1"></i><?php echo htmlspecialchars($c['category'] ?? 'N/A'); ?></td>
                        <td><span class="db-badge <?php echo $pri_map[trim($c['priority'])] ?? 'db-badge--muted'; ?>"><?php echo htmlspecialchars($c['priority']); ?></span></td>
                        <td><span class="db-badge <?php echo $st_map[trim($c['status'])] ?? 'db-badge--muted'; ?>"><?php echo htmlspecialchars($c['status']); ?></span></td>
                        <td><?php echo !empty($c['assigned_to_name']) ? htmlspecialchars($c['assigned_to_name']) : '<span class="db-text-muted">Unassigned</span>'; ?></td>
                        <td><a href="../complaints/complaint-details.php?id=<?php echo $c['complaint_id']; ?>" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer"><a href="../complaints/view-complaints.php" class="db-btn db-btn--outline db-btn--sm">View All Complaints</a></div>
            <?php else: ?>
            <div class="db-empty"><i class="fas fa-inbox"></i><p>No complaints filed yet</p></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <!-- ── PENDING DOCUMENT REQUESTS (Admin/Staff, toggled) ── -->
        <?php if ($user_role !== 'Resident' && !empty($pending_document_requests)): ?>
        <div class="db-panel" id="pendingRequestsSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-file-invoice"></i></span>
                    <h2>Pending Document Requests</h2>
                </div>
                <a href="../requests/admin-manage-requests.php?tab=manage&status=Pending" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
            </div>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr><th>Request ID</th><th>Date</th><th>Resident</th><th>Document Type</th><th>Purpose</th><th>Fee</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending_document_requests as $req): ?>
                    <tr>
                        <td><span class="db-id">#<?php echo str_pad($req['request_id'], 5, '0', STR_PAD_LEFT); ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($req['request_date'])); ?></span></td>
                        <td>
                            <strong><?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong><br>
                            <span class="db-text-sm"><?php echo htmlspecialchars($req['email'] ?? 'N/A'); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($req['request_type_name'] ?? 'N/A'); ?></td>
                        <td><?php $p = $req['purpose']; echo htmlspecialchars(strlen($p)>38 ? substr($p,0,38).'…' : $p); ?></td>
                        <td><?php echo $req['fee'] > 0 ? '<strong style="color:var(--db-success)">₱'.number_format($req['fee'],2).'</strong>' : '<span class="db-badge db-badge--muted">Free</span>'; ?></td>
                        <td><a href="../requests/admin-manage-requests.php?tab=manage&status=Pending#request-<?php echo $req['request_id']; ?>" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer"><a href="../requests/admin-manage-requests.php?tab=manage&status=Pending" class="db-btn db-btn--outline db-btn--sm">View All Pending Requests</a></div>
        </div>
        <?php endif; ?>


        <!-- ── PENDING COMPLAINTS (Admin/Staff, toggled) ── -->
        <?php if ($user_role !== 'Resident'): ?>
        <div class="db-panel" id="pendingComplaintsSection" style="display:none;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-clipboard-list"></i></span>
                    <h2>Pending Complaints</h2>
                </div>
                <a href="../complaints/view-complaints.php?status=Pending" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-list"></i> View All</a>
            </div>
            <?php if (!empty($pending_complaints)): ?>
            <div class="db-table-wrap">
                <table class="db-table">
                    <thead>
                        <tr><th>Complaint #</th><th>Date</th><th>Complainant</th><th>Subject</th><th>Category</th><th>Priority</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending_complaints as $c):
                        $cat_icon = ['Noise'=>'fa-volume-up','Garbage'=>'fa-trash','Property'=>'fa-home','Infrastructure'=>'fa-road','Public Safety'=>'fa-shield-alt','Services'=>'fa-concierge-bell'];
                        $ci = $cat_icon[$c['category']] ?? 'fa-comment';
                        $pri_map = ['Low'=>'db-badge--success','Medium'=>'db-badge--warning','High'=>'db-badge--danger','Urgent'=>'db-badge--urgent'];
                        $subj = $c['subject']; $subj_short = strlen($subj)>38 ? substr($subj,0,38).'…' : $subj;
                    ?>
                    <tr>
                        <td><span class="db-id"><?php echo htmlspecialchars($c['complaint_number']); ?></span></td>
                        <td><span class="db-text-sm"><?php echo date('M d, Y', strtotime($c['complaint_date'])); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($c['complainant_name'] ?? 'Unknown'); ?></strong></td>
                        <td><span title="<?php echo htmlspecialchars($subj); ?>"><?php echo htmlspecialchars($subj_short); ?></span></td>
                        <td><i class="fas <?php echo $ci; ?> me-1"></i><?php echo htmlspecialchars($c['category'] ?? 'N/A'); ?></td>
                        <td><span class="db-badge <?php echo $pri_map[trim($c['priority'])] ?? 'db-badge--muted'; ?>"><?php echo htmlspecialchars($c['priority']); ?></span></td>
                        <td><a href="../complaints/complaint-details.php?id=<?php echo $c['complaint_id']; ?>" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="db-panel__footer"><a href="../complaints/view-complaints.php?status=Pending" class="db-btn db-btn--outline db-btn--sm">View All Pending Complaints</a></div>
            <?php else: ?>
            <div class="db-empty"><i class="fas fa-check-circle" style="color:var(--db-success)"></i><p>No pending complaints — all clear!</p></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <!-- ══════════════════════════════════════
             ANNOUNCEMENTS — Visible to ALL roles
        ══════════════════════════════════════ -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--amber"><i class="fas fa-bullhorn"></i></span>
                    <h2>Announcements</h2>
                </div>
                <?php if ($user_role !== 'Resident'): ?>
                <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addAnnouncementModal')">
                    <i class="fas fa-plus"></i> Post
                </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($announcements)): ?>
            <div class="db-announcements">
                <?php foreach ($announcements as $ann):
                    $prio = $ann['priority'] ?? 'normal';
                    $prio_icon = ['urgent'=>'fa-exclamation-circle','high'=>'fa-exclamation-triangle','normal'=>'fa-info-circle'];
                    $icon = $prio_icon[$prio] ?? 'fa-info-circle';
                ?>
                <div class="db-ann db-ann--<?php echo $prio; ?>">
                    <div class="db-ann__stripe"></div>
                    <div class="db-ann__body">
                        <div class="db-ann__top">
                            <div class="db-ann__meta">
                                <i class="fas <?php echo $icon; ?> db-ann__icon"></i>
                                <div>
                                    <div class="db-ann__title"><?php echo htmlspecialchars($ann['title']); ?></div>
                                    <span class="db-badge db-ann__prio-badge db-badge--<?php echo $prio === 'urgent' ? 'urgent' : ($prio === 'high' ? 'warning' : 'info'); ?>">
                                        <?php echo strtoupper($prio); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ($user_role !== 'Resident'): ?>
                            <div class="db-ann__controls">
                                <button class="db-icon-btn" onclick='editAnnouncement(<?php echo json_encode($ann); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="toggle_announcement">
                                    <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                    <input type="hidden" name="is_active" value="0">
                                    <button type="submit" class="db-icon-btn" title="Hide"><i class="fas fa-eye-slash"></i></button>
                                </form>
                                <button class="db-icon-btn db-icon-btn--danger" onclick='openDeleteAnnouncementModal(<?php echo $ann["id"]; ?>, <?php echo json_encode($ann["title"]); ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="db-ann__content"><?php echo nl2br(htmlspecialchars($ann['content'])); ?></div>
                        <div class="db-ann__date"><i class="far fa-clock"></i> <?php echo date('F j, Y g:i A', strtotime($ann['created_at'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="db-empty">
                <i class="fas fa-bullhorn"></i>
                <p><?php echo ($user_role !== 'Resident') ? 'No announcements yet. Post one to keep residents informed.' : 'No announcements yet. Check back later.'; ?></p>
                <?php if ($user_role !== 'Resident'): ?>
                <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addAnnouncementModal')"><i class="fas fa-plus"></i> Post Announcement</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>


        <!-- ══════════════════════════════════════
             ACTIVITIES — Visible to ALL roles
        ══════════════════════════════════════ -->
        <?php if (!empty($activities) || $user_role !== 'Resident'): ?>
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--teal"><i class="fas fa-calendar-check"></i></span>
                    <h2>Barangay Activities</h2>
                </div>
                <?php if ($user_role !== 'Resident'): ?>
                <div style="display:flex;gap:0.5rem">
                    <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addActivityModal')"><i class="fas fa-plus"></i> Add</button>
                    <a href="../activities/manage.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-list"></i> All</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($activities)): ?>
            <div class="db-timeline">
                <?php foreach ($activities as $act):
                    $diff = floor((strtotime($act['activity_date']) - time()) / 86400);
                    if ($diff > 0) { $time_text = "In $diff day" . ($diff > 1 ? 's' : ''); $tc = 'upcoming'; }
                    elseif ($diff == 0) { $time_text = 'Today'; $tc = 'today'; }
                    else { $da = abs($diff); $time_text = "$da day" . ($da > 1 ? 's' : '') . ' ago'; $tc = 'past'; }
                ?>
                <div class="db-timeline__item db-timeline__item--<?php echo $tc; ?>">
                    <div class="db-timeline__dot"></div>
                    <div class="db-timeline__card">
                        <div class="db-timeline__top">
                            <div>
                                <div class="db-timeline__title"><?php echo htmlspecialchars($act['title']); ?></div>
                                <div class="db-timeline__meta">
                                    <span><i class="far fa-calendar"></i> <?php echo date('F j, Y', strtotime($act['activity_date'])); ?></span>
                                    <span class="db-badge db-badge--<?php echo $tc === 'upcoming' ? 'info' : ($tc === 'today' ? 'warning' : 'muted'); ?>"><?php echo $time_text; ?></span>
                                    <?php if (!empty($act['location'])): ?>
                                    <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($act['location']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($user_role !== 'Resident'): ?>
                            <div class="db-ann__controls">
                                <button class="db-icon-btn" onclick='editActivity(<?php echo json_encode($act); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action" value="toggle_activity">
                                    <input type="hidden" name="id" value="<?php echo $act['id']; ?>">
                                    <input type="hidden" name="is_active" value="0">
                                    <button type="submit" class="db-icon-btn" title="Hide"><i class="fas fa-eye-slash"></i></button>
                                </form>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this activity?')">
                                    <input type="hidden" name="action" value="delete_activity">
                                    <input type="hidden" name="id" value="<?php echo $act['id']; ?>">
                                    <button type="submit" class="db-icon-btn db-icon-btn--danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="db-timeline__desc"><?php echo nl2br(htmlspecialchars($act['description'])); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="db-empty">
                <i class="fas fa-calendar-check"></i>
                <p>No activities yet.</p>
                <?php if ($user_role !== 'Resident'): ?>
                <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addActivityModal')"><i class="fas fa-plus"></i> Add Activity</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /db-grid__main -->


    <!-- ── RIGHT SIDEBAR COLUMN ── -->
    <div class="db-grid__side">

        <!-- ══════════════════════════════════════
             VIDEO — Visible to ALL roles
        ══════════════════════════════════════ -->
        <?php if ($video_url || $user_role !== 'Resident'): ?>
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--rose"><i class="fas fa-video"></i></span>
                    <h2>Video Updates</h2>
                </div>
                <?php if ($user_role !== 'Resident'): ?>
                <a href="../media/manage.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-cog"></i></a>
                <?php endif; ?>
            </div>
            <?php if ($video_url): ?>
            <div class="db-video">
                <iframe src="<?php echo htmlspecialchars($video_url); ?>" allowfullscreen></iframe>
            </div>
            <?php if (!empty($video_data['caption'])): ?>
            <p class="db-video__caption"><?php echo htmlspecialchars($video_data['caption']); ?></p>
            <?php endif; ?>
            <?php if ($user_role !== 'Resident' && $video_data): ?>
            <div style="display:flex;gap:0.5rem;margin-top:0.75rem;flex-wrap:wrap;">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_media">
                    <input type="hidden" name="id" value="<?php echo $video_data['id']; ?>">
                    <input type="hidden" name="is_active" value="0">
                    <button type="submit" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-eye-slash"></i> Hide</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this video?')">
                    <input type="hidden" name="action" value="delete_media">
                    <input type="hidden" name="id" value="<?php echo $video_data['id']; ?>">
                    <button type="submit" class="db-btn db-btn--danger db-btn--sm"><i class="fas fa-trash"></i> Delete</button>
                </form>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="db-empty db-empty--sm">
                <i class="fas fa-video"></i>
                <p>No video available</p>
                <?php if ($user_role !== 'Resident'): ?>
                <a href="../media/manage.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> Add Video</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>


        <!-- ══════════════════════════════════════
             GALLERY — Visible to ALL roles
        ══════════════════════════════════════ -->
        <?php if (!empty($gallery_photos) || $user_role !== 'Resident'): ?>
        <div class="db-panel db-panel--compact">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <span class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-images"></i></span>
                    <h2>Photo Gallery</h2>
                </div>
                <?php if ($user_role !== 'Resident'): ?>
                <a href="../media/manage.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-cog"></i></a>
                <?php endif; ?>
            </div>
            <?php if (!empty($gallery_photos)): ?>
            <div class="db-gallery">
                <?php foreach ($gallery_photos as $photo): ?>
                <div class="db-gallery__item">
                    <img src="../../<?php echo htmlspecialchars($photo['file_path']); ?>"
                         alt="<?php echo htmlspecialchars($photo['caption']); ?>"
                         onclick="openImageModal('../../<?php echo htmlspecialchars($photo['file_path']); ?>', '<?php echo htmlspecialchars($photo['caption']); ?>')">
                    <div class="db-gallery__cap"><?php echo htmlspecialchars($photo['caption']); ?></div>
                    <?php if ($user_role !== 'Resident'): ?>
                    <div class="db-gallery__actions">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle_media">
                            <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                            <input type="hidden" name="is_active" value="0">
                            <button type="submit" title="Hide"><i class="fas fa-eye-slash"></i></button>
                        </form>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete photo?')">
                            <input type="hidden" name="action" value="delete_media">
                            <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                            <button type="submit" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="db-empty db-empty--sm">
                <i class="fas fa-images"></i>
                <p>No photos yet</p>
                <?php if ($user_role !== 'Resident'): ?>
                <a href="../media/manage.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> Upload</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /db-grid__side -->
</div><!-- /db-grid -->


<!-- ═══════════════════════════════════════════
     MODALS
═══════════════════════════════════════════ -->

<!-- Add Announcement -->
<div id="addAnnouncementModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-bullhorn"></i> Post Announcement</h3>
            <button class="db-modal__close" onclick="closeModal('addAnnouncementModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="add_announcement">
            <div class="db-field"><label>Title <span class="req">*</span></label><input type="text" name="title" class="db-input" placeholder="Announcement title" required></div>
            <div class="db-field"><label>Priority <span class="req">*</span></label>
                <select name="priority" class="db-input">
                    <option value="normal">Normal</option>
                    <option value="high">High Priority</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="db-field"><label>Content <span class="req">*</span></label><textarea name="content" class="db-input" rows="5" placeholder="Write your announcement…" required></textarea></div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-paper-plane"></i> Post Announcement</button>
        </form>
    </div>
</div>

<!-- Edit Announcement -->
<div id="editAnnouncementModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Announcement</h3>
            <button class="db-modal__close" onclick="closeModal('editAnnouncementModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="edit_announcement">
            <input type="hidden" name="id" id="edit_ann_id">
            <div class="db-field"><label>Title <span class="req">*</span></label><input type="text" name="title" id="edit_ann_title" class="db-input" required></div>
            <div class="db-field"><label>Priority <span class="req">*</span></label>
                <select name="priority" id="edit_ann_priority" class="db-input">
                    <option value="normal">Normal</option>
                    <option value="high">High Priority</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="db-field"><label>Content <span class="req">*</span></label><textarea name="content" id="edit_ann_content" class="db-input" rows="5" required></textarea></div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Announcement</button>
        </form>
    </div>
</div>

<!-- Delete Announcement Confirm -->
<div id="deleteAnnouncementModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--danger">
            <h3><i class="fas fa-trash"></i> Confirm Deletion</h3>
            <button class="db-modal__close" onclick="closeModal('deleteAnnouncementModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p>Are you sure you want to delete:</p>
            <div class="db-delete-target" id="delete_ann_title_display"></div>
            <p class="db-delete-warn"><i class="fas fa-info-circle"></i> This action cannot be undone.</p>
            <form method="POST" id="deleteAnnouncementForm">
                <input type="hidden" name="action" value="delete_announcement">
                <input type="hidden" name="id" id="delete_ann_id">
                <div style="display:flex;gap:0.75rem;margin-top:1.5rem">
                    <button type="button" class="db-btn db-btn--ghost db-btn--full" onclick="closeModal('deleteAnnouncementModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger db-btn--full"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Activity -->
<div id="addActivityModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-calendar-plus"></i> Add Activity</h3>
            <button class="db-modal__close" onclick="closeModal('addActivityModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="add_activity">
            <div class="db-field"><label>Title <span class="req">*</span></label><input type="text" name="title" class="db-input" placeholder="Activity title" required></div>
            <div class="db-field"><label>Description <span class="req">*</span></label><textarea name="description" class="db-input" rows="4" placeholder="Describe the activity…" required></textarea></div>
            <div class="db-field-row">
                <div class="db-field"><label>Date <span class="req">*</span></label><input type="date" name="activity_date" class="db-input" required></div>
                <div class="db-field"><label>Location</label><input type="text" name="location" class="db-input" placeholder="e.g. Barangay Hall"></div>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Save Activity</button>
        </form>
    </div>
</div>

<!-- Edit Activity -->
<div id="editActivityModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-edit"></i> Edit Activity</h3>
            <button class="db-modal__close" onclick="closeModal('editActivityModal')">×</button>
        </div>
        <form method="POST" class="db-modal__body">
            <input type="hidden" name="action" value="edit_activity">
            <input type="hidden" name="id" id="edit_act_id">
            <div class="db-field"><label>Title <span class="req">*</span></label><input type="text" name="title" id="edit_act_title" class="db-input" required></div>
            <div class="db-field"><label>Description <span class="req">*</span></label><textarea name="description" id="edit_act_desc" class="db-input" rows="4" required></textarea></div>
            <div class="db-field-row">
                <div class="db-field"><label>Date <span class="req">*</span></label><input type="date" name="activity_date" id="edit_act_date" class="db-input" required></div>
                <div class="db-field"><label>Location</label><input type="text" name="location" id="edit_act_loc" class="db-input"></div>
            </div>
            <button type="submit" class="db-btn db-btn--primary db-btn--full"><i class="fas fa-save"></i> Update Activity</button>
        </form>
    </div>
</div>

<!-- Image Viewer -->
<div id="imageModal" class="db-modal db-modal--img">
    <div class="db-imgview">
        <button class="db-imgview__close" onclick="closeModal('imageModal')">×</button>
        <img id="modalImage" src="" alt="">
        <div id="modalCaption" class="db-imgview__cap"></div>
    </div>
</div>


<script>
// ── Live Clock ──
setInterval(function() {
    const now = new Date();
    let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
    const ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const el = document.getElementById('db-live-time');
    if (el) el.textContent = `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')} ${ap}`;
}, 1000);

// ── Toggle Sections (one at a time) ──
const SECTIONS = ['pendingIncidentsSection','pendingComplaintsSection','allComplaintsSection','pendingRequestsSection','allResidentsSection'];
function toggleSection(id, triggerCard) {
    const target = document.getElementById(id);
    if (!target) return;
    const isOpen = target.style.display !== 'none';
    SECTIONS.forEach(sid => { const s = document.getElementById(sid); if (s) s.style.display = 'none'; });
    document.querySelectorAll('.db-stat-card--clickable').forEach(c => c.classList.remove('db-stat-card--active'));
    if (!isOpen) {
        target.style.display = 'block';
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (triggerCard) triggerCard.classList.add('db-stat-card--active');
    }
}

// ── Resident Filtering ──
function filterResidents(val) {
    document.querySelectorAll('#allResidentsTableBody tr').forEach(row => {
        row.style.display = (val === 'all' || row.dataset.verified === val) ? '' : 'none';
    });
}

// ── Modals ──
function openModal(id) {
    document.getElementById(id).classList.add('db-modal--open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('db-modal--open');
    document.body.style.overflow = '';
}
window.addEventListener('click', e => { if (e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.db-modal--open').forEach(m => closeModal(m.id)); });

// ── Announcement helpers ──
function openDeleteAnnouncementModal(id, title) {
    document.getElementById('delete_ann_id').value = id;
    document.getElementById('delete_ann_title_display').textContent = title;
    openModal('deleteAnnouncementModal');
}
function editAnnouncement(ann) {
    document.getElementById('edit_ann_id').value = ann.id;
    document.getElementById('edit_ann_title').value = ann.title;
    document.getElementById('edit_ann_content').value = ann.content;
    document.getElementById('edit_ann_priority').value = ann.priority || 'normal';
    openModal('editAnnouncementModal');
}

// ── Activity helpers ──
function editActivity(act) {
    document.getElementById('edit_act_id').value = act.id;
    document.getElementById('edit_act_title').value = act.title;
    document.getElementById('edit_act_desc').value = act.description;
    document.getElementById('edit_act_date').value = act.activity_date;
    document.getElementById('edit_act_loc').value = act.location || '';
    openModal('editActivityModal');
}

// ── Image Modal ──
function openImageModal(src, cap) {
    document.getElementById('modalImage').src = src;
    document.getElementById('modalCaption').textContent = cap;
    openModal('imageModal');
}

// ── Auto-dismiss alerts ──
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>