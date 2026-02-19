<?php
require_once '../../../config/config.php';

requireLogin();
$user_role = getCurrentUserRole();
$page_title = 'Waste Collection Schedule';

// Get user's address/zone for filtering
$user_id = getCurrentUserId();
$user_info = fetchOne($conn, 
    "SELECT r.address, r.purok 
     FROM tbl_users u 
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id 
     WHERE u.user_id = ?", 
    [$user_id], 'i'
);

// Get all unique zones for filter
$zones = fetchAll($conn, "SELECT DISTINCT area_zone FROM tbl_waste_schedules WHERE status = 'active' ORDER BY area_zone");

// Filter by zone if specified
$filter_zone = $_GET['zone'] ?? '';

$where_clause = "WHERE status = 'active'";
$params = [];
$types = '';

if (!empty($filter_zone)) {
    $where_clause .= " AND (area_zone = ? OR area_zone = 'All Zones')";
    $params[] = $filter_zone;
    $types .= 's';
}

$schedules_sql = "SELECT * FROM tbl_waste_schedules 
                  $where_clause 
                  ORDER BY 
                    FIELD(collection_day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                    collection_time ASC";
$schedules = fetchAll($conn, $schedules_sql, $params, $types);

// Group schedules by day
$schedule_by_day = [];
foreach ($schedules as $schedule) {
    $schedule_by_day[$schedule['collection_day']][] = $schedule;
}

include '../../../includes/header.php';
?>

<style>
.schedule-card {
    border-left: 4px solid;
    transition: all 0.3s;
}
.schedule-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.schedule-card.biodegradable { border-left-color: #28a745; }
.schedule-card.non-biodegradable { border-left-color: #6c757d; }
.schedule-card.recyclable { border-left-color: #17a2b8; }
.schedule-card.hazardous { border-left-color: #dc3545; }
.schedule-card.mixed { border-left-color: #ffc107; }
.waste-icon {
    font-size: 2.5rem;
    opacity: 0.7;
}
.day-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-trash-alt me-2 text-primary"></i>Waste Collection Schedule
                    </h2>
                    <p class="text-muted mb-0">View your area's garbage collection schedule</p>
                </div>
                <a href="report-issue.php" class="btn btn-danger">
                    <i class="fas fa-exclamation-circle me-1"></i>Report Issue
                </a>
            </div>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <!-- Filter Section -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Filter by Zone</label>
                            <select name="zone" class="form-select" onchange="this.form.submit()">
                                <option value="">All Zones</option>
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo htmlspecialchars($zone['area_zone']); ?>"
                                        <?php echo $filter_zone === $zone['area_zone'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($zone['area_zone']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-1"></i>Filter
                            </button>
                            <?php if (!empty($filter_zone)): ?>
                                <a href="?" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Clear Filter
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Important Reminders -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="alert alert-info border-0 shadow-sm">
                <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Important Reminders</h5>
                <ul class="mb-0">
                    <li>Please segregate your waste properly: biodegradable and non-biodegradable</li>
                    <li>Place your waste bins outside your gate before the scheduled collection time</li>
                    <li>Use proper trash bags and ensure bins are covered</li>
                    <li>Do not mix different types of waste</li>
                    <li>For hazardous waste (batteries, chemicals, etc.), use special collection days only</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Schedule by Day -->
    <?php if (empty($schedules)): ?>
        <div class="text-center py-5">
            <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
            <h4 class="text-muted">No schedules available</h4>
            <p class="text-muted">Please check back later or contact the barangay office.</p>
        </div>
    <?php else: ?>
        <?php 
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        foreach ($days as $day): 
            if (!isset($schedule_by_day[$day])) continue;
        ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header day-header text-white py-3">
                            <h4 class="mb-0">
                                <i class="fas fa-calendar-day me-2"></i><?php echo $day; ?>
                            </h4>
                        </div>
                        <div class="card-body p-0">
                            <div class="row g-0">
                                <?php foreach ($schedule_by_day[$day] as $schedule): ?>
                                    <div class="col-md-6">
                                        <div class="schedule-card <?php echo $schedule['waste_type']; ?> p-4 m-3">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <div>
                                                    <h5 class="mb-1">
                                                        <?php 
                                                        $type_labels = [
                                                            'biodegradable' => 'Biodegradable Waste',
                                                            'non-biodegradable' => 'Non-Biodegradable Waste',
                                                            'recyclable' => 'Recyclable Materials',
                                                            'hazardous' => 'Hazardous Waste',
                                                            'mixed' => 'Mixed Waste'
                                                        ];
                                                        echo $type_labels[$schedule['waste_type']] ?? ucfirst($schedule['waste_type']);
                                                        ?>
                                                    </h5>
                                                    <p class="text-muted mb-0">
                                                        <i class="fas fa-map-marker-alt me-1"></i>
                                                        <?php echo htmlspecialchars($schedule['area_zone']); ?>
                                                        <?php if ($schedule['purok']): ?>
                                                            - <?php echo htmlspecialchars($schedule['purok']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <div class="waste-icon">
                                                    <?php
                                                    $icons = [
                                                        'biodegradable' => 'leaf',
                                                        'non-biodegradable' => 'trash',
                                                        'recyclable' => 'recycle',
                                                        'hazardous' => 'skull-crossbones',
                                                        'mixed' => 'trash-alt'
                                                    ];
                                                    $colors = [
                                                        'biodegradable' => 'success',
                                                        'non-biodegradable' => 'secondary',
                                                        'recyclable' => 'info',
                                                        'hazardous' => 'danger',
                                                        'mixed' => 'warning'
                                                    ];
                                                    ?>
                                                    <i class="fas fa-<?php echo $icons[$schedule['waste_type']] ?? 'trash'; ?> text-<?php echo $colors[$schedule['waste_type']] ?? 'secondary'; ?>"></i>
                                                </div>
                                            </div>
                                            
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fas fa-clock text-primary me-2"></i>
                                                        <div>
                                                            <small class="text-muted d-block">Collection Time</small>
                                                            <strong><?php echo date('g:i A', strtotime($schedule['collection_time'])); ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php if ($schedule['collector_name']): ?>
                                                    <div class="col-6">
                                                        <div class="d-flex align-items-center">
                                                            <i class="fas fa-user text-primary me-2"></i>
                                                            <div>
                                                                <small class="text-muted d-block">Collector</small>
                                                                <strong><?php echo htmlspecialchars($schedule['collector_name']); ?></strong>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($schedule['truck_number']): ?>
                                                    <div class="col-6">
                                                        <div class="d-flex align-items-center">
                                                            <i class="fas fa-truck text-primary me-2"></i>
                                                            <div>
                                                                <small class="text-muted d-block">Truck</small>
                                                                <strong><?php echo htmlspecialchars($schedule['truck_number']); ?></strong>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($schedule['notes']): ?>
                                                <div class="mt-3">
                                                    <small class="text-muted">
                                                        <i class="fas fa-info-circle me-1"></i>
                                                        <?php echo htmlspecialchars($schedule['notes']); ?>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Waste Segregation Guide -->
    <div class="row">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="fas fa-lightbulb me-2 text-warning"></i>Waste Segregation Guide</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-leaf fa-3x text-success mb-3"></i>
                                <h6 class="text-success">Biodegradable</h6>
                                <small class="text-muted">Food scraps, garden waste, paper, cardboard</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-trash fa-3x text-secondary mb-3"></i>
                                <h6 class="text-secondary">Non-Biodegradable</h6>
                                <small class="text-muted">Plastics, styrofoam, diapers, sanitary items</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-recycle fa-3x text-info mb-3"></i>
                                <h6 class="text-info">Recyclable</h6>
                                <small class="text-muted">Bottles, cans, clean paper, cardboard boxes</small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3">
                                <i class="fas fa-skull-crossbones fa-3x text-danger mb-3"></i>
                                <h6 class="text-danger">Hazardous</h6>
                                <small class="text-muted">Batteries, chemicals, electronics, medical waste</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
$conn->close();
include '../../../includes/footer.php'; 
?>