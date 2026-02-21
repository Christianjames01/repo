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
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');

// Validate month and year
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

// Get events for current month
$first_day = "$year-$month-01";
$last_day  = date('Y-m-t', strtotime($first_day));

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
    if (!isset($events[$date_key])) $events[$date_key] = [];
    $events[$date_key][] = $row;
}
$stmt->close();

// Upcoming events (next 30 days)
$today        = date('Y-m-d');
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
$upcoming_result  = $stmt->get_result();
$upcoming_events  = [];
while ($row = $upcoming_result->fetch_assoc()) $upcoming_events[] = $row;
$stmt->close();

// Calendar calculations
$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
$days_in_month      = date('t', $first_day_of_month);
$day_of_week        = date('w', $first_day_of_month);

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1)  { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1;  $next_year++; }

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<style>
/* ══════════════════════════════════════════
   CALENDAR — using dashboard-index.css vars
══════════════════════════════════════════ */

/* ── page wrapper ── */
.cal-wrap {
    padding: 24px 28px 40px;
    max-width: 1500px;
}

/* ── page hero ── */
.cal-hero {
    background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-light) 65%, #224090 100%);
    border-radius: var(--db-radius-lg);
    padding: 26px 32px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    position: relative;
    overflow: hidden;
}

.cal-hero::before,
.cal-hero::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.06);
    pointer-events: none;
}
.cal-hero::before { width: 260px; height: 260px; top: -120px; right: -60px; }
.cal-hero::after  { width: 140px; height: 140px; bottom: -60px; right: 120px; border-color: rgba(245,158,11,.12); }

.cal-hero__left {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 1;
}

.cal-hero__icon {
    width: 50px; height: 50px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--db-amber), #d97706);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff;
    box-shadow: 0 4px 16px rgba(245,158,11,.35);
    flex-shrink: 0;
}

.cal-hero__month {
    font-size: 24px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.5px;
}

.cal-hero__sub {
    font-size: 12px;
    color: rgba(255,255,255,.55);
    margin-top: 2px;
    font-family: 'DM Mono', monospace;
    letter-spacing: .5px;
}

.cal-hero__nav {
    display: flex;
    gap: 8px;
    align-items: center;
    position: relative;
    z-index: 1;
    flex-wrap: wrap;
}

/* ── nav buttons reuse db-btn ── */
.cal-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--db-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(255,255,255,.1);
    color: rgba(255,255,255,.88);
    text-decoration: none;
    transition: all .18s ease;
    backdrop-filter: blur(8px);
    white-space: nowrap;
}
.cal-nav-btn:hover {
    background: rgba(255,255,255,.2);
    color: #fff;
    border-color: rgba(255,255,255,.3);
}
.cal-nav-btn--today {
    background: rgba(245,158,11,.18);
    border-color: rgba(245,158,11,.35);
    color: #fcd34d;
}
.cal-nav-btn--today:hover {
    background: rgba(245,158,11,.3);
    color: var(--db-amber);
}
.cal-nav-btn--add {
    background: linear-gradient(135deg, var(--db-amber), #d97706);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 4px 12px rgba(245,158,11,.35);
}
.cal-nav-btn--add:hover {
    background: linear-gradient(135deg, #fbbf24, #b45309);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(245,158,11,.45);
    color: #fff;
}

/* ── layout grid ── */
.cal-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 18px;
    align-items: start;
}
@media (max-width: 1080px) { .cal-layout { grid-template-columns: 1fr; } }

/* ── calendar panel shell (reuses .db-panel look) ── */
.cal-panel {
    background: var(--db-surf);
    border-radius: var(--db-radius-lg);
    border: 1px solid var(--db-border);
    box-shadow: var(--db-shadow);
    overflow: hidden;
    margin-bottom: 18px;
    animation: dbFadeUp .35s ease both;
}

/* ── weekday header row ── */
.cal-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
}

.cal-weekday {
    padding: 12px 8px;
    text-align: center;
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: rgba(255,255,255,.7);
}

/* ── days grid ── */
.cal-days {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

.cal-day {
    min-height: 110px;
    border-right: 1px solid var(--db-border);
    border-bottom: 1px solid var(--db-border);
    padding: 8px 7px;
    background: var(--db-surf);
    cursor: pointer;
    transition: background .15s;
    position: relative;
}
.cal-day:nth-child(7n) { border-right: none; }
.cal-day:hover { background: var(--db-surf2); }

.cal-day--empty {
    background: #f8fafc;
    cursor: default;
}
.cal-day--empty:hover { background: #f8fafc; }

.cal-day--today {
    background: #eff6ff;
}
.cal-day--today:hover { background: #dbeafe; }

.cal-day__num {
    font-size: 12px;
    font-weight: 700;
    color: var(--db-muted);
    margin-bottom: 5px;
    line-height: 1;
}
.cal-day--today .cal-day__num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px; height: 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
    color: #fff;
    font-size: 12px;
}

/* ── event pills ── */
.cal-day__events {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.cal-pill {
    padding: 3px 7px;
    border-radius: 5px;
    font-size: 10.5px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    transition: all .15s;
    border-left: 2px solid transparent;
    letter-spacing: .1px;
}
.cal-pill:hover {
    transform: translateX(2px);
    box-shadow: 0 2px 6px rgba(0,0,0,.12);
}
.cal-pill--more {
    background: var(--db-surf2) !important;
    color: var(--db-muted) !important;
    border-left-color: var(--db-border) !important;
    font-size: 10px;
}

/* ── legend ── */
.cal-legend {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    padding: 14px 20px;
    border-top: 1px solid var(--db-border);
    background: var(--db-surf2);
}

.cal-legend__item {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11.5px;
    color: var(--db-muted);
    font-weight: 500;
}

.cal-legend__dot {
    width: 10px; height: 10px;
    border-radius: 3px;
    flex-shrink: 0;
}

/* ── upcoming sidebar ── */
.cal-upcoming {
    position: sticky;
    top: 20px;
}

.cal-upcoming__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 22px;
    border-bottom: 1px solid var(--db-border);
}

.cal-upcoming__header h2 {
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.2px;
}

.cal-upcoming__list {
    max-height: 620px;
    overflow-y: auto;
}

.cal-event-card {
    padding: 14px 22px;
    border-bottom: 1px solid var(--db-border);
    cursor: pointer;
    transition: all .15s;
}
.cal-event-card:last-child { border-bottom: none; }
.cal-event-card:hover {
    background: var(--db-surf2);
    padding-left: 28px;
}

.cal-event-card__date {
    font-family: 'DM Mono', monospace;
    font-size: 9.5px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--db-muted);
    margin-bottom: 5px;
}

.cal-event-card__title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 13.5px;
    color: var(--db-text);
    margin-bottom: 4px;
}

.cal-event-card__dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.cal-event-card__meta {
    display: flex;
    flex-direction: column;
    gap: 3px;
    margin-top: 5px;
}

.cal-event-card__row {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    color: var(--db-muted);
}

.cal-event-card__row i {
    width: 12px;
    color: var(--db-navy-light);
    font-size: 10px;
}

/* ── empty state ── */
.cal-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 24px;
    text-align: center;
    gap: 10px;
}
.cal-empty i { font-size: 36px; color: var(--db-border); }
.cal-empty p { font-size: 13px; color: var(--db-muted); }

