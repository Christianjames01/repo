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
    // Check if user has permission
    if ($user_role !== 'Admin' && $user_role !== 'Staff' && $user_role !== 'Super Admin') {
        $error_message = "You don't have permission to perform this action.";
    } elseif (isset($_POST['action'])) {
        
        // ANNOUNCEMENT ACTIONS
        if ($_POST['action'] === 'add_announcement') {
            try {
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $priority = isset($_POST['priority']) ? $_POST['priority'] : 'normal';
                $created_by = $_SESSION['user_id'];
                
                $stmt = $conn->prepare("INSERT INTO tbl_announcements (title, content, priority, created_by) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $title, $content, $priority, $created_by);
                if ($stmt->execute()) {
                    $success_message = "Announcement posted successfully!";
                } else {
                    $error_message = "Error posting announcement: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'edit_announcement') {
            try {
                $id = intval($_POST['id']);
                $title = trim($_POST['title']);
                $content = trim($_POST['content']);
                $priority = isset($_POST['priority']) ? $_POST['priority'] : 'normal';
                
                $stmt = $conn->prepare("UPDATE tbl_announcements SET title = ?, content = ?, priority = ? WHERE id = ?");
                $stmt->bind_param("sssi", $title, $content, $priority, $id);
                if ($stmt->execute()) {
                    $success_message = "Announcement updated successfully!";
                } else {
                    $error_message = "Error updating announcement: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'toggle_announcement') {
            try {
                $id = intval($_POST['id']);
                $is_active = intval($_POST['is_active']);
                
                $stmt = $conn->prepare("UPDATE tbl_announcements SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $id);
                if ($stmt->execute()) {
                    $success_message = $is_active ? "Announcement activated!" : "Announcement hidden!";
                } else {
                    $error_message = "Error toggling announcement: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'delete_announcement') {
            try {
                $id = intval($_POST['id']);
                
                $stmt = $conn->prepare("DELETE FROM tbl_announcements WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $success_message = "Announcement deleted successfully!";
                } else {
                    $error_message = "Error deleting announcement: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
        
        // MEDIA ACTIONS
        elseif ($_POST['action'] === 'toggle_media') {
            try {
                $id = intval($_POST['id']);
                $is_active = intval($_POST['is_active']);
                
                $stmt = $conn->prepare("UPDATE tbl_barangay_media SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $id);
                if ($stmt->execute()) {
                    $success_message = $is_active ? "Media activated!" : "Media hidden!";
                } else {
                    $error_message = "Error toggling media: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'delete_media') {
            try {
                $id = intval($_POST['id']);
                
                // Get file path to delete physical file
                $stmt = $conn->prepare("SELECT file_path FROM tbl_barangay_media WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $media = $result->fetch_assoc();
                $stmt->close();
                
                if ($media && $media['file_path']) {
                    $file_to_delete = '../../' . $media['file_path'];
                    if (file_exists($file_to_delete)) {
                        unlink($file_to_delete);
                    }
                }
                
                $stmt = $conn->prepare("DELETE FROM tbl_barangay_media WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $success_message = "Media deleted successfully!";
                } else {
                    $error_message = "Error deleting media: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }

        // ACTIVITY ACTIONS
        elseif ($_POST['action'] === 'add_activity') {
            try {
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $activity_date = $_POST['activity_date'];
                $location = isset($_POST['location']) ? trim($_POST['location']) : '';
                $created_by = $_SESSION['user_id'];
                
                $stmt = $conn->prepare("INSERT INTO tbl_barangay_activities (title, description, activity_date, location, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssi", $title, $description, $activity_date, $location, $created_by);
                if ($stmt->execute()) {
                    $success_message = "Activity added successfully!";
                } else {
                    $error_message = "Error adding activity: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'edit_activity') {
            try {
                $id = intval($_POST['id']);
                $title = trim($_POST['title']);
                $description = trim($_POST['description']);
                $activity_date = $_POST['activity_date'];
                $location = isset($_POST['location']) ? trim($_POST['location']) : '';
                
                $stmt = $conn->prepare("UPDATE tbl_barangay_activities SET title = ?, description = ?, activity_date = ?, location = ? WHERE id = ?");
                $stmt->bind_param("ssssi", $title, $description, $activity_date, $location, $id);
                if ($stmt->execute()) {
                    $success_message = "Activity updated successfully!";
                } else {
                    $error_message = "Error updating activity: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'toggle_activity') {
            try {
                $id = intval($_POST['id']);
                $is_active = intval($_POST['is_active']);
                
                $stmt = $conn->prepare("UPDATE tbl_barangay_activities SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_active, $id);
                if ($stmt->execute()) {
                    $success_message = $is_active ? "Activity activated!" : "Activity hidden!";
                } else {
                    $error_message = "Error toggling activity: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'delete_activity') {
            try {
                $id = intval($_POST['id']);
                
                $stmt = $conn->prepare("DELETE FROM tbl_barangay_activities WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $success_message = "Activity deleted successfully!";
                } else {
                    $error_message = "Error deleting activity: " . $stmt->error;
                }
                $stmt->close();
            } catch (Exception $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
        
        // Redirect to clear POST data (PRG pattern)
        if ($success_message || $error_message) {
            $_SESSION['temp_success'] = $success_message;
            $_SESSION['temp_error'] = $error_message;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Get messages from session (after redirect)
if (isset($_SESSION['temp_success'])) {
    $success_message = $_SESSION['temp_success'];
    unset($_SESSION['temp_success']);
}
if (isset($_SESSION['temp_error'])) {
    $error_message = $_SESSION['temp_error'];
    unset($_SESSION['temp_error']);
}

// Resident stats
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

// Admin/Staff stats - UPDATED TO INCLUDE TOTAL COMPLAINTS
} else {
    $columns = [
        "pending_incidents" => "SELECT COUNT(*) FROM tbl_incidents WHERE status NOT IN ('Resolved','Closed')",
        "pending_complaints" => "SELECT COUNT(*) FROM tbl_complaints WHERE TRIM(status) = 'Pending'",
        "total_complaints" => "SELECT COUNT(*) FROM tbl_complaints",
        "unverified_residents" => tableExists($conn,'tbl_residents') ? "SELECT COUNT(*) FROM tbl_residents WHERE is_verified=0" : "SELECT 0",
        "total_residents" => tableExists($conn,'tbl_residents') ? "SELECT COUNT(*) FROM tbl_residents" : "SELECT 0"
    ];
    
    // NEW: Add check for tbl_requests table
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

// Fetch ALL complaints for dashboard (Admin/Staff only) - NEW SECTION
$all_complaints = [];
if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin') {
    if (tableExists($conn, 'tbl_complaints')) {
        // Check which date column exists
        $date_columns = ['created_at', 'date_filed', 'date_created'];
        $date_column = 'created_at'; // default
        foreach ($date_columns as $col) {
            if (columnExists($conn, 'tbl_complaints', $col)) {
                $date_column = $col;
                break;
            }
        }
        
        $complaints_stmt = $conn->prepare("
            SELECT c.complaint_id, c.complaint_number, c.subject, c.description, c.category, 
                   c.priority, c.status, c.resident_id, c.assigned_to,
                   c.$date_column as complaint_date,
                   CONCAT(r.first_name, ' ', r.last_name) as complainant_name,
                   u.username as assigned_to_name
            FROM tbl_complaints c
            LEFT JOIN tbl_residents r ON c.resident_id = r.resident_id
            LEFT JOIN tbl_users u ON c.assigned_to = u.user_id
            ORDER BY c.$date_column DESC
            LIMIT 50
        ");
        $complaints_stmt->execute();
        $complaints_result = $complaints_stmt->get_result();
        while ($row = $complaints_result->fetch_assoc()) {
            $all_complaints[] = $row;
        }
        $complaints_stmt->close();
    }
}

// Fetch pending document requests (Admin/Staff only)
$pending_document_requests = [];
if (($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin') && tableExists($conn, 'tbl_requests')) {
    $stmt = $conn->prepare("
        SELECT r.*, 
               res.first_name, res.last_name, res.email,
               rt.request_type_name, rt.fee
        FROM tbl_requests r
        INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        WHERE r.status = 'Pending'
        ORDER BY r.request_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pending_document_requests[] = $row;
    }
    $stmt->close();
}

// Fetch pending complaints (Admin/Staff only)
$pending_complaints = [];
if (($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin') && tableExists($conn, 'tbl_complaints')) {
    // Check which date column exists
    $date_columns = ['created_at', 'date_filed', 'date_created'];
    $date_column = 'created_at'; // default
    foreach ($date_columns as $col) {
        if (columnExists($conn, 'tbl_complaints', $col)) {
            $date_column = $col;
            break;
        }
    }
    
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
        ORDER BY c.$date_column DESC
        LIMIT 10
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pending_complaints[] = $row;
    }
    $stmt->close();
}

// Fetch ALL active announcements
$announcements = [];
if (tableExists($conn, 'tbl_announcements')) {
    // Check if priority column exists
    $check_column = $conn->query("SHOW COLUMNS FROM tbl_announcements LIKE 'priority'");
    $has_priority = $check_column->num_rows > 0;
    
    if ($has_priority) {
        $stmt = $conn->prepare("SELECT * FROM tbl_announcements WHERE is_active = 1 ORDER BY 
            CASE priority 
                WHEN 'urgent' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'normal' THEN 3 
            END, created_at DESC LIMIT 5");
    } else {
        $stmt = $conn->prepare("SELECT * FROM tbl_announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

// Fetch video URL
$video_url = null;
$video_data = null;
if (tableExists($conn, 'tbl_barangay_media')) {
    $stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'video' AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $video_data = $result->fetch_assoc();
    if ($video_data) {
        $video_url = $video_data['video_url'];
    }
    $stmt->close();
}

// Fetch gallery photos
$gallery_photos = [];
if (tableExists($conn, 'tbl_barangay_media')) {
    $stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'photo' AND is_active = 1 ORDER BY created_at DESC LIMIT 12");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $gallery_photos[] = $row;
    }
    $stmt->close();
}

// Fetch activities
$activities = [];
if (tableExists($conn, 'tbl_barangay_activities')) {
    // Check if location column exists
    $check_column = $conn->query("SHOW COLUMNS FROM tbl_barangay_activities LIKE 'location'");
    $has_location = $check_column->num_rows > 0;
    
    $stmt = $conn->prepare("SELECT * FROM tbl_barangay_activities WHERE is_active = 1 ORDER BY activity_date DESC LIMIT 8");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!$has_location && !isset($row['location'])) {
            $row['location'] = ''; // Add empty location if column doesn't exist
        }
        $activities[] = $row;
    }
    $stmt->close();
}

// Set extra CSS for header to include
$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css">';

include '../../includes/header.php';
?>

<!-- Dashboard Hero Section -->
<div class="dashboard-hero">
    <div class="hero-content">
        <h1 class="hero-title">Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?> 👋</h1>
        <p class="hero-subtitle">Here's what's happening in your barangay today</p>
    </div>
    <div class="hero-date">
        <i class="fas fa-calendar-alt"></i>
        <span><?php echo date('l, F j, Y'); ?></span>
        <span style="margin-left: 1rem;">
            <i class="fas fa-clock"></i>
            <span id="current-time"><?php echo date('g:i:s A'); ?></span>
        </span>
    </div>
</div>

<?php if ($success_message): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($success_message); ?></span>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($error_message); ?></span>
</div>
<?php endif; ?>

<!-- Quick Stats Cards (Only for Admin/Staff) - UPDATED WITH ALL COMPLAINTS CARD -->
<?php if ($user_role !== 'Resident'): ?>
<div class="stats-grid">
    <div class="stat-card stat-primary" style="cursor: pointer;" onclick="togglePendingIncidents()">
        <div class="stat-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-details">
            <h3><?php echo $stats['pending_incidents'] ?? 0; ?></h3>
            <p>Pending Incidents</p>
        </div>
    </div>
    <div class="stat-card stat-warning" style="cursor: pointer;" onclick="togglePendingComplaints()">
        <div class="stat-icon">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div class="stat-details">
            <h3><?php echo $stats['pending_complaints'] ?? 0; ?></h3>
            <p>Pending Complaints</p>
        </div>
    </div>
    <!-- NEW: All Complaints Card -->
    <div class="stat-card stat-danger" style="cursor: pointer;" onclick="toggleAllComplaints()">
        <div class="stat-icon">
            <i class="fas fa-comments"></i>
        </div>
        <div class="stat-details">
            <h3><?php echo $stats['total_complaints'] ?? 0; ?></h3>
            <p>All Complaints</p>
        </div>
    </div>
    <?php if (isset($stats['pending_requests'])): ?>
    <div class="stat-card stat-info" style="cursor: pointer;" onclick="togglePendingRequests()">
        <div class="stat-icon">
            <i class="fas fa-file-invoice"></i>
        </div>
        <div class="stat-details">
            <h3><?php echo $stats['pending_requests']; ?></h3>
            <p>Pending Requests</p>
        </div>
    </div>
    <?php endif; ?>
    <div class="stat-card stat-success" style="cursor: pointer;" onclick="toggleAllResidents()">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-details">
            <h3><?php echo $stats['total_residents'] ?? 0; ?></h3>
            <p>Total Residents</p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- All Residents Section (Admin/Staff only) -->
<?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
<?php
// Fetch all residents for dashboard
$all_residents = [];
if (tableExists($conn, 'tbl_residents')) {
    $residents_stmt = $conn->prepare("SELECT * FROM tbl_residents ORDER BY created_at DESC LIMIT 50");
    $residents_stmt->execute();
    $residents_result = $residents_stmt->get_result();
    while ($row = $residents_result->fetch_assoc()) {
        $all_residents[] = $row;
    }
    $residents_stmt->close();
}

// Get verified and unverified counts
$verified_residents = array_filter($all_residents, function($r) { return $r['is_verified'] == 1; });
$unverified_residents = array_filter($all_residents, function($r) { return $r['is_verified'] == 0; });
?>

<div class="announcement-section" id="allResidentsSection" style="display: none;">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-users"></i>
            <h2>All Residents</h2>
        </div>
        <div style="display: flex; gap: 0.5rem;">
            <button class="add-btn" onclick="showVerifiedResidents()">
                <i class="fas fa-check-circle me-1"></i>Verified (<?php echo count($verified_residents); ?>)
            </button>
            <button class="add-btn" onclick="showUnverifiedResidents()">
                <i class="fas fa-clock me-1"></i>Unverified (<?php echo count($unverified_residents); ?>)
            </button>
            <a href="../residents/manage.php" class="add-btn">
                <i class="fas fa-cog"></i> Manage Residents
            </a>
        </div>
    </div>

    <?php if (!empty($all_residents)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="allResidentsTableBody">
                        <?php foreach ($all_residents as $resident): ?>
                            <tr data-verified="<?php echo $resident['is_verified']; ?>">
                                <td><strong class="text-primary">#<?php echo str_pad($resident['resident_id'], 4, '0', STR_PAD_LEFT); ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($resident['first_name'] . ' ' . $resident['last_name']); ?></strong></td>
                                <td><small><?php echo htmlspecialchars($resident['email'] ?? 'N/A'); ?></small></td>
                                <td><?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?></td>
                                <td>
                                    <small><?php 
                                        $address = $resident['address'] ?? 'N/A';
                                        echo htmlspecialchars(strlen($address) > 30 ? substr($address, 0, 30) . '...' : $address); 
                                    ?></small>
                                </td>
                                <td>
                                    <?php if ($resident['is_verified']): ?>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Verified
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">
                                            <i class="fas fa-clock me-1"></i>Unverified
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?php echo date('M d, Y', strtotime($resident['created_at'])); ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="../residents/view.php?id=<?php echo $resident['resident_id']; ?>" 
                                           class="btn btn-info btn-sm" 
                                           title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="../residents/edit.php?id=<?php echo $resident['resident_id']; ?>" 
                                           class="btn btn-primary btn-sm" 
                                           title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-3">
                <a href="../residents/manage.php" class="btn btn-outline-primary">
                    <i class="fas fa-users me-2"></i>View All Residents
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-users fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Residents Found</h5>
            <p class="text-muted mb-0">No residents registered yet</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Pending Incidents Section (Admin/Staff only) -->
<?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
<?php
// Fetch pending incidents for dashboard
$pending_incidents = [];
if (tableExists($conn, 'tbl_incidents')) {
    $incidents_stmt = $conn->prepare("
        SELECT i.*, 
               CONCAT(r.first_name, ' ', r.last_name) as reporter_name,
               u.username as responder_name
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        LEFT JOIN tbl_users u ON i.responder_id = u.user_id
        WHERE i.status NOT IN ('Resolved', 'Closed')
        ORDER BY i.date_reported DESC
        LIMIT 10
    ");
    $incidents_stmt->execute();
    $incidents_result = $incidents_stmt->get_result();
    while ($row = $incidents_result->fetch_assoc()) {
        $pending_incidents[] = $row;
    }
    $incidents_stmt->close();
}
?>

<div class="announcement-section" id="pendingIncidentsSection" style="display: none;">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-exclamation-triangle"></i>
            <h2>Pending Incidents</h2>
        </div>
        <a href="../incidents/view-incidents.php" class="add-btn">
            <i class="fas fa-list"></i> View All Incidents
        </a>
    </div>

    <?php if (!empty($pending_incidents)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Reference #</th>
                            <th>Date</th>
                            <th>Reporter</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Responder</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_incidents as $incident): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary">
                                        <?php echo htmlspecialchars($incident['reference_no']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y h:i A', strtotime($incident['date_reported'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($incident['reporter_name'] ?? 'Unknown'); ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $icon_class = 'fa-exclamation-triangle';
                                    switch($incident['incident_type']) {
                                        case 'Crime': $icon_class = 'fa-user-secret'; break;
                                        case 'Fire': $icon_class = 'fa-fire'; break;
                                        case 'Accident': $icon_class = 'fa-car-crash'; break;
                                        case 'Health Emergency': $icon_class = 'fa-ambulance'; break;
                                        case 'Violation': $icon_class = 'fa-gavel'; break;
                                        case 'Natural Disaster': $icon_class = 'fa-cloud-showers-heavy'; break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon_class; ?> me-1"></i>
                                    <?php echo htmlspecialchars($incident['incident_type']); ?>
                                </td>
                                <td>
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                    <?php 
                                    $location = $incident['location'];
                                    if (strlen($location) > 30) {
                                        echo '<span data-bs-toggle="tooltip" title="' . htmlspecialchars($location) . '">';
                                        echo htmlspecialchars(substr($location, 0, 30)) . '...';
                                        echo '</span>';
                                    } else {
                                        echo htmlspecialchars($location);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $severity = $incident['severity'];
                                    $severity_badges = [
                                        'Low' => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Low</span>',
                                        'Medium' => '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
                                        'High' => '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
                                        'Critical' => '<span class="badge bg-dark"><i class="fas fa-skull-crossbones me-1"></i>Critical</span>'
                                    ];
                                    echo $severity_badges[$severity] ?? '<span class="badge bg-secondary">' . htmlspecialchars($severity) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $status = $incident['status'];
                                    $status_badges = [
                                        'Pending' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>',
                                        'Under Investigation' => '<span class="badge bg-info"><i class="fas fa-search me-1"></i>Under Investigation</span>',
                                        'In Progress' => '<span class="badge bg-primary"><i class="fas fa-spinner me-1"></i>In Progress</span>',
                                        'Resolved' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Resolved</span>',
                                        'Closed' => '<span class="badge bg-secondary"><i class="fas fa-lock me-1"></i>Closed</span>'
                                    ];
                                    echo $status_badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($incident['responder_name'])): ?>
                                        <i class="fas fa-user-shield me-1"></i>
                                        <?php echo htmlspecialchars($incident['responder_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><i>Not assigned</i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../incidents/incident-details.php?id=<?php echo $incident['incident_id']; ?>" 
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-3">
                <a href="../incidents/view-incidents.php" class="btn btn-outline-primary">
                    <i class="fas fa-list me-2"></i>View All Incidents
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Pending Incidents</h5>
            <p class="text-muted mb-0">All incidents have been resolved or closed</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- NEW: All Complaints Section (Admin/Staff only) -->
<?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
<div class="announcement-section" id="allComplaintsSection" style="display: none;">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-comments"></i>
            <h2>All Complaints</h2>
        </div>
        <a href="../complaints/view-complaints.php" class="add-btn">
            <i class="fas fa-list"></i> Manage Complaints
        </a>
    </div>

    <?php if (!empty($all_complaints)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Complaint #</th>
                            <th>Date</th>
                            <th>Complainant</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_complaints as $complaint): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary">
                                        <?php echo htmlspecialchars($complaint['complaint_number']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($complaint['complaint_date'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($complaint['complainant_name'] ?? 'Unknown'); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    $subject = $complaint['subject'];
                                    if (strlen($subject) > 40) {
                                        echo '<span data-bs-toggle="tooltip" title="' . htmlspecialchars($subject) . '">';
                                        echo htmlspecialchars(substr($subject, 0, 40)) . '...';
                                        echo '</span>';
                                    } else {
                                        echo htmlspecialchars($subject);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $icon_class = 'fa-comment';
                                    switch($complaint['category']) {
                                        case 'Noise': $icon_class = 'fa-volume-up'; break;
                                        case 'Garbage': $icon_class = 'fa-trash'; break;
                                        case 'Property': $icon_class = 'fa-home'; break;
                                        case 'Infrastructure': $icon_class = 'fa-road'; break;
                                        case 'Public Safety': $icon_class = 'fa-shield-alt'; break;
                                        case 'Services': $icon_class = 'fa-concierge-bell'; break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon_class; ?> me-1"></i>
                                    <?php echo htmlspecialchars($complaint['category'] ?? 'N/A'); ?>
                                </td>
                                <td>
                                    <?php
                                    $priority = trim($complaint['priority']);
                                    $priority_badges = [
                                        'Low' => '<span class="badge bg-success bg-opacity-25 text-success"><i class="fas fa-circle me-1"></i>Low</span>',
                                        'Medium' => '<span class="badge bg-warning bg-opacity-25 text-warning"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
                                        'High' => '<span class="badge bg-danger bg-opacity-25 text-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
                                        'Urgent' => '<span class="badge bg-danger text-white"><i class="fas fa-fire me-1"></i>Urgent</span>'
                                    ];
                                    echo $priority_badges[$priority] ?? '<span class="badge bg-secondary">' . htmlspecialchars($priority) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $status = trim($complaint['status']);
                                    $status_badges = [
                                        'Pending' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Pending</span>',
                                        'In Progress' => '<span class="badge bg-primary"><i class="fas fa-spinner me-1"></i>In Progress</span>',
                                        'Resolved' => '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Resolved</span>',
                                        'Closed' => '<span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>Closed</span>'
                                    ];
                                    echo $status_badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($complaint['assigned_to_name'])): ?>
                                        <i class="fas fa-user-shield me-1"></i>
                                        <?php echo htmlspecialchars($complaint['assigned_to_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><i>Not assigned</i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../complaints/complaint-details.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-3">
                <a href="../complaints/view-complaints.php" class="btn btn-outline-primary">
                    <i class="fas fa-list me-2"></i>View All Complaints
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Complaints Found</h5>
            <p class="text-muted mb-0">No complaints have been filed yet</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Pending Document Requests Section (Admin/Staff only) -->
<?php if (($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin') && !empty($pending_document_requests)): ?>
<div class="announcement-section" id="pendingRequestsSection" style="display: none;">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-file-invoice"></i>
            <h2>Pending Document Requests</h2>
        </div>
        <a href="../requests/admin-manage-requests.php?tab=manage&status=Pending" class="add-btn">
            <i class="fas fa-list"></i> View All Requests
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Request ID</th>
                            <th>Date</th>
                            <th>Resident</th>
                            <th>Document Type</th>
                            <th>Purpose</th>
                            <th>Fee</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_document_requests as $request): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary">
                                        #<?php echo str_pad($request['request_id'], 5, '0', STR_PAD_LEFT); ?>
                                    </strong>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($request['request_date'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></strong><br>
                                    <small class="text-muted">
                                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($request['email'] ?? 'N/A'); ?>
                                    </small>
                                </td>
                                <td><?php echo htmlspecialchars($request['request_type_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php 
                                    $purpose = $request['purpose'];
                                    if (strlen($purpose) > 40) {
                                        echo '<span data-bs-toggle="tooltip" title="' . htmlspecialchars($purpose) . '">';
                                        echo htmlspecialchars(substr($purpose, 0, 40)) . '...';
                                        echo '</span>';
                                    } else {
                                        echo htmlspecialchars($purpose);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($request['fee'] > 0): ?>
                                        <strong class="text-success">₱<?php echo number_format($request['fee'], 2); ?></strong>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">Free</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../requests/admin-manage-requests.php?tab=manage&status=Pending#request-<?php echo $request['request_id']; ?>" 
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-3">
                <a href="../requests/admin-manage-requests.php?tab=manage&status=Pending" class="btn btn-outline-primary">
                    <i class="fas fa-list me-2"></i>View All Pending Requests
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Pending Complaints Section (Admin/Staff only) -->
<?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
<div class="announcement-section" id="pendingComplaintsSection" style="display: none;">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-clipboard-list"></i>
            <h2>Pending Complaints</h2>
        </div>
        <a href="../complaints/view-complaints.php?status=Pending" class="add-btn">
            <i class="fas fa-list"></i> View All Complaints
        </a>
    </div>

    <?php if (!empty($pending_complaints)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Complaint #</th>
                            <th>Date</th>
                            <th>Complainant</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_complaints as $complaint): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary">
                                        <?php echo htmlspecialchars($complaint['complaint_number']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <small><?php echo date('M d, Y', strtotime($complaint['complaint_date'])); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($complaint['complainant_name'] ?? 'Unknown'); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    $subject = $complaint['subject'];
                                    if (strlen($subject) > 40) {
                                        echo '<span data-bs-toggle="tooltip" title="' . htmlspecialchars($subject) . '">';
                                        echo htmlspecialchars(substr($subject, 0, 40)) . '...';
                                        echo '</span>';
                                    } else {
                                        echo htmlspecialchars($subject);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $icon_class = 'fa-comment';
                                    switch($complaint['category']) {
                                        case 'Noise': $icon_class = 'fa-volume-up'; break;
                                        case 'Garbage': $icon_class = 'fa-trash'; break;
                                        case 'Property': $icon_class = 'fa-home'; break;
                                        case 'Infrastructure': $icon_class = 'fa-road'; break;
                                        case 'Public Safety': $icon_class = 'fa-shield-alt'; break;
                                        case 'Services': $icon_class = 'fa-concierge-bell'; break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon_class; ?> me-1"></i>
                                    <?php echo htmlspecialchars($complaint['category'] ?? 'N/A'); ?>
                                </td>
                                <td>
                                    <?php
                                    $priority = trim($complaint['priority']);
                                    $priority_badges = [
                                        'Low' => '<span class="badge bg-success bg-opacity-25 text-success"><i class="fas fa-circle me-1"></i>Low</span>',
                                        'Medium' => '<span class="badge bg-warning bg-opacity-25 text-warning"><i class="fas fa-exclamation-circle me-1"></i>Medium</span>',
                                        'High' => '<span class="badge bg-danger bg-opacity-25 text-danger"><i class="fas fa-exclamation-triangle me-1"></i>High</span>',
                                        'Urgent' => '<span class="badge bg-danger text-white"><i class="fas fa-fire me-1"></i>Urgent</span>'
                                    ];
                                    echo $priority_badges[$priority] ?? '<span class="badge bg-secondary">' . htmlspecialchars($priority) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <a href="../complaints/complaint-details.php?id=<?php echo $complaint['complaint_id']; ?>" 
                                       class="btn btn-sm btn-primary" title="View Details">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-3">
                <a href="../complaints/view-complaints.php?status=Pending" class="btn btn-outline-primary">
                    <i class="fas fa-list me-2"></i>View All Pending Complaints
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Pending Complaints</h5>
            <p class="text-muted mb-0">All complaints have been processed</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Announcements Section -->
<div class="announcement-section">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-bullhorn"></i>
            <h2>Announcements</h2>
        </div>
        <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
            <button class="add-btn" onclick="openModal('addAnnouncementModal')">
                <i class="fas fa-plus"></i> Post Announcement
            </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($announcements)): ?>
        <div class="announcements-container">
            <?php foreach ($announcements as $announcement): 
                $priority_class = 'priority-' . ($announcement['priority'] ?? 'normal');
                $priority_icon = [
                    'urgent' => 'fa-exclamation-circle',
                    'high' => 'fa-exclamation-triangle',
                    'normal' => 'fa-info-circle'
                ];
                $icon = $priority_icon[$announcement['priority'] ?? 'normal'];
            ?>
            <div class="announcement-card <?php echo $priority_class; ?>">
                <div class="announcement-header">
                    <div class="announcement-header-left">
                        <i class="fas <?php echo $icon; ?> announcement-icon"></i>
                        <div>
                            <h3 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                            <span class="announcement-badge"><?php echo strtoupper($announcement['priority'] ?? 'NORMAL'); ?></span>
                        </div>
                    </div>
                    <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
                        <div class="quick-actions">
                            <button class="quick-btn" onclick='editAnnouncement(<?php echo json_encode($announcement); ?>)' title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_announcement">
                                <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                <input type="hidden" name="is_active" value="0">
                                <button type="submit" class="quick-btn" title="Hide">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </form>
                            <button class="quick-btn danger" 
                                onclick='openDeleteAnnouncementModal(<?php echo $announcement["id"]; ?>, <?php echo json_encode($announcement["title"]); ?>)' 
                                title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="announcement-content">
                    <p><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                </div>
                <div class="announcement-date">
                    <i class="far fa-clock"></i>
                    <span>Posted: <?php echo date('F j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-media-placeholder">
            <i class="fas fa-bullhorn"></i>
            <h3>No Announcements Yet</h3>
            <p><?php echo ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin') ? 
                'Post an announcement to keep residents informed' : 
                'Check back later for barangay announcements'; ?></p>
            <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
                <button class="add-btn" onclick="openModal('addAnnouncementModal')">
                    <i class="fas fa-plus"></i> Post Announcement
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Video Presentation Section -->
<?php if ($video_url || ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin')): ?>
<div class="media-section">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-video"></i>
            <h2>Barangay Video Updates</h2>
        </div>
        <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
            <a href="../media/manage.php" class="add-btn">
                <i class="fas fa-cog"></i> Manage Media
            </a>
        <?php endif; ?>
    </div>

    <?php if ($video_url): ?>
        <div class="video-container">
            <iframe 
                src="<?php echo htmlspecialchars($video_url); ?>" 
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                allowfullscreen>
            </iframe>
            <?php if ($video_data && !empty($video_data['caption'])): ?>
                <div class="video-caption">
                    <p><?php echo htmlspecialchars($video_data['caption']); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php if (($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin') && $video_data): ?>
            <div class="quick-actions" style="margin-top: 1rem;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="toggle_media">
                    <input type="hidden" name="id" value="<?php echo $video_data['id']; ?>">
                    <input type="hidden" name="is_active" value="0">
                    <button type="submit" class="quick-btn">
                        <i class="fas fa-eye-slash"></i> Hide Video
                    </button>
                </form>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this video?');">
                    <input type="hidden" name="action" value="delete_media">
                    <input type="hidden" name="id" value="<?php echo $video_data['id']; ?>">
                    <button type="submit" class="quick-btn danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        <?php endif; ?>
    <?php elseif ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
        <div class="empty-media-placeholder">
            <i class="fas fa-video"></i>
            <h3>No Video Available</h3>
            <p>Add a YouTube video to showcase barangay updates</p>
            <a href="../media/manage.php" class="add-btn">
                <i class="fas fa-plus"></i> Add Video
            </a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Photo Gallery Section -->
<?php if (!empty($gallery_photos) || ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin')): ?>
<div class="media-section">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-images"></i>
            <h2>Photo Gallery</h2>
        </div>
        <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
            <a href="../media/manage.php" class="add-btn">
                <i class="fas fa-cog"></i> Manage Gallery
            </a>
        <?php endif; ?>
    </div>

    <?php if (!empty($gallery_photos)): ?>
        <div class="gallery-grid">
            <?php foreach ($gallery_photos as $photo): ?>
                <div class="gallery-item">
                    <img src="../../<?php echo htmlspecialchars($photo['file_path']); ?>" 
                         alt="<?php echo htmlspecialchars($photo['caption']); ?>"
                         onclick="openImageModal('../../<?php echo htmlspecialchars($photo['file_path']); ?>', '<?php echo htmlspecialchars($photo['caption']); ?>')">
                    <div class="gallery-caption">
                        <?php echo htmlspecialchars($photo['caption']); ?>
                    </div>
                    <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
                        <div class="gallery-actions">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_media">
                                <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                                <input type="hidden" name="is_active" value="0">
                                <button type="submit" title="Hide">
                                    <i class="fas fa-eye-slash"></i>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this photo?');">
                                <input type="hidden" name="action" value="delete_media">
                                <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                                <button type="submit" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
        <div class="empty-media-placeholder">
            <i class="fas fa-images"></i>
            <h3>No Photos Available</h3>
            <p>Upload photos to showcase barangay events and activities</p>
            <a href="../media/manage.php" class="add-btn">
                <i class="fas fa-plus"></i> Upload Photos
            </a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Activities Section -->
<?php if (!empty($activities) || ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin')): ?>
<div class="media-section">
    <div class="section-header">
        <div class="section-header-left">
            <i class="fas fa-calendar-check"></i>
            <h2>Barangay Activities & Events</h2>
        </div>
        <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
            <div style="display: flex; gap: 0.5rem;">
                <button class="add-btn" onclick="openModal('addActivityModal')">
                    <i class="fas fa-plus"></i> Add Activity
                </button>
                <a href="../activities/manage.php" class="add-btn">
                    <i class="fas fa-list"></i> View All
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($activities)): ?>
        <div class="activity-timeline">
            <?php foreach ($activities as $activity): 
                $days_diff = floor((strtotime($activity['activity_date']) - time()) / 86400);
                if ($days_diff > 0) {
                    $time_text = 'In ' . $days_diff . ' day' . ($days_diff > 1 ? 's' : '');
                    $status_class = 'upcoming';
                } elseif ($days_diff == 0) {
                    $time_text = 'Today';
                    $status_class = 'today';
                } else {
                    $days_ago = abs($days_diff);
                    $time_text = $days_ago . ' day' . ($days_ago > 1 ? 's' : '') . ' ago';
                    $status_class = 'past';
                }
            ?>
                <div class="activity-item <?php echo $status_class; ?>">
                    <div class="activity-marker"></div>
                    <div class="activity-content">
                        <div class="activity-header">
                            <div class="activity-title-wrapper">
                                <div class="activity-title"><?php echo htmlspecialchars($activity['title']); ?></div>
                                <div class="activity-meta">
                                    <span class="activity-date">
                                        <i class="far fa-calendar"></i> 
                                        <?php echo date('F j, Y', strtotime($activity['activity_date'])); ?>
                                    </span>
                                    <span class="activity-status"><?php echo $time_text; ?></span>
                                    <?php if (!empty($activity['location'])): ?>
                                        <span class="activity-location">
                                            <i class="fas fa-map-marker-alt"></i> 
                                            <?php echo htmlspecialchars($activity['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
                                <div class="activity-actions">
                                    <button class="quick-btn" onclick='editActivity(<?php echo json_encode($activity); ?>)' title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_activity">
                                        <input type="hidden" name="id" value="<?php echo $activity['id']; ?>">
                                        <input type="hidden" name="is_active" value="0">
                                        <button type="submit" class="quick-btn" title="Hide">
                                            <i class="fas fa-eye-slash"></i>
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this activity?');">
                                        <input type="hidden" name="action" value="delete_activity">
                                        <input type="hidden" name="id" value="<?php echo $activity['id']; ?>">
                                        <button type="submit" class="quick-btn danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="activity-description">
                            <?php echo nl2br(htmlspecialchars($activity['description'])); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif ($user_role === 'Admin' || $user_role === 'Staff' || $user_role === 'Super Admin'): ?>
        <div class="empty-media-placeholder">
            <i class="fas fa-calendar-check"></i>
            <h3>No Activities Yet</h3>
            <p>Start tracking barangay activities and events</p>
            <button class="add-btn" onclick="openModal('addActivityModal')">
                <i class="fas fa-plus"></i> Add Activity
            </button>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- MODALS -->

<!-- Add Announcement Modal -->
<div id="addAnnouncementModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Post New Announcement</h2>
            <button class="close-btn" onclick="closeModal('addAnnouncementModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_announcement">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" class="form-control" placeholder="e.g., Community Clean-Up Drive" required>
            </div>
            <div class="form-group">
                <label>Priority Level *</label>
                <select name="priority" class="form-control" required>
                    <option value="normal">Normal</option>
                    <option value="high">High Priority</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="form-group">
                <label>Content *</label>
                <textarea name="content" class="form-control" rows="5" placeholder="Write your announcement here..." required></textarea>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Post Announcement
            </button>
        </form>
    </div>
</div>

<!-- Edit Announcement Modal -->
<div id="editAnnouncementModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Edit Announcement</h2>
            <button class="close-btn" onclick="closeModal('editAnnouncementModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_announcement">
            <input type="hidden" name="id" id="edit_announcement_id">
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" id="edit_announcement_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Priority Level *</label>
                <select name="priority" id="edit_announcement_priority" class="form-control" required>
                    <option value="normal">Normal</option>
                    <option value="high">High Priority</option>
                    <option value="urgent">Urgent</option>
                </select>
            </div>
            <div class="form-group">
                <label>Content *</label>
                <textarea name="content" id="edit_announcement_content" class="form-control" rows="5" required></textarea>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Update Announcement
            </button>
        </form>
    </div>
</div>

<!-- Add Activity Modal -->
<div id="addActivityModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Add New Activity</h2>
            <button class="close-btn" onclick="closeModal('addActivityModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_activity">
            <div class="form-group">
                <label>Activity Title *</label>
                <input type="text" name="title" class="form-control" placeholder="e.g., Medical Mission" required>
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" class="form-control" rows="4" placeholder="Describe the activity..." required></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Activity Date *</label>
                    <input type="date" name="activity_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control" placeholder="e.g., Barangay Hall">
                </div>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Save Activity
            </button>
        </form>
    </div>
</div>

<!-- Edit Activity Modal -->
<div id="editActivityModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Edit Activity</h2>
            <button class="close-btn" onclick="closeModal('editActivityModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_activity">
            <input type="hidden" name="id" id="edit_activity_id">
            <div class="form-group">
                <label>Activity Title *</label>
                <input type="text" name="title" id="edit_activity_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" id="edit_activity_description" class="form-control" rows="4" required></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Activity Date *</label>
                    <input type="date" name="activity_date" id="edit_activity_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" id="edit_activity_location" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-save"></i> Update Activity
            </button>
        </form>
    </div>
</div>

<!-- Delete Announcement Confirmation Modal -->
<div id="deleteAnnouncementModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-exclamation-triangle text-danger"></i> Confirm Deletion
            </h2>
            <button class="close-btn" onclick="closeModal('deleteAnnouncementModal')">&times;</button>
        </div>
        <div class="modal-body" style="padding: 2rem;">
            <p style="font-size: 1.1rem; margin-bottom: 1rem;">Are you sure you want to delete this announcement?</p>
            <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                <strong id="delete_announcement_title"></strong>
            </div>
            <p style="color: #dc3545; font-size: 0.9rem;">
                <i class="fas fa-info-circle"></i> This action cannot be undone.
            </p>
        </div>
        <form method="POST" id="deleteAnnouncementForm">
            <input type="hidden" name="action" value="delete_announcement">
            <input type="hidden" name="id" id="delete_announcement_id">
            <div style="display: flex; gap: 1rem; padding: 1rem 2rem 2rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteAnnouncementModal')" style="flex: 1;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-danger" style="flex: 1;">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Image Viewer Modal -->
<div id="imageModal" class="modal image-modal">
    <div class="modal-content image-modal-content">
        <button class="close-btn" onclick="closeImageModal()">&times;</button>
        <img id="modalImage" src="" alt="">
        <div id="modalCaption" class="image-modal-caption"></div>
    </div>
</div>

<script>

// Update clock every second
setInterval(function() {
    const now = new Date();
    let hours = now.getHours();
    const minutes = now.getMinutes();
    const seconds = now.getSeconds();
    const ampm = hours >= 12 ? 'PM' : 'AM';
    
    hours = hours % 12;
    hours = hours ? hours : 12; // 0 should be 12
    
    const timeString = hours + ':' + 
                      (minutes < 10 ? '0' + minutes : minutes) + ':' +
                      (seconds < 10 ? '0' + seconds : seconds) + ' ' + ampm;
    
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}, 1000);

// NEW: Toggle All Complaints Section
function toggleAllComplaints() {
    const section = document.getElementById('allComplaintsSection');
    const complaintsSection = document.getElementById('pendingComplaintsSection');
    const requestsSection = document.getElementById('pendingRequestsSection');
    const residentsSection = document.getElementById('allResidentsSection');
    const incidentsSection = document.getElementById('pendingIncidentsSection');
    
    if (section) {
        if (section.style.display === 'none') {
            section.style.display = 'block';
            if (complaintsSection) complaintsSection.style.display = 'none';
            if (requestsSection) requestsSection.style.display = 'none';
            if (residentsSection) residentsSection.style.display = 'none';
            if (incidentsSection) incidentsSection.style.display = 'none';
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            section.style.display = 'none';
        }
    }
}

// Toggle all residents section
function toggleAllResidents() {
    const section = document.getElementById('allResidentsSection');
    const complaintsSection = document.getElementById('pendingComplaintsSection');
    const requestsSection = document.getElementById('pendingRequestsSection');
    const incidentsSection = document.getElementById('pendingIncidentsSection');
    const allComplaintsSection = document.getElementById('allComplaintsSection');
    
    if (section) {
        if (section.style.display === 'none') {
            section.style.display = 'block';
            if (complaintsSection) complaintsSection.style.display = 'none';
            if (requestsSection) requestsSection.style.display = 'none';
            if (incidentsSection) incidentsSection.style.display = 'none';
            if (allComplaintsSection) allComplaintsSection.style.display = 'none';
            showAllResidentsFilter();
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            section.style.display = 'none';
        }
    }
}

// Toggle pending incidents section
function togglePendingIncidents() {
    const section = document.getElementById('pendingIncidentsSection');
    const complaintsSection = document.getElementById('pendingComplaintsSection');
    const requestsSection = document.getElementById('pendingRequestsSection');
    const residentsSection = document.getElementById('allResidentsSection');
    const allComplaintsSection = document.getElementById('allComplaintsSection');
    
    if (section) {
        if (section.style.display === 'none') {
            section.style.display = 'block';
            if (complaintsSection) complaintsSection.style.display = 'none';
            if (requestsSection) requestsSection.style.display = 'none';
            if (residentsSection) residentsSection.style.display = 'none';
            if (allComplaintsSection) allComplaintsSection.style.display = 'none';
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            section.style.display = 'none';
        }
    }
}

// Toggle pending requests section
function togglePendingRequests() {
    const section = document.getElementById('pendingRequestsSection');
    const complaintsSection = document.getElementById('pendingComplaintsSection');
    const residentsSection = document.getElementById('allResidentsSection');
    const incidentsSection = document.getElementById('pendingIncidentsSection');
    const allComplaintsSection = document.getElementById('allComplaintsSection');
    
    if (section) {
        if (section.style.display === 'none') {
            section.style.display = 'block';
            if (complaintsSection) complaintsSection.style.display = 'none';
            if (residentsSection) residentsSection.style.display = 'none';
            if (incidentsSection) incidentsSection.style.display = 'none';
            if (allComplaintsSection) allComplaintsSection.style.display = 'none';
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            section.style.display = 'none';
        }
    }
}

// Toggle pending complaints section
function togglePendingComplaints() {
    const section = document.getElementById('pendingComplaintsSection');
    const requestsSection = document.getElementById('pendingRequestsSection');
    const residentsSection = document.getElementById('allResidentsSection');
    const incidentsSection = document.getElementById('pendingIncidentsSection');
    const allComplaintsSection = document.getElementById('allComplaintsSection');
    
    if (section) {
        if (section.style.display === 'none') {
            section.style.display = 'block';
            if (requestsSection) requestsSection.style.display = 'none';
            if (residentsSection) residentsSection.style.display = 'none';
            if (incidentsSection) incidentsSection.style.display = 'none';
            if (allComplaintsSection) allComplaintsSection.style.display = 'none';
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            section.style.display = 'none';
        }
    }
}

// Filter functions for residents
function showAllResidentsFilter() {
    const rows = document.querySelectorAll('#allResidentsTableBody tr');
    rows.forEach(row => {
        row.style.display = '';
    });
}

function showVerifiedResidents() {
    const rows = document.querySelectorAll('#allResidentsTableBody tr');
    rows.forEach(row => {
        if (row.getAttribute('data-verified') === '1') {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function showUnverifiedResidents() {
    const rows = document.querySelectorAll('#allResidentsTableBody tr');
    rows.forEach(row => {
        if (row.getAttribute('data-verified') === '0') {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function openDeleteAnnouncementModal(id, title) {
    document.getElementById('delete_announcement_id').value = id;
    document.getElementById('delete_announcement_title').textContent = title;
    openModal('deleteAnnouncementModal');
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
    document.body.style.overflow = 'auto';
}

function editAnnouncement(announcement) {
    document.getElementById('edit_announcement_id').value = announcement.id;
    document.getElementById('edit_announcement_title').value = announcement.title;
    document.getElementById('edit_announcement_content').value = announcement.content;
    document.getElementById('edit_announcement_priority').value = announcement.priority || 'normal';
    openModal('editAnnouncementModal');
}

function editActivity(activity) {
    document.getElementById('edit_activity_id').value = activity.id;
    document.getElementById('edit_activity_title').value = activity.title;
    document.getElementById('edit_activity_description').value = activity.description;
    document.getElementById('edit_activity_date').value = activity.activity_date;
    document.getElementById('edit_activity_location').value = activity.location || '';
    openModal('editActivityModal');
}

function openImageModal(src, caption) {
    document.getElementById('modalImage').src = src;
    document.getElementById('modalCaption').textContent = caption;
    document.getElementById('imageModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeImageModal() {
    document.getElementById('imageModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

// Close modal on ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
    }
});

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.animation = 'slideOut 0.3s ease forwards';
        setTimeout(function() {
            alert.style.display = 'none';
        }, 300);
    });
}, 5000);

// Initialize Bootstrap tooltips if available
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>