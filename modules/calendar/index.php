<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireLogin();

$page_title = 'Calendar';
$current_user_id = getCurrentUserId();
$current_role = getCurrentUserRole();
$can_manage = in_array($current_role, ['Admin', 'Super Admin', 'Super Administrator', 'Staff']);

// Get current month and year
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Validate month and year
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

// Get events for current month
$first_day = "$year-$month-01";
$last_day = date('Y-m-t', strtotime($first_day));

$stmt = $conn->prepare("
    SELECT e.id as event_id, e.title, e.description, e.event_date, e.start_time, 
           e.end_time, e.location, e.event_type, e.color, e.created_by, e.is_active,
           u.username as created_by_name
    FROM tbl_calendar_events e
    LEFT JOIN tbl_users u ON e.created_by = u.user_id
    WHERE e.event_date BETWEEN ? AND ? AND e.is_active = 1
    ORDER BY e.event_date, e.start_time
");
$stmt->bind_param("ss", $first_day, $last_day);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    $date_key = $row['event_date'];
    if (!isset($events[$date_key])) {
        $events[$date_key] = [];
    }
    $events[$date_key][] = $row;
}
$stmt->close();

// Get upcoming events (next 30 days from today)
$today = date('Y-m-d');
$upcoming_end = date('Y-m-d', strtotime('+30 days'));