/* ═══════════════════════════
   MODALS — reuse db-modal system
═══════════════════════════ */
.cal-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(13,27,54,.55);
    backdrop-filter: blur(5px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.cal-modal--open { display: flex; }

.cal-modal__box {
    background: var(--db-surf);
    border-radius: var(--db-radius-lg);
    width: 100%;
    max-width: 520px;
    max-height: 92vh;
    overflow-y: auto;
    box-shadow: var(--db-shadow-lg);
    animation: dbModalIn .28s cubic-bezier(.34,1.56,.64,1);
}
.cal-modal__box--sm { max-width: 420px; }

.cal-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px;
    background: linear-gradient(135deg, var(--db-navy), var(--db-navy-light));
    border-radius: var(--db-radius-lg) var(--db-radius-lg) 0 0;
}
.cal-modal__header--warn {
    background: linear-gradient(135deg, #92400e, var(--db-amber));
}
.cal-modal__header--danger {
    background: linear-gradient(135deg, #7f1d1d, var(--db-danger));
}

.cal-modal__header h2, .cal-modal__header h3 {
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cal-modal__close {
    background: rgba(255,255,255,.12);
    border: none;
    color: rgba(255,255,255,.85);
    width: 30px; height: 30px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.cal-modal__close:hover { background: rgba(255,255,255,.22); color: #fff; }

.cal-modal__body { padding: 22px; }

/* form fields */
.cal-field { margin-bottom: 15px; }
.cal-field label {
    display: block;
    font-size: 12.5px;
    font-weight: 600;
    margin-bottom: 5px;
    color: var(--db-text);
}
.cal-field .req { color: var(--db-rose); }
.cal-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.cal-input {
    width: 100%;
    padding: 9px 13px;
    border: 1.5px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    color: var(--db-text);
    background: var(--db-surf);
    outline: none;
    transition: all .18s;
    appearance: none;
    box-sizing: border-box;
}
.cal-input:focus {
    border-color: var(--db-navy-light);
    box-shadow: 0 0 0 3px rgba(28,52,97,.1);
}
.cal-input::placeholder { color: #94a3b8; }
textarea.cal-input { resize: vertical; min-height: 80px; }
select.cal-input { cursor: pointer; }

/* event detail view */
.cal-detail {
    background: var(--db-surf2);
    border: 1px solid var(--db-border);
    border-radius: var(--db-radius);
    padding: 18px;
    margin-bottom: 16px;
}
.cal-detail__title {
    font-size: 17px;
    font-weight: 800;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--db-border);
}
.cal-detail__row {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
    font-size: 13px;
}
.cal-detail__row:last-child { margin-bottom: 0; }
.cal-detail__icon {
    width: 28px; height: 28px;
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
    margin-top: 1px;
}
.cal-detail__label {
    font-size: 10.5px;
    font-family: 'DM Mono', monospace;
    color: var(--db-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 1px;
}
.cal-detail__val { font-weight: 600; color: var(--db-text); }

/* confirm delete panel */
.cal-delete-target {
    background: var(--db-surf2);
    border: 1px solid var(--db-border);
    border-radius: var(--db-radius-sm);
    padding: 12px 14px;
    font-weight: 600;
    margin: 12px 0;
}
.cal-delete-warn {
    font-size: 12px;
    color: var(--db-danger);
    display: flex;
    align-items: center;
    gap: 5px;
}

/* ── responsive ── */
@media (max-width: 768px) {
    .cal-wrap { padding: 16px; }
    .cal-day { min-height: 80px; padding: 5px 4px; }
    .cal-day__num { font-size: 11px; }
    .cal-pill { font-size: 9.5px; padding: 2px 4px; }
    .cal-weekday { padding: 9px 4px; font-size: 9px; }
    .cal-field-row { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .cal-hero { padding: 18px 16px; }
    .cal-hero__month { font-size: 18px; }
}
</style>

<div class="cal-wrap">

    <!-- ── HERO ── -->
    <div class="cal-hero">
        <div class="cal-hero__left">
            <div class="cal-hero__icon"><i class="fas fa-calendar-alt"></i></div>
            <div>
                <div class="cal-hero__month"><?php echo date('F Y', mktime(0,0,0,$month,1,$year)); ?></div>
                <div class="cal-hero__sub">Barangay Calendar &amp; Events</div>
            </div>
        </div>

        <div class="cal-hero__nav">
            <a href="?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="cal-nav-btn">
                <i class="fas fa-chevron-left"></i> Prev
            </a>
            <a href="?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="cal-nav-btn cal-nav-btn--today">
                <i class="fas fa-circle-dot"></i> Today
            </a>
            <a href="?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="cal-nav-btn">
                Next <i class="fas fa-chevron-right"></i>
            </a>
            <?php if ($can_manage): ?>
            <button onclick="openAddEventModal()" class="cal-nav-btn cal-nav-btn--add">
                <i class="fas fa-plus"></i> Add Event
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php echo displayMessage(); ?>

    <div class="cal-layout">

        <!-- ── MAIN CALENDAR ── -->
        <div>
            <div class="cal-panel">
                <!-- Weekdays -->
                <div class="cal-weekdays">
                    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?>
                    <div class="cal-weekday"><?php echo $d; ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Days -->
                <div class="cal-days">
                    <?php
                    // Empty leading cells
                    for ($i = 0; $i < $day_of_week; $i++) {
                        echo '<div class="cal-day cal-day--empty"></div>';
                    }

                    $today_date = date('Y-m-d');
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $cur = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $is_today = ($cur === $today_date) ? 'cal-day--today' : '';
                        $day_events = $events[$cur] ?? [];

                        echo '<div class="cal-day ' . $is_today . '" onclick="viewDayEvents(\'' . $cur . '\')">';
                        echo '<div class="cal-day__num">' . $day . '</div>';

                        if (!empty($day_events)) {
                            echo '<div class="cal-day__events">';
                            foreach (array_slice($day_events, 0, 3) as $ev) {
                                $c = htmlspecialchars($ev['color']);
                                echo '<div class="cal-pill" style="background:' . $c . '18;color:' . $c . ';border-left-color:' . $c . ';" onclick="event.stopPropagation();viewEvent(' . (int)$ev['event_id'] . ')">';
                                echo htmlspecialchars($ev['title']);
                                echo '</div>';
                            }
                            if (count($day_events) > 3) {
                                $extra = count($day_events) - 3;
                                echo '<div class="cal-pill cal-pill--more">+' . $extra . ' more</div>';
                            }
                            echo '</div>';
                        }
                        echo '</div>';
                    }
                    ?>
                </div>

                <!-- Legend -->
                <div class="cal-legend">
                    <?php
                    $types = [
                        ['Meeting',   '#0ea5e9'],
                        ['Activity',  '#10b981'],
                        ['Holiday',   '#e11d48'],
                        ['Emergency', '#f59e0b'],
                        ['Other',     '#64748b'],
                    ];
                    foreach ($types as [$label, $color]):
                    ?>
                    <div class="cal-legend__item">
                        <div class="cal-legend__dot" style="background:<?php echo $color; ?>;"></div>
                        <span><?php echo $label; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── UPCOMING SIDEBAR ── -->
        <div class="cal-upcoming">
            <div class="cal-panel">
                <div class="cal-upcoming__header">
                    <span class="db-panel__icon db-panel__icon--blue"><i class="fas fa-calendar-day"></i></span>
                    <h2>Upcoming Events</h2>
                </div>

                <div class="cal-upcoming__list">
                    <?php if (empty($upcoming_events)): ?>
                    <div class="cal-empty">
                        <i class="fas fa-calendar-check"></i>
                        <p>No upcoming events in the next 30 days</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($upcoming_events as $ev): ?>
                        <?php
                            $ev_date   = new DateTime($ev['event_date']);
                            $ev_today  = new DateTime(date('Y-m-d'));
                            $ev_tmrw   = new DateTime(date('Y-m-d', strtotime('+1 day')));
                            $date_str  = match(true) {
                                $ev_date->format('Y-m-d') === $ev_today->format('Y-m-d') => 'Today',
                                $ev_date->format('Y-m-d') === $ev_tmrw->format('Y-m-d')  => 'Tomorrow',
                                default => $ev_date->format('M j, Y'),
                            };
                            $col = htmlspecialchars($ev['color']);
                        ?>
                        <div class="cal-event-card" onclick="viewEvent(<?php echo (int)$ev['event_id']; ?>)">
                            <div class="cal-event-card__date"><?php echo $date_str; ?></div>
                            <div class="cal-event-card__title">
                                <span class="cal-event-card__dot" style="background:<?php echo $col; ?>;"></span>
                                <?php echo htmlspecialchars($ev['title']); ?>
                            </div>
                            <div class="cal-event-card__meta">
                                <?php if ($ev['start_time']): ?>
                                <div class="cal-event-card__row">
                                    <i class="fas fa-clock"></i>
                                    <?php
                                    echo date('g:i A', strtotime($ev['start_time']));
                                    if ($ev['end_time']) echo ' — ' . date('g:i A', strtotime($ev['end_time']));
                                    ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($ev['location']): ?>
                                <div class="cal-event-card__row">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($ev['location']); ?>
                                </div>
                                <?php endif; ?>
                                <div class="cal-event-card__row">
                                    <i class="fas fa-tag"></i>
                                    <span class="db-badge db-badge--muted"><?php echo htmlspecialchars($ev['event_type']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div><!-- /cal-layout -->
</div><!-- /cal-wrap -->


<!-- ════════════════════════════════════
     ADD / EDIT EVENT MODAL
════════════════════════════════════ -->
<div id="eventModal" class="cal-modal">
    <div class="cal-modal__box">
        <div class="cal-modal__header">
            <h2 id="modalTitle"><i class="fas fa-calendar-plus"></i> Add Event</h2>
            <button class="cal-modal__close" onclick="closeModal('eventModal')">&times;</button>
        </div>
        <div class="cal-modal__body">
            <form id="eventForm" action="process-calendar.php" method="POST">
                <input type="hidden" name="action"   id="formAction" value="add">
                <input type="hidden" name="event_id" id="eventId">

                <div class="cal-field">
                    <label>Title <span class="req">*</span></label>
                    <input type="text" name="title" id="eventTitle" class="cal-input" required>
                </div>
                <div class="cal-field">
                    <label>Date <span class="req">*</span></label>
                    <input type="date" name="event_date" id="eventDate" class="cal-input" required>
                </div>
                <div class="cal-field-row">
                    <div class="cal-field">
                        <label>Start Time</label>
                        <input type="time" name="start_time" id="startTime" class="cal-input">
                    </div>
                    <div class="cal-field">
                        <label>End Time</label>
                        <input type="time" name="end_time" id="endTime" class="cal-input">
                    </div>
                </div>
                <div class="cal-field">
                    <label>Event Type</label>
                    <select name="event_type" id="eventType" class="cal-input">
                        <option value="Meeting">Meeting</option>
                        <option value="Activity">Activity</option>
                        <option value="Holiday">Holiday</option>
                        <option value="Emergency">Emergency</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="cal-field">
                    <label>Location</label>
                    <input type="text" name="location" id="eventLocation" class="cal-input" placeholder="e.g. Barangay Hall">
                </div>
                <div class="cal-field">
                    <label>Description</label>
                    <textarea name="description" id="eventDescription" class="cal-input" rows="3" placeholder="Describe this event…"></textarea>
                </div>

                <div style="display:flex;gap:8px;margin-top:6px;">
                    <button type="button" onclick="closeModal('eventModal')" class="db-btn db-btn--ghost" style="flex:1;">Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary" style="flex:1;"><i class="fas fa-save"></i> Save Event</button>
                </div>
            </form>

            <?php if ($can_manage): ?>
            <div id="deleteSection" style="display:none;margin-top:14px;">
                <button onclick="deleteEvent()" class="db-btn db-btn--danger" style="width:100%;">
                    <i class="fas fa-trash"></i> Delete This Event
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════
     VIEW EVENT MODAL
════════════════════════════════════ -->
<div id="viewEventModal" class="cal-modal">
    <div class="cal-modal__box">
        <div class="cal-modal__header">
            <h2><i class="fas fa-calendar-check"></i> Event Details</h2>
            <button class="cal-modal__close" onclick="closeModal('viewEventModal')">&times;</button>
        </div>
        <div class="cal-modal__body" id="eventDetailsContainer"></div>
    </div>
</div>


<!-- ════════════════════════════════════
     CONFIRM DELETE MODAL
════════════════════════════════════ -->
<div id="confirmModal" class="cal-modal">
    <div class="cal-modal__box cal-modal__box--sm">
        <div class="cal-modal__header cal-modal__header--warn">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h3>
            <button class="cal-modal__close" onclick="closeModal('confirmModal')">&times;</button>
        </div>
        <div class="cal-modal__body">
            <p style="color:var(--db-muted);margin-bottom:14px;">Are you sure you want to delete this event? This action cannot be undone.</p>
            <div class="cal-delete-target" id="confirmEventTitle">—</div>
            <p class="cal-delete-warn"><i class="fas fa-info-circle"></i> Permanent deletion — no recovery.</p>
            <div style="display:flex;gap:8px;margin-top:20px;">
                <button onclick="closeModal('confirmModal')" class="db-btn db-btn--ghost" style="flex:1;">Cancel</button>
                <button id="confirmDeleteBtn" class="db-btn db-btn--danger" style="flex:1;"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════
     ERROR MODAL
════════════════════════════════════ -->
<div id="errorModal" class="cal-modal">
    <div class="cal-modal__box cal-modal__box--sm">
        <div class="cal-modal__header cal-modal__header--danger">
            <h3><i class="fas fa-exclamation-circle"></i> Error</h3>
            <button class="cal-modal__close" onclick="closeModal('errorModal')">&times;</button>
        </div>
        <div class="cal-modal__body">
            <p id="errorMessage" style="color:var(--db-muted);"></p>
            <button onclick="closeModal('errorModal')" class="db-btn db-btn--danger" style="width:100%;margin-top:16px;">OK</button>
        </div>
    </div>
</div>


<script>
const events    = <?php echo json_encode($events); ?>;
const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;

/* ── Modal helpers ── */
function openModal(id)  { document.getElementById(id).classList.add('cal-modal--open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('cal-modal--open'); document.body.style.overflow = ''; }

window.addEventListener('click', e => {
    if (e.target.classList.contains('cal-modal')) closeModal(e.target.id);
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.cal-modal--open').forEach(m => closeModal(m.id));
});

function showError(msg) {
    document.getElementById('errorMessage').textContent = msg;
    openModal('errorModal');
}

/* ── Type → color map (matches dashboard palette) ── */
const typeColors = {
    Meeting:   { bg: 'var(--db-sky-light)',    fg: 'var(--db-sky)',    icon: 'fa-users' },
    Activity:  { bg: 'var(--db-teal-light)',   fg: 'var(--db-teal)',   icon: 'fa-running' },
    Holiday:   { bg: 'var(--db-rose-light)',   fg: 'var(--db-rose)',   icon: 'fa-flag' },
    Emergency: { bg: 'var(--db-amber-light)',  fg: 'var(--db-amber)',  icon: 'fa-bell' },
    Other:     { bg: 'var(--db-surf2)',        fg: 'var(--db-muted)',  icon: 'fa-calendar' },
};

/* ── Add event ── */
function openAddEventModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-calendar-plus"></i> Add Event';
    document.getElementById('formAction').value = 'add';
    document.getElementById('eventForm').reset();
    document.getElementById('deleteSection').style.display = 'none';
    openModal('eventModal');
}

/* ── View event (fetch) ── */
function viewEvent(id) {
    fetch('get-event.php?id=' + id)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(ev => {
            if (ev.error) { showError(ev.error); return; }

            const tc = typeColors[ev.event_type] || typeColors['Other'];
            let html = `<div class="cal-detail">`;
            html += `<div class="cal-detail__title" style="color:${ev.color};">${ev.title}</div>`;

            const rows = [
                { icon: 'fa-calendar-day',   label: 'Date',     val: formatDate(ev.event_date),    bg: 'var(--db-sky-light)',   fg: 'var(--db-sky)'   },
                ev.start_time ? { icon: 'fa-clock',         label: 'Time',     val: formatTime(ev.start_time) + (ev.end_time ? ' — ' + formatTime(ev.end_time) : ''), bg: 'var(--db-amber-light)', fg: 'var(--db-amber)' } : null,
                ev.location   ? { icon: 'fa-map-marker-alt',label: 'Location', val: ev.location,  bg: 'var(--db-rose-light)',  fg: 'var(--db-rose)'  } : null,
                { icon: tc.icon,             label: 'Type',     val: ev.event_type,                bg: tc.bg,                   fg: tc.fg             },
                ev.description ? { icon: 'fa-align-left',   label: 'Notes',    val: ev.description, bg: 'var(--db-surf2)',      fg: 'var(--db-muted)' } : null,
            ];

            rows.filter(Boolean).forEach(row => {
                html += `<div class="cal-detail__row">
                    <div class="cal-detail__icon" style="background:${row.bg};color:${row.fg};">
                        <i class="fas ${row.icon}"></i>
                    </div>
                    <div>
                        <div class="cal-detail__label">${row.label}</div>
                        <div class="cal-detail__val">${row.val}</div>
                    </div>
                </div>`;
            });

            html += `</div>`;

            if (canManage) {
                html += `<div style="display:flex;gap:8px;">
                    <button onclick="editEvent(${JSON.stringify(ev).replace(/"/g,'&quot;')})" class="db-btn db-btn--primary" style="flex:1;"><i class="fas fa-edit"></i> Edit</button>
                    <button onclick="closeModal('viewEventModal')" class="db-btn db-btn--ghost" style="flex:1;">Close</button>
                </div>`;
            } else {
                html += `<button onclick="closeModal('viewEventModal')" class="db-btn db-btn--primary" style="width:100%;"><i class="fas fa-times"></i> Close</button>`;
            }

            document.getElementById('eventDetailsContainer').innerHTML = html;
            openModal('viewEventModal');
        })
        .catch(() => showError('Failed to load event details. Please try again.'));
}

/* ── View day (multiple events) ── */
function viewDayEvents(date) {
    if (!events[date] || !events[date].length) return;
    const dayEvs = events[date];

    let html = `<p style="font-family:'DM Mono',monospace;font-size:10.5px;color:var(--db-muted);margin-bottom:14px;">${formatDate(date)}</p>
    <div style="display:flex;flex-direction:column;gap:10px;">`;

    dayEvs.forEach(ev => {
        html += `<div class="cal-detail" style="cursor:pointer;padding:12px 14px;margin:0;" onclick="viewEvent(${ev.event_id})">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:4px;align-self:stretch;background:${ev.color};border-radius:3px;flex-shrink:0;"></div>
                <div>
                    <div style="font-weight:700;font-size:13.5px;">${ev.title}</div>
                    ${ev.start_time ? `<div style="font-size:11.5px;color:var(--db-muted);margin-top:3px;"><i class="fas fa-clock" style="font-size:10px;margin-right:4px;"></i>${formatTime(ev.start_time)}${ev.end_time ? ' — ' + formatTime(ev.end_time) : ''}</div>` : ''}
                </div>
                <div style="margin-left:auto;"><span class="db-badge db-badge--muted">${ev.event_type}</span></div>
            </div>
        </div>`;
    });

    html += `</div><button onclick="closeModal('viewEventModal')" class="db-btn db-btn--ghost" style="width:100%;margin-top:16px;">Close</button>`;
    document.getElementById('eventDetailsContainer').innerHTML = html;
    openModal('viewEventModal');
}

/* ── Edit event ── */
function editEvent(ev) {
    closeModal('viewEventModal');
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Event';
    document.getElementById('formAction').value    = 'edit';
    document.getElementById('eventId').value       = ev.event_id;
    document.getElementById('eventTitle').value    = ev.title;
    document.getElementById('eventDate').value     = ev.event_date;
    document.getElementById('startTime').value     = ev.start_time  || '';
    document.getElementById('endTime').value       = ev.end_time    || '';
    document.getElementById('eventType').value     = ev.event_type;
    document.getElementById('eventLocation').value = ev.location    || '';
    document.getElementById('eventDescription').value = ev.description || '';
    document.getElementById('deleteSection').style.display = 'block';
    openModal('eventModal');
}

/* ── Delete event ── */
function deleteEvent() {
    const id    = document.getElementById('eventId').value;
    const title = document.getElementById('eventTitle').value;
    document.getElementById('confirmEventTitle').textContent = title;
    document.getElementById('confirmDeleteBtn').onclick = function () {
        closeModal('confirmModal');
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'process-calendar.php';
        [['action','delete'],['event_id', id]].forEach(([n,v]) => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = n; i.value = v;
            form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
    };
    closeModal('eventModal');
    openModal('confirmModal');
}

/* ── Formatters ── */
function formatDate(s) {
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const d = new Date(s + 'T00:00:00');
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}
function formatTime(s) {
    if (!s) return '';
    const [h, m] = s.split(':');
    const hour = parseInt(h);
    return (hour % 12 || 12) + ':' + m + ' ' + (hour >= 12 ? 'PM' : 'AM');
}
</script>

<?php include '../../includes/footer.php'; ?>