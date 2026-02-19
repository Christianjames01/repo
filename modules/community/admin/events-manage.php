<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Community Events';

// Handle AJAX actions for updates only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'edit') {
        $event_id = intval($_POST['event_id']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $event_date = $_POST['event_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $location = trim($_POST['location']);
        $event_type = $_POST['event_type'];
        $status = $_POST['status'];
        
        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Title is required']);
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE tbl_events SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, location = ?, event_type = ?, status = ? WHERE event_id = ?");
        $stmt->bind_param("ssssssssi", $title, $description, $event_date, $start_time, $end_time, $location, $event_type, $status, $event_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update event']);
        }
        exit();
    }
    
    if ($_POST['action'] === 'cancel') {
        $event_id = intval($_POST['event_id']);
        $stmt = $conn->prepare("UPDATE tbl_events SET status = 'cancelled' WHERE event_id = ?");
        $stmt->bind_param("i", $event_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Event cancelled successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to cancel event']);
        }
        exit();
    }
    
    if ($_POST['action'] === 'delete') {
        $event_id = intval($_POST['event_id']);
        
        // Get event image to delete file
        $image_stmt = $conn->prepare("SELECT event_image FROM tbl_events WHERE event_id = ?");
        $image_stmt->bind_param("i", $event_id);
        $image_stmt->execute();
        $image_result = $image_stmt->get_result()->fetch_assoc();
        $event_image = $image_result['event_image'] ?? null;
        $image_stmt->close();
        
        // Delete attendees first
        $conn->query("DELETE FROM tbl_event_attendees WHERE event_id = $event_id");
        
        // Delete event
        $stmt = $conn->prepare("DELETE FROM tbl_events WHERE event_id = ?");
        $stmt->bind_param("i", $event_id);
        
        if ($stmt->execute()) {
            // Delete image file if exists
            if ($event_image) {
                $image_path = '../../../uploads/events/' . $event_image;
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Event deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete event']);
        }
        exit();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $where_conditions[] = "e.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($type_filter !== 'all') {
    $where_conditions[] = "e.event_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_conditions[] = "(e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get events
$query = "
    SELECT e.*,
           CONCAT(r.first_name, ' ', r.last_name) as organizer_name,
           COUNT(DISTINCT a.attendee_id) as attendee_count
    FROM tbl_events e
    LEFT JOIN tbl_resident r ON e.organizer_id = r.resident_id
    LEFT JOIN tbl_event_attendees a ON e.event_id = a.event_id
    $where_clause
    GROUP BY e.event_id
    ORDER BY e.event_date ASC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$events = $stmt->get_result();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT e.event_id) as total_events,
        COUNT(DISTINCT a.attendee_id) as total_attendees,
        COUNT(DISTINCT CASE WHEN e.status = 'upcoming' THEN e.event_id END) as upcoming_events,
        COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.event_id END) as completed_events
    FROM tbl_events e
    LEFT JOIN tbl_event_attendees a ON e.event_id = a.event_id
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

include '../../../includes/header.php';
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; color: #1a1a1a; }
    .page-header { background: white; padding: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    .page-header h1 { font-size: 1.75rem; font-weight: 600; margin-bottom: 0.5rem; color: #1a1a1a; }
    .breadcrumb { font-size: 0.875rem; color: #6b7280; }
    .breadcrumb a { color: #3b82f6; text-decoration: none; }
    
    .alert { padding: 0.875rem 1.25rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid; font-size: 0.875rem; }
    .alert-success { background: #f0fdf4; color: #15803d; border-color: #86efac; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-card { background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; }
    .stat-label { font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem; }
    .stat-value { font-size: 2rem; font-weight: 600; color: #1a1a1a; }
    .filters-section { background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 2rem; }
    .filters-grid { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 1rem; margin-top: 1rem; }
    .form-group label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: #374151; }
    .form-control { width: 100%; padding: 0.625rem 0.875rem; font-size: 0.875rem; border: 1px solid #d1d5db; border-radius: 6px; background: white; transition: all 0.15s; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    textarea.form-control { resize: vertical; min-height: 100px; font-family: inherit; }
    .btn { padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; display: inline-block; }
    .btn-sm { padding: 0.5rem 1rem; font-size: 0.8125rem; }
    .btn-primary { background: #3b82f6; color: white; }
    .btn-primary:hover { background: #2563eb; }
    .btn-success { background: #10b981; color: white; }
    .btn-success:hover { background: #059669; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-secondary:hover { background: #4b5563; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-danger:hover { background: #dc2626; }
    .btn-outline { background: white; color: #374151; border: 1px solid #d1d5db; }
    .btn-outline:hover { background: #f9fafb; }
    .action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .events-list { background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden; }
    .event-item { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; display: flex; gap: 1.5rem; }
    .event-item:last-child { border-bottom: none; }
    
    /* Event Thumbnail */
    .event-thumbnail { flex-shrink: 0; width: 120px; height: 120px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .event-thumbnail img { width: 100%; height: 100%; object-fit: cover; }
    .event-thumbnail-placeholder { color: #d1d5db; font-size: 2.5rem; }
    
    .event-date-badge { flex-shrink: 0; width: 80px; height: 80px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
    .event-month { font-size: 0.75rem; font-weight: 600; color: #ef4444; text-transform: uppercase; letter-spacing: 0.5px; }
    .event-day { font-size: 1.75rem; font-weight: 700; color: #1a1a1a; line-height: 1; }
    .event-content { flex: 1; }
    .event-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem; }
    .event-title { font-size: 1.125rem; font-weight: 600; color: #1a1a1a; margin-bottom: 0.25rem; }
    .event-description { color: #6b7280; font-size: 0.875rem; line-height: 1.5; margin-bottom: 0.75rem; }
    .event-meta { display: flex; gap: 1.5rem; margin: 0.75rem 0; font-size: 0.875rem; color: #6b7280; flex-wrap: wrap; }
    .event-meta span { display: flex; align-items: center; gap: 0.5rem; }
    .event-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; flex-wrap: wrap; gap: 1rem; }
    .event-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .badge { display: inline-block; padding: 0.25rem 0.75rem; font-size: 0.75rem; font-weight: 500; border-radius: 12px; }
    .badge-upcoming { background: #dbeafe; color: #1e40af; }
    .badge-ongoing { background: #d1fae5; color: #065f46; }
    .badge-completed { background: #f3f4f6; color: #4b5563; }
    .badge-cancelled { background: #fee2e2; color: #991b1b; }
    .empty-state { text-align: center; padding: 3rem; color: #6b7280; }
    .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
    
    /* MODAL STYLES */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal-overlay.active { display: flex; }
    .modal-content { background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); animation: modalSlideIn 0.2s ease-out; }
    .modal-content.large { max-width: 800px; }
    @keyframes modalSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    .modal-header { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
    .modal-header h3 { margin: 0; font-size: 1.25rem; font-weight: 600; color: #1a1a1a; }
    .modal-close { background: none; border: none; font-size: 1.5rem; color: #6b7280; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.15s; }
    .modal-close:hover { background: #f3f4f6; color: #1a1a1a; }
    .modal-body { padding: 1.5rem; }
    .modal-body p { color: #4b5563; line-height: 1.6; margin-bottom: 1rem; }
    .modal-footer { padding: 1.5rem; border-top: 1px solid #e5e7eb; display: flex; gap: 0.75rem; justify-content: flex-end; }
    
    .detail-section { margin-bottom: 1.5rem; }
    .detail-label { font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
    .detail-value { font-size: 0.875rem; color: #1a1a1a; line-height: 1.6; }
    .detail-value.large { font-size: 1.125rem; font-weight: 600; }
    .detail-image { width: 100%; max-height: 300px; object-fit: cover; border-radius: 8px; border: 1px solid #e5e7eb; }
    .edit-form .form-group { margin-bottom: 1.5rem; }
    .edit-form textarea { min-height: 100px; resize: vertical; }
    .row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem; }
    
    @media (max-width: 768px) {
        .row { grid-template-columns: 1fr; }
        .filters-grid { grid-template-columns: 1fr; }
        .event-item { flex-direction: column; }
        .event-thumbnail { width: 100%; height: 200px; }
    }
</style>

<div class="page-header">
    <h1>Manage Community Events</h1>
    <div class="breadcrumb">
        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php">Dashboard</a> / 
        <span>Events Management</span>
    </div>
</div>

<div class="container-fluid">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle" style="margin-right: 0.5rem;"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Events</div>
            <div class="stat-value"><?php echo number_format($stats['total_events']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Attendees</div>
            <div class="stat-value"><?php echo number_format($stats['total_attendees']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Upcoming Events</div>
            <div class="stat-value"><?php echo number_format($stats['upcoming_events']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Completed Events</div>
            <div class="stat-value"><?php echo number_format($stats['completed_events']); ?></div>
        </div>
    </div>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div></div>
        <a href="create-event.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Create New Event
        </a>
    </div>
    
    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search events..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="upcoming" <?php echo $status_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="ongoing" <?php echo $status_filter === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="meeting" <?php echo $type_filter === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                        <option value="social" <?php echo $type_filter === 'social' ? 'selected' : ''; ?>>Social</option>
                        <option value="cleanup" <?php echo $type_filter === 'cleanup' ? 'selected' : ''; ?>>Cleanup</option>
                        <option value="sports" <?php echo $type_filter === 'sports' ? 'selected' : ''; ?>>Sports</option>
                        <option value="other" <?php echo $type_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Events List -->
    <div class="events-list">
        <?php if ($events->num_rows > 0): ?>
            <?php while ($event = $events->fetch_assoc()): 
                $has_image = !empty($event['event_image']) && file_exists('../../../uploads/events/' . $event['event_image']);
            ?>
                <div class="event-item">
                    <!-- Event Thumbnail -->
                    <div class="event-thumbnail">
                        <?php if ($has_image): ?>
                            <img src="<?php echo BASE_URL; ?>/uploads/events/<?php echo htmlspecialchars($event['event_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($event['title']); ?>">
                        <?php else: ?>
                            <div class="event-thumbnail-placeholder">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="event-date-badge">
                        <div class="event-month"><?php echo date('M', strtotime($event['event_date'])); ?></div>
                        <div class="event-day"><?php echo date('d', strtotime($event['event_date'])); ?></div>
                    </div>
                    
                    <div class="event-content">
                        <div class="event-header">
                            <div>
                                <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                            </div>
                            <span class="badge badge-<?php echo $event['status']; ?>">
                                <?php echo ucfirst($event['status']); ?>
                            </span>
                        </div>
                        
                        <p class="event-description">
                            <?php echo nl2br(htmlspecialchars(substr($event['description'], 0, 150))); ?>
                            <?php if (strlen($event['description']) > 150): ?>...<?php endif; ?>
                        </p>
                        
                        <div class="event-meta">
                            <span><i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($event['start_time'])); ?> - <?php echo date('h:i A', strtotime($event['end_time'])); ?></span>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['location']); ?></span>
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($event['organizer_name']); ?></span>
                            <span><i class="fas fa-users"></i> <?php echo $event['attendee_count']; ?> attendees</span>
                            <span><i class="fas fa-tag"></i> <?php echo ucfirst($event['event_type']); ?></span>
                        </div>
                        
                        <div class="event-footer">
                            <div></div>
                            <div class="event-actions">
                                <button onclick="showViewModal(<?php echo $event['event_id']; ?>)" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button onclick="showEditModal(<?php echo $event['event_id']; ?>)" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($event['status'] === 'upcoming'): ?>
                                    <button onclick="showCancelModal(<?php echo $event['event_id']; ?>, '<?php echo htmlspecialchars($event['title'], ENT_QUOTES); ?>')" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-ban"></i> Cancel
                                    </button>
                                <?php endif; ?>
                                <button onclick="showDeleteModal(<?php echo $event['event_id']; ?>, '<?php echo htmlspecialchars($event['title'], ENT_QUOTES); ?>')" class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar"></i>
                <p>No events found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Event Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3>Event Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-section" id="viewImageSection" style="display: none;">
                <img id="viewImage" src="" alt="Event Image" class="detail-image">
            </div>
            
            <div class="detail-section">
                <div class="detail-label">Event Title</div>
                <div class="detail-value large" id="viewTitle"></div>
            </div>
            
            <div class="detail-section">
                <div class="detail-label">Description</div>
                <div class="detail-value" id="viewDescription"></div>
            </div>
            
            <div class="row">
                <div class="detail-section">
                    <div class="detail-label">Date</div>
                    <div class="detail-value" id="viewDate"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Time</div>
                    <div class="detail-value" id="viewTime"></div>
                </div>
            </div>
            
            <div class="row">
                <div class="detail-section">
                    <div class="detail-label">Location</div>
                    <div class="detail-value" id="viewLocation"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Event Type</div>
                    <div class="detail-value" id="viewType"></div>
                </div>
            </div>
            
            <div class="row">
                <div class="detail-section">
                    <div class="detail-label">Organizer</div>
                    <div class="detail-value" id="viewOrganizer"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="viewStatus"></div>
                </div>
            </div>
            
            <div class="detail-section">
                <div class="detail-label">Attendees</div>
                <div class="detail-value" id="viewAttendees"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Edit Event Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3>Edit Event</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editEventForm" class="edit-form">
                <input type="hidden" id="editEventId" name="event_id">
                
                <div class="form-group">
                    <label>Event Title *</label>
                    <input type="text" id="editTitle" name="title" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Description *</label>
                    <textarea id="editDescription" name="description" class="form-control" required></textarea>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label>Event Date *</label>
                        <input type="date" id="editEventDate" name="event_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Event Type *</label>
                        <select id="editEventType" name="event_type" class="form-control" required>
                            <option value="meeting">Meeting</option>
                            <option value="social">Social</option>
                            <option value="cleanup">Cleanup</option>
                            <option value="sports">Sports</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label>Start Time *</label>
                        <input type="time" id="editStartTime" name="start_time" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Time *</label>
                        <input type="time" id="editEndTime" name="end_time" class="form-control" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label>Location *</label>
                        <input type="text" id="editLocation" name="location" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select id="editStatus" name="status" class="form-control" required>
                            <option value="upcoming">Upcoming</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditEvent()">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- Cancel Event Modal -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Cancel Event</h3>
            <button class="modal-close" onclick="closeCancelModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to cancel "<strong id="cancelEventTitle"></strong>"?</p>
            <p>This will notify all registered attendees about the cancellation.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeCancelModal()">No, Keep Event</button>
            <button class="btn btn-danger" onclick="confirmCancelEvent()">
                <i class="fas fa-ban"></i> Yes, Cancel Event
            </button>
        </div>
    </div>
</div>

<!-- Delete Event Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delete Event</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to permanently delete "<strong id="deleteEventTitle"></strong>"?</p>
            <p><strong>This action cannot be undone.</strong> All attendee data associated with this event will be permanently removed.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" onclick="confirmDeleteEvent()">
                <i class="fas fa-trash"></i> Delete Event
            </button>
        </div>
    </div>
</div>

<script>
let currentEventId = null;

// View Modal Functions
function showViewModal(eventId) {
    currentEventId = eventId;
    document.getElementById('viewModal').classList.add('active');
    loadEventDetails(eventId);
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}

async function loadEventDetails(eventId) {
    try {
        const response = await fetch('get-event-details.php?id=' + eventId);
        const data = await response.json();
        
        if (data.success) {
            const event = data.event;
            
            // Show/hide image
            if (event.event_image) {
                document.getElementById('viewImage').src = '<?php echo BASE_URL; ?>/uploads/events/' + event.event_image;
                document.getElementById('viewImageSection').style.display = 'block';
            } else {
                document.getElementById('viewImageSection').style.display = 'none';
            }
            
            document.getElementById('viewTitle').textContent = event.title;
            document.getElementById('viewDescription').textContent = event.description;
            document.getElementById('viewDate').textContent = new Date(event.event_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
            document.getElementById('viewTime').textContent = formatTime(event.start_time) + ' - ' + formatTime(event.end_time);
            document.getElementById('viewLocation').textContent = event.location;
            document.getElementById('viewType').textContent = capitalizeFirst(event.event_type);
            document.getElementById('viewOrganizer').textContent = event.organizer_name;
            document.getElementById('viewStatus').innerHTML = `<span class="badge badge-${event.status}">${capitalizeFirst(event.status)}</span>`;
            document.getElementById('viewAttendees').textContent = event.attendee_count + ' attendees';
        } else {
            alert('Error: ' + data.message);
            closeViewModal();
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while loading event details');
        closeViewModal();
    }
}

// Edit Modal Functions
function showEditModal(eventId) {
    currentEventId = eventId;
    document.getElementById('editModal').classList.add('active');
    
    fetch('get-event-details.php?id=' + eventId)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const event = data.event;
            
            document.getElementById('editEventId').value = event.event_id;
            document.getElementById('editTitle').value = event.title;
            document.getElementById('editDescription').value = event.description;
            document.getElementById('editEventDate').value = event.event_date;
            document.getElementById('editStartTime').value = event.start_time.substring(0, 5);
            document.getElementById('editEndTime').value = event.end_time.substring(0, 5);
            document.getElementById('editLocation').value = event.location;
            document.getElementById('editEventType').value = event.event_type;
            document.getElementById('editStatus').value = event.status;
        } else {
            alert('Error: ' + data.message);
            closeEditModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while loading event data');
        closeEditModal();
    });
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

function saveEditEvent() {
    const formData = new FormData(document.getElementById('editEventForm'));
    formData.append('action', 'edit');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving changes');
    });
}

// Cancel Event Modal Functions
function showCancelModal(eventId, eventTitle) {
    currentEventId = eventId;
    document.getElementById('cancelEventTitle').textContent = eventTitle;
    document.getElementById('cancelModal').classList.add('active');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('active');
}

function confirmCancelEvent() {
    if (!currentEventId) return;
    
    const formData = new FormData();
    formData.append('action', 'cancel');
    formData.append('event_id', currentEventId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            closeCancelModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
        closeCancelModal();
    });
}

// Delete Event Modal Functions
function showDeleteModal(eventId, eventTitle) {
    currentEventId = eventId;
    document.getElementById('deleteEventTitle').textContent = eventTitle;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

function confirmDeleteEvent() {
    if (!currentEventId) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('event_id', currentEventId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            closeDeleteModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
        closeDeleteModal();
    });
}

// Utility functions
function formatTime(timeString) {
    const [hours, minutes] = timeString.split(':');
    const hour = parseInt(hours);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const displayHour = hour % 12 || 12;
    return `${displayHour}:${minutes} ${ampm}`;
}

function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// Close modal on overlay click
document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeViewModal();
        closeEditModal();
        closeCancelModal();
        closeDeleteModal();
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>