$stmt = $conn->prepare("
    SELECT e.id as event_id, e.title, e.description, e.event_date, e.start_time, 
           e.end_time, e.location, e.event_type, e.color, e.created_by, e.is_active,
           u.username as created_by_name
    FROM tbl_calendar_events e
    LEFT JOIN tbl_users u ON e.created_by = u.user_id
    WHERE e.event_date BETWEEN ? AND ? AND e.is_active = 1
    ORDER BY e.event_date, e.start_time
    LIMIT 10
");
$stmt->bind_param("ss", $today, $upcoming_end);
$stmt->execute();
$upcoming_result = $stmt->get_result();

$upcoming_events = [];
while ($row = $upcoming_result->fetch_assoc()) {
    $upcoming_events[] = $row;
}
$stmt->close();

// Calendar calculations
$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = date('t', $first_day_of_month);
$day_of_week = date('w', $first_day_of_month);

$prev_month = $month - 1;
$prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $month + 1;
$next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

include '../../includes/header.php';
?>

<style>
.calendar-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.calendar-layout {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 2rem;
    align-items: start;
}

@media (max-width: 1024px) {
    .calendar-layout {
        grid-template-columns: 1fr;
    }
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.month-year {
    font-size: 1.75rem;
    font-weight: 700;
    color: #2d3748;
}

.calendar-nav {
    display: flex;
    gap: 0.5rem;
}

.nav-btn {
    padding: 0.5rem 1rem;
    border: none;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-decoration: none;
    color: #2d3748;
}

.nav-btn:hover {
    background: #f7fafc;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.add-event-btn {
    background: #0d6efd;
    color: white;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.add-event-btn:hover {
    background: #0b5ed7;
    transform: translateY(-2px);
}

.calendar-grid {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: #f7fafc;
    border-bottom: 2px solid #e2e8f0;
}

.weekday {
    padding: 1rem;
    text-align: center;
    font-weight: 600;
    color: #4a5568;
    font-size: 0.875rem;
    text-transform: uppercase;
}

.calendar-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    min-height: 600px;
}

.calendar-day {
    min-height: 100px;
    border: 1px solid #e2e8f0;
    padding: 0.5rem;
    background: white;
    cursor: pointer;
    transition: background 0.2s;
}

.calendar-day:hover {
    background: #f7fafc;
}

.calendar-day.empty {
    background: #f9fafb;
    cursor: default;
}

.calendar-day.today {
    background: #ebf8ff;
    border-color: #0d6efd;
}

.day-number {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
}

.calendar-day.today .day-number {
    color: #0d6efd;
    font-size: 1rem;
}

.day-events {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.event-pill {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: all 0.2s;
}

.event-pill:hover {
    transform: translateX(2px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.legend {
    display: flex;
    gap: 1.5rem;
    margin-top: 1.5rem;
    flex-wrap: wrap;
    padding: 1rem;
    background: white;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 4px;
}

.upcoming-section {
    position: sticky;
    top: 2rem;
}

.upcoming-header {
    background: white;
    padding: 1.5rem;
    border-radius: 12px 12px 0 0;
    border-bottom: 2px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.upcoming-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.upcoming-list {
    background: white;
    border-radius: 0 0 12px 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    max-height: 600px;
    overflow-y: auto;
}

.upcoming-event {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    transition: all 0.2s;
}

.upcoming-event:last-child {
    border-bottom: none;
}

.upcoming-event:hover {
    background: #f7fafc;
    padding-left: 2rem;
}

.upcoming-event-date {
    font-size: 0.75rem;
    color: #718096;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}

.upcoming-event-title {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.upcoming-event-type {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.upcoming-event-time {
    font-size: 0.875rem;
    color: #4a5568;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.upcoming-event-location {
    font-size: 0.875rem;
    color: #718096;
    margin-top: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.no-upcoming {
    padding: 2rem 1.5rem;
    text-align: center;
    color: #718096;
}

.no-upcoming i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.3;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-body {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #2d3748;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #cbd5e0;
    border-radius: 6px;
    font-size: 1rem;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13,110,253,0.1);
}

.btn-group {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.5rem;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
    flex: 1;
}

.btn-primary {
    background: #0d6efd;
    color: white;
}

.btn-primary:hover {
    background: #0b5ed7;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

.event-details {
    padding: 1rem;
    background: #f7fafc;
    border-radius: 8px;
    margin-top: 1rem;
}

.event-details p {
    margin: 0.5rem 0;
    color: #4a5568;
}

.event-details strong {
    color: #2d3748;
}

.error-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1001;
    align-items: center;
    justify-content: center;
}

.error-modal.show {
    display: flex;
}

.error-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.error-modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 1rem;
    background: #fee;
    border-radius: 12px 12px 0 0;
}

.error-modal-header i {
    color: #dc3545;
    font-size: 1.5rem;
}

.error-modal-header h3 {
    margin: 0;
    color: #dc3545;
    font-size: 1.25rem;
}

.error-modal-body {
    padding: 1.5rem;
}

.error-modal-body p {
    margin: 0;
    color: #4a5568;
    line-height: 1.6;
}

.error-modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
}

.error-modal-footer button {
    padding: 0.5rem 1.5rem;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.error-modal-footer button:hover {
    background: #c82333;
}

.confirm-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1001;
    align-items: center;
    justify-content: center;
}

.confirm-modal.show {
    display: flex;
}

.confirm-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    animation: slideDown 0.3s ease-out;
}

.confirm-modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 1rem;
    background: #fff3cd;
    border-radius: 12px 12px 0 0;
}

.confirm-modal-header i {
    color: #856404;
    font-size: 1.5rem;
}

.confirm-modal-header h3 {
    margin: 0;
    color: #856404;
    font-size: 1.25rem;
}

.confirm-modal-body {
    padding: 1.5rem;
}

.confirm-modal-body p {
    margin: 0;
    color: #4a5568;
    line-height: 1.6;
}

.confirm-modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

.confirm-modal-footer button {
    padding: 0.5rem 1.5rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.confirm-modal-footer .btn-confirm-cancel {
    background: #6c757d;
    color: white;
}

.confirm-modal-footer .btn-confirm-cancel:hover {
    background: #5a6268;
}

.confirm-modal-footer .btn-confirm-delete {
    background: #dc3545;
    color: white;
}

.confirm-modal-footer .btn-confirm-delete:hover {
    background: #c82333;
}

@media (max-width: 768px) {
    .calendar-weekdays, .calendar-days {
        font-size: 0.75rem;
    }
    
    .calendar-day {
        min-height: 80px;
        padding: 0.25rem;
    }
    
    .day-number {
        font-size: 0.75rem;
    }
    
    .event-pill {
        font-size: 0.65rem;
        padding: 0.125rem 0.25rem;
    }
}
</style>

<div class="calendar-container">
    <div class="calendar-header">
        <h1 class="month-year">
            <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
        </h1>
        <div class="calendar-nav">
            <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="nav-btn">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            <a href="?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="nav-btn">
                Today
            </a>
            <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="nav-btn">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php if ($can_manage): ?>
            <button onclick="openAddEventModal()" class="add-event-btn">
                <i class="fas fa-plus"></i> Add Event
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="calendar-layout">
        <div>
            <div class="calendar-grid">
                <div class="calendar-weekdays">
                    <div class="weekday">Sun</div>
                    <div class="weekday">Mon</div>
                    <div class="weekday">Tue</div>
                    <div class="weekday">Wed</div>
                    <div class="weekday">Thu</div>
                    <div class="weekday">Fri</div>
                    <div class="weekday">Sat</div>
                </div>

                <div class="calendar-days">
                    <?php
                    // Empty cells before first day
                    for ($i = 0; $i < $day_of_week; $i++) {
                        echo '<div class="calendar-day empty"></div>';
                    }

                    // Days of month
                    $today_date = date('Y-m-d');
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $is_today = ($current_date === $today_date) ? 'today' : '';
                        $day_events = isset($events[$current_date]) ? $events[$current_date] : [];
                        
                        echo '<div class="calendar-day ' . $is_today . '" onclick="viewDayEvents(\'' . $current_date . '\')">';
                        echo '<div class="day-number">' . $day . '</div>';
                        
                        if (!empty($day_events)) {
                            echo '<div class="day-events">';
                            foreach (array_slice($day_events, 0, 3) as $event) {
                                $time = $event['start_time'] ? date('g:i A', strtotime($event['start_time'])) : '';
                                echo '<div class="event-pill" style="background-color: ' . htmlspecialchars($event['color']) . '20; color: ' . htmlspecialchars($event['color']) . ';" onclick="event.stopPropagation(); viewEvent(' . (int)$event['event_id'] . ')">';
                                echo htmlspecialchars($event['title']);
                                echo '</div>';
                            }
                            if (count($day_events) > 3) {
                                echo '<div class="event-pill" style="background: #e2e8f0; color: #4a5568;">+' . (count($day_events) - 3) . ' more</div>';
                            }
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                    ?>
                </div>
            </div>

            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color" style="background: #0d6efd;"></div>
                    <span>Meeting</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #198754;"></div>
                    <span>Activity</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #dc3545;"></div>
                    <span>Holiday</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #ffc107;"></div>
                    <span>Emergency</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #6c757d;"></div>
                    <span>Other</span>
                </div>
            </div>
        </div>

        <!-- Upcoming Events Section -->
        <div class="upcoming-section">
            <div class="upcoming-header">
                <h2><i class="fas fa-calendar-day"></i> Upcoming Events</h2>
            </div>
            <div class="upcoming-list">
                <?php if (empty($upcoming_events)): ?>
                    <div class="no-upcoming">
                        <i class="fas fa-calendar-check"></i>
                        <p>No upcoming events</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($upcoming_events as $event): ?>
                        <div class="upcoming-event" onclick="viewEvent(<?php echo (int)$event['event_id']; ?>)">
                            <div class="upcoming-event-date">
                                <?php 
                                $event_date = new DateTime($event['event_date']);
                                $event_today = new DateTime(date('Y-m-d'));
                                $event_tomorrow = new DateTime(date('Y-m-d', strtotime('+1 day')));
                                
                                if ($event_date->format('Y-m-d') === $event_today->format('Y-m-d')) {
                                    echo 'Today';
                                } elseif ($event_date->format('Y-m-d') === $event_tomorrow->format('Y-m-d')) {
                                    echo 'Tomorrow';
                                } else {
                                    echo $event_date->format('M j, Y');
                                }
                                ?>
                            </div>
                            <div class="upcoming-event-title">
                                <span class="upcoming-event-type" style="background: <?php echo htmlspecialchars($event['color']); ?>;"></span>
                                <?php echo htmlspecialchars($event['title']); ?>
                            </div>
                            <?php if ($event['start_time']): ?>
                                <div class="upcoming-event-time">
                                    <i class="fas fa-clock" style="font-size: 0.75rem;"></i>
                                    <?php 
                                    echo date('g:i A', strtotime($event['start_time']));
                                    if ($event['end_time']) {
                                        echo ' - ' . date('g:i A', strtotime($event['end_time']));
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($event['location']): ?>
                                <div class="upcoming-event-location">
                                    <i class="fas fa-map-marker-alt" style="font-size: 0.75rem;"></i>
                                    <?php echo htmlspecialchars($event['location']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Event Modal -->
<div id="eventModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add Event</h2>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form id="eventForm" action="process-calendar.php" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="event_id" id="eventId">
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" id="eventTitle" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Date *</label>
                    <input type="date" name="event_date" id="eventDate" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" id="startTime" class="form-control">
                </div>

                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" id="endTime" class="form-control">
                </div>

                <div class="form-group">
                    <label>Event Type</label>
                    <select name="event_type" id="eventType" class="form-control">
                        <option value="Meeting">Meeting</option>
                        <option value="Activity">Activity</option>
                        <option value="Holiday">Holiday</option>
                        <option value="Emergency">Emergency</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" id="eventLocation" class="form-control">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="eventDescription" class="form-control" rows="3"></textarea>
                </div>

                <div class="btn-group">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Event</button>
                </div>
            </form>
            
            <?php if ($can_manage): ?>
            <div id="deleteSection" style="display: none; margin-top: 1rem;">
                <button onclick="deleteEvent()" class="btn btn-danger" style="width: 100%;">
                    <i class="fas fa-trash"></i> Delete Event
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Event Modal -->
<div id="viewEventModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Event Details</h2>
            <button onclick="closeViewModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body" id="eventDetailsContainer">
            <!-- Event details will be loaded here -->
        </div>
    </div>
</div>

<!-- Error Modal -->
<div id="errorModal" class="error-modal">
    <div class="error-modal-content">
        <div class="error-modal-header">
            <i class="fas fa-exclamation-circle"></i>
            <h3>Error</h3>
        </div>
        <div class="error-modal-body">
            <p id="errorMessage">An error occurred</p>
        </div>
        <div class="error-modal-footer">
            <button onclick="closeErrorModal()">OK</button>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmModal" class="confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Confirm Action</h3>
        </div>
        <div class="confirm-modal-body">
            <p id="confirmMessage">Are you sure?</p>
        </div>
        <div class="confirm-modal-footer">
            <button class="btn-confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn-confirm-delete" id="confirmButton">Confirm</button>
        </div>
    </div>
</div>

<script>
const events = <?php echo json_encode($events); ?>;
const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;

function showError(message) {
    document.getElementById('errorMessage').textContent = message;
    document.getElementById('errorModal').classList.add('show');
}

function closeErrorModal() {
    document.getElementById('errorModal').classList.remove('show');
}

function showConfirm(message, onConfirm) {
    document.getElementById('confirmMessage').textContent = message;
    document.getElementById('confirmModal').classList.add('show');
    
    // Set up the confirm button click handler
    const confirmBtn = document.getElementById('confirmButton');
    confirmBtn.onclick = function() {
        closeConfirmModal();
        onConfirm();
    };
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
}

function openAddEventModal() {
    document.getElementById('modalTitle').textContent = 'Add Event';
    document.getElementById('formAction').value = 'add';
    document.getElementById('eventForm').reset();
    document.getElementById('deleteSection').style.display = 'none';
    document.getElementById('eventModal').classList.add('show');
}

function closeModal() {
    document.getElementById('eventModal').classList.remove('show');
}

function closeViewModal() {
    document.getElementById('viewEventModal').classList.remove('show');
}

function viewEvent(eventId) {
    console.log('Viewing event ID:', eventId); // Debug log
    
    fetch('get-event.php?id=' + eventId)
        .then(response => {
            console.log('Response status:', response.status); // Debug log
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(event => {
            console.log('Event data:', event); // Debug log
            
            if (event.error) {
                showError(event.error);
                return;
            }

            let html = '<div class="event-details">';
            html += '<h3 style="margin-top: 0; color: ' + event.color + ';">' + event.title + '</h3>';
            
            if (event.description) {
                html += '<p><strong>Description:</strong> ' + event.description + '</p>';
            }
            
            html += '<p><strong>Date:</strong> ' + formatDate(event.event_date) + '</p>';
            
            if (event.start_time) {
                html += '<p><strong>Time:</strong> ' + formatTime(event.start_time);
                if (event.end_time) {
                    html += ' - ' + formatTime(event.end_time);
                }
                html += '</p>';
            }
            
            if (event.location) {
                html += '<p><strong>Location:</strong> ' + event.location + '</p>';
            }
            
            html += '<p><strong>Type:</strong> ' + event.event_type + '</p>';
            html += '</div>';

            if (canManage) {
                html += '<div class="btn-group">';
                html += '<button onclick="editEvent(' + JSON.stringify(event).replace(/"/g, '&quot;') + ')" class="btn btn-primary">Edit</button>';
                html += '<button onclick="closeViewModal()" class="btn btn-secondary">Close</button>';
                html += '</div>';
            } else {
                html += '<button onclick="closeViewModal()" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Close</button>';
            }

            document.getElementById('eventDetailsContainer').innerHTML = html;
            document.getElementById('viewEventModal').classList.add('show');
        })
        .catch(error => {
            console.error('Error fetching event:', error); // Debug log
            showError('Failed to load event details. Please try again.');
        });
}

function editEvent(event) {
    closeViewModal();
    
    document.getElementById('modalTitle').textContent = 'Edit Event';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('eventId').value = event.event_id;
    document.getElementById('eventTitle').value = event.title;
    document.getElementById('eventDate').value = event.event_date;
    document.getElementById('startTime').value = event.start_time || '';
    document.getElementById('endTime').value = event.end_time || '';
    document.getElementById('eventType').value = event.event_type;
    document.getElementById('eventLocation').value = event.location || '';
    document.getElementById('eventDescription').value = event.description || '';
    document.getElementById('deleteSection').style.display = 'block';
    document.getElementById('eventModal').classList.add('show');
}

function deleteEvent() {
    showConfirm('Are you sure you want to delete this event?', function() {
        const eventId = document.getElementById('eventId').value;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process-calendar.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'event_id';
        idInput.value = eventId;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        form.submit();
    });
}

function viewDayEvents(date) {
    if (!events[date]) return;
    
    let html = '<h3 style="margin-top: 0;">' + formatDate(date) + '</h3>';
    html += '<div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem;">';
    
    events[date].forEach(event => {
        html += '<div class="event-details" style="cursor: pointer;" onclick="viewEvent(' + event.event_id + ')">';
        html += '<div style="display: flex; align-items: center; gap: 0.5rem;">';
        html += '<div style="width: 4px; height: 40px; background: ' + event.color + '; border-radius: 2px;"></div>';
        html += '<div>';
        html += '<strong>' + event.title + '</strong>';
        if (event.start_time) {
            html += '<br><small>' + formatTime(event.start_time);
            if (event.end_time) html += ' - ' + formatTime(event.end_time);
            html += '</small>';
        }
        html += '</div></div></div>';
    });
    
    html += '</div>';
    html += '<button onclick="closeViewModal()" class="btn btn-secondary" style="width: 100%; margin-top: 1rem;">Close</button>';
    
    document.getElementById('eventDetailsContainer').innerHTML = html;
    document.getElementById('viewEventModal').classList.add('show');
}

function formatDate(dateStr) {
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                   'July', 'August', 'September', 'October', 'November', 'December'];
    const d = new Date(dateStr + 'T00:00:00');
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const [h, m] = timeStr.split(':');
    const hour = parseInt(h);
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 || 12;
    return hour12 + ':' + m + ' ' + ampm;
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
    if (event.target.classList.contains('error-modal')) {
        event.target.classList.remove('show');
    }
    if (event.target.classList.contains('confirm-modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php include '../../includes/footer.php'; ?>