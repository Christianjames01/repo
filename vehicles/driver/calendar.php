<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

// Only drivers can access
requireRole('Driver');

$current_user_id = getCurrentUserId();
$page_title = 'My Calendar';

// Helper function
if (!function_exists('tableExists')) {
    function tableExists($conn, $table_name) {
        $result = $conn->query("SHOW TABLES LIKE '$table_name'");
        return $result && $result->num_rows > 0;
    }
}

// Get driver's assigned vehicles for reference - FIXED: using assigned_driver_id
$vehicles_sql = "SELECT vehicle_id, plate_number, brand, vehicle_type
                 FROM tbl_vehicles 
                 WHERE assigned_driver_id = ?
                 ORDER BY plate_number";

$stmt = $conn->prepare($vehicles_sql);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
$my_vehicles = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $my_vehicles[] = $row;
    }
}
$stmt->close();

// Get upcoming events from vehicle logs
$upcoming_events = [];
if (tableExists($conn, 'tbl_vehicle_logs')) {
    $logs_sql = "SELECT l.*, v.plate_number, v.brand, v.vehicle_type
                  FROM tbl_vehicle_logs l
                  INNER JOIN tbl_vehicles v ON l.vehicle_id = v.vehicle_id
                  WHERE v.assigned_driver_id = ?
                  AND DATE(l.log_date) >= CURDATE()
                  ORDER BY l.log_date ASC
                  LIMIT 50";
    
    $stmt = $conn->prepare($logs_sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $upcoming_events[] = [
                'type' => $row['log_type'],
                'date' => date('Y-m-d', strtotime($row['log_date'])),
                'time' => date('H:i:s', strtotime($row['log_date'])),
                'title' => $row['log_type'],
                'description' => $row['description'],
                'vehicle' => $row['plate_number'],
                'details' => $row['brand'] . ' ' . $row['vehicle_type']
            ];
        }
    }
    $stmt->close();
}

include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <a href="index.php" class="btn btn-outline-secondary btn-sm mb-3">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
            <h2 class="mb-0">
                <i class="fas fa-calendar-alt me-2"></i>My Calendar
            </h2>
            <p class="text-muted">View your schedule and upcoming activities</p>
        </div>
    </div>

    <div class="row">
        <!-- Calendar -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="fas fa-calendar me-2"></i>
                            <?php echo date('F Y'); ?>
                        </h5>
                        <div>
                            <span class="badge bg-primary">Today: <?php echo date('M d, Y'); ?></span>
                        </div>
                    </div>
                    
                    <!-- Simple Calendar View -->
                    <div class="calendar-grid">
                        <div class="calendar-header">
                            <div class="calendar-day-header">Sun</div>
                            <div class="calendar-day-header">Mon</div>
                            <div class="calendar-day-header">Tue</div>
                            <div class="calendar-day-header">Wed</div>
                            <div class="calendar-day-header">Thu</div>
                            <div class="calendar-day-header">Fri</div>
                            <div class="calendar-day-header">Sat</div>
                        </div>
                        
                        <?php
                        $current_month = date('n');
                        $current_year = date('Y');
                        $first_day = date('w', strtotime("$current_year-$current_month-01"));
                        $days_in_month = date('t', strtotime("$current_year-$current_month-01"));
                        $today = date('j');
                        
                        echo '<div class="calendar-body">';
                        
                        // Empty cells before month starts
                        for ($i = 0; $i < $first_day; $i++) {
                            echo '<div class="calendar-day empty"></div>';
                        }
                        
                        // Days of the month
                        for ($day = 1; $day <= $days_in_month; $day++) {
                            $is_today = ($day == $today) ? 'today' : '';
                            $date_str = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
                            
                            // Check if there's an event on this day
                            $has_event = false;
                            $event_count = 0;
                            foreach ($upcoming_events as $event) {
                                if ($event['date'] === $date_str) {
                                    $has_event = true;
                                    $event_count++;
                                }
                            }
                            
                            $event_class = $has_event ? 'has-event' : '';
                            
                            echo "<div class='calendar-day $is_today $event_class' data-date='$date_str'>
                                    <span class='day-number'>$day</span>";
                            if ($event_count > 0) {
                                echo "<span class='event-badge'>$event_count</span>";
                            }
                            echo "</div>";
                        }
                        
                        echo '</div>';
                        ?>
                    </div>

                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-circle text-warning me-1"></i> Has scheduled activity
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upcoming Events Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-clock me-2"></i>Upcoming Activities
                    </h5>
                </div>
                <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                    <?php if (empty($upcoming_events)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No upcoming activities</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($upcoming_events as $event): ?>
                            <div class="list-group-item">
                                <div class="d-flex align-items-start">
                                    <div class="event-date-compact me-3">
                                        <div class="event-month-compact"><?php echo date('M', strtotime($event['date'])); ?></div>
                                        <div class="event-day-compact"><?php echo date('d', strtotime($event['date'])); ?></div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">
                                            <span class="badge bg-info"><?php echo htmlspecialchars($event['title']); ?></span>
                                        </h6>
                                        <p class="mb-1 small"><?php echo htmlspecialchars($event['description']); ?></p>
                                        <p class="mb-0 text-muted small">
                                            <i class="fas fa-car me-1"></i><?php echo htmlspecialchars($event['vehicle']); ?>
                                            <span class="ms-2">
                                                <i class="fas fa-clock me-1"></i><?php echo date('g:i A', strtotime($event['time'])); ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="card-title mb-3">
                        <i class="fas fa-chart-bar me-2"></i>Quick Stats
                    </h6>
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="stat-box">
                                <h4 class="text-primary mb-0"><?php echo count($my_vehicles); ?></h4>
                                <small class="text-muted">My Vehicles</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="stat-box">
                                <h4 class="text-info mb-0"><?php echo count($upcoming_events); ?></h4>
                                <small class="text-muted">Upcoming</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- All Upcoming Events (Detailed View) -->
    <?php if (!empty($upcoming_events)): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>All Scheduled Activities
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Vehicle</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming_events as $event): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($event['date'])); ?></strong><br>
                                        <small class="text-muted"><?php echo date('g:i A', strtotime($event['time'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($event['type']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($event['description']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($event['vehicle']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($event['details']); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $days_until = floor((strtotime($event['date']) - time()) / 86400);
                                        if ($days_until == 0) {
                                            echo '<span class="badge bg-danger">Today</span>';
                                        } elseif ($days_until == 1) {
                                            echo '<span class="badge bg-warning text-dark">Tomorrow</span>';
                                        } elseif ($days_until <= 7) {
                                            echo '<span class="badge bg-info">In ' . $days_until . ' days</span>';
                                        } else {
                                            echo '<span class="badge bg-secondary">In ' . $days_until . ' days</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 1px;
    background: #dee2e6;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
}

.calendar-header {
    display: contents;
}

.calendar-day-header {
    background: #f8f9fa;
    padding: 10px;
    text-align: center;
    font-weight: bold;
    font-size: 0.85rem;
    color: #495057;
}

.calendar-body {
    display: contents;
}

.calendar-day {
    background: white;
    padding: 10px;
    min-height: 70px;
    position: relative;
    cursor: pointer;
    transition: all 0.2s;
}

.calendar-day:hover {
    background: #f8f9fa;
    transform: scale(1.02);
}

.calendar-day.empty {
    background: #f8f9fa;
    cursor: default;
}

.calendar-day.empty:hover {
    transform: none;
}

.calendar-day.today {
    background: #e7f3ff;
    font-weight: bold;
    border: 2px solid #0d6efd;
}

.calendar-day.today .day-number {
    color: #0d6efd;
}

.calendar-day.has-event {
    background: #fff8e1;
}

.calendar-day.has-event:hover {
    background: #fff3cd;
}

.day-number {
    display: block;
    font-size: 0.9rem;
}

.event-badge {
    position: absolute;
    bottom: 5px;
    right: 5px;
    background: #ffc107;
    color: #000;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: bold;
}

.event-date-compact {
    text-align: center;
    border: 2px solid #dee2e6;
    border-radius: 6px;
    padding: 5px 10px;
    min-width: 50px;
}

.event-month-compact {
    font-size: 0.7rem;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: bold;
}

.event-day-compact {
    font-size: 1.2rem;
    font-weight: bold;
    color: #212529;
}

.stat-box {
    padding: 10px;
}

.list-group-item {
    transition: background-color 0.2s;
}

.list-group-item:hover {
    background-color: rgba(0, 123, 255, 0.05);
}
</style>

<script>
// Optional: Add click event to calendar days to show events
document.querySelectorAll('.calendar-day.has-event').forEach(day => {
    day.addEventListener('click', function() {
        const date = this.getAttribute('data-date');
        // You can add modal or tooltip functionality here
        console.log('Events for:', date);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>