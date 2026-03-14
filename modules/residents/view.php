<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();

$is_resident = ($user_role === 'Resident');
$is_tanod    = ($user_role === 'Tanod' || $user_role === 'Barangay Tanod');
$can_edit    = !$is_resident && !$is_tanod;

if ($is_resident) { header('Location: ../dashboard/index.php'); exit(); }

$page_title = 'View Resident';
$success = $error = $info = '';

$resident_id = 0;
if (isset($_GET['id'])) {
    $resident_id = intval($_GET['id']);
    if ($resident_id <= 0) { $_SESSION['error_message'] = 'Invalid resident ID.'; header('Location: manage.php'); exit(); }
} else { $_SESSION['error_message'] = 'Resident ID is required.'; header('Location: manage.php'); exit(); }

$stmt = $conn->prepare("SELECT * FROM tbl_residents WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id); $stmt->execute();
$resident = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$resident) { $_SESSION['error_message'] = 'Resident not found.'; header('Location: manage.php'); exit(); }

$age = 0;
if (!empty($resident['date_of_birth'])) {
    try { $age = (new DateTime())->diff(new DateTime($resident['date_of_birth']))->y; } catch (Exception $e) {}
}

$stmt = $conn->prepare("SELECT r.*, rt.request_type_name FROM tbl_requests r LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id WHERE r.resident_id = ? ORDER BY r.request_date DESC LIMIT 10");
$stmt->bind_param("i", $resident_id); $stmt->execute();
$document_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total_requests, SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status='Released' THEN 1 ELSE 0 END) as released, SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected FROM tbl_requests WHERE resident_id = ?");
$stmt->bind_param("i", $resident_id); $stmt->execute();
$req_stats = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!empty($_SESSION['success_message']))     { $success = $_SESSION['success_message'];     unset($_SESSION['success_message']); }
if (!empty($_SESSION['error_message_flash'])) { $error   = $_SESSION['error_message_flash']; unset($_SESSION['error_message_flash']); }
if (!empty($_SESSION['info_message']))        { $info    = $_SESSION['info_message'];         unset($_SESSION['info_message']); }

$has_id   = !empty($resident['id_photo']) && file_exists('../../uploads/ids/' . $resident['id_photo']);
$file_ext = $has_id ? strtolower(pathinfo($resident['id_photo'], PATHINFO_EXTENSION)) : '';
$has_photo = !empty($resident['profile_photo']) && file_exists('../../uploads/profiles/' . $resident['profile_photo']);

$extra_css = '<link rel="stylesheet" href="../../assets/css/dashboard-index.css?v=' . time() . '">';
include '../../includes/header.php';
?>

<style>
/* ── Print ── */
@media print {
    @page { size: A4 portrait; margin: 0.5cm; }
    body { font-size: 9pt; }
    .no-print { display: none !important; }
    .print-only { display: block !important; }
}
.print-only { display: none; }

/* ── Force all CSS vars available (mirrors dashboard-index.css) ── */
:root {
    --db-navy:        #0d1b36;
    --db-navy-mid:    #152849;
    --db-navy-light:  #1c3461;
    --db-amber:       #f59e0b;
    --db-amber-light: #fef3c7;
    --db-amber-dark:  #b45309;
    --db-teal:        #0d9488;
    --db-teal-light:  #ccfbf1;
    --db-rose:        #e11d48;
    --db-rose-light:  #ffe4e6;
    --db-sky:         #0ea5e9;
    --db-sky-light:   #e0f2fe;
    --db-indigo:      #6366f1;
    --db-indigo-light:#e0e7ff;
    --db-success:     #10b981;
    --db-success-light:#d1fae5;
    --db-warning:     #f59e0b;
    --db-warning-light:#fef3c7;
    --db-danger:      #ef4444;
    --db-danger-light:#fee2e2;
    --db-info:        #3b82f6;
    --db-info-light:  #dbeafe;
    --db-bg:          #eef2f7;
    --db-surf:        #ffffff;
    --db-surf2:       #f8fafc;
    --db-border:      #e2e8f0;
    --db-text:        #0f172a;
    --db-muted:       #64748b;
    --db-radius:      14px;
    --db-radius-sm:   8px;
    --db-radius-lg:   20px;
    --db-shadow:      0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:   0 8px 40px rgba(13,27,54,.14), 0 2px 8px rgba(13,27,54,.06);
}

/* ── Page container ── */
.rv-page { padding: 20px 24px 48px; }

/* ══════════════════════════════════════════
   BANNER
══════════════════════════════════════════ */
.rv-banner {
    background: linear-gradient(135deg, #0d1b36 0%, #1c3461 65%, #224090 100%);
    border-radius: 20px;
    padding: 24px 28px;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 40px rgba(13,27,54,.2);
}
.rv-banner::before {
    content: ''; position: absolute;
    width: 300px; height: 300px; border-radius: 50%;
    border: 1px solid rgba(255,255,255,.06);
    top: -130px; right: -50px; pointer-events: none;
}
.rv-banner::after {
    content: ''; position: absolute;
    width: 160px; height: 160px; border-radius: 50%;
    border: 1px solid rgba(245,158,11,.12);
    top: -40px; right: 90px; pointer-events: none;
}

/* ── Avatar ── */
.rv-avatar {
    width: 76px; height: 76px; border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem; font-weight: 800; color: #fff;
    flex-shrink: 0;
    box-shadow: 0 4px 20px rgba(99,102,241,.4);
    border: 3px solid rgba(255,255,255,.22);
    overflow: hidden; position: relative; z-index: 1;
    font-family: 'Sora', sans-serif;
}
.rv-avatar img { width: 100%; height: 100%; object-fit: cover; }

.rv-banner-info { flex: 1; min-width: 200px; position: relative; z-index: 1; }
.rv-banner-name {
    font-size: 1.3rem; font-weight: 800; color: #fff;
    letter-spacing: -0.3px; line-height: 1.2; margin-bottom: 4px;
    font-family: 'Sora', sans-serif;
}
.rv-banner-id {
    font-size: 11px; color: rgba(255,255,255,.45);
    margin-bottom: 10px; letter-spacing: .03em;
}
.rv-banner-badges { display: flex; gap: 6px; flex-wrap: wrap; }

/* ── Banner action buttons ── */
.rv-banner-actions {
    display: flex; gap: 8px; flex-wrap: wrap;
    align-items: center; position: relative; z-index: 1;
}
.rv-wbtn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 12px; font-weight: 600;
    cursor: pointer; text-decoration: none;
    border: 1px solid rgba(255,255,255,.22);
    background: rgba(255,255,255,.1); color: #fff;
    transition: all .18s; white-space: nowrap;
}
.rv-wbtn:hover { background: rgba(255,255,255,.2); color: #fff; transform: translateY(-1px); }
.rv-wbtn-solid {
    background: rgba(255,255,255,.95) !important;
    color: #0d1b36 !important; border-color: transparent !important;
}
.rv-wbtn-solid:hover { background: #fff !important; color: #0d1b36 !important; }

/* ══════════════════════════════════════════
   BADGES (self-contained, don't rely on .db-badge from CSS file)
══════════════════════════════════════════ */
.rv-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 10px; font-weight: 600; letter-spacing: .3px;
    white-space: nowrap; font-family: 'Sora', sans-serif;
}
.rv-badge-success { background: #d1fae5; color: #065f46; }
.rv-badge-warning { background: #fef3c7; color: #92400e; }
.rv-badge-info    { background: #dbeafe; color: #1e40af; }
.rv-badge-muted   { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
.rv-badge-danger  { background: #fee2e2; color: #7f1d1d; }

/* ══════════════════════════════════════════
   STAT CARDS (fully self-contained)
══════════════════════════════════════════ */
.rv-stats {
    display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;
}
.rv-stat {
    flex: 1 1 130px; min-width: 0;
    background: #fff;
    border-radius: 14px;
    padding: 18px 16px 14px;
    display: flex; flex-direction: column; gap: 10px;
    box-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    border: 1px solid #e2e8f0;
    position: relative; overflow: hidden;
}
.rv-stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.rv-stat-icon-blue   { background: #e0f2fe; color: #0ea5e9; }
.rv-stat-icon-amber  { background: #fef3c7; color: #b45309; }
.rv-stat-icon-indigo { background: #e0e7ff; color: #6366f1; }
.rv-stat-icon-teal   { background: #ccfbf1; color: #0d9488; }
.rv-stat-icon-rose   { background: #ffe4e6; color: #e11d48; }
.rv-stat-num {
    font-size: 28px; font-weight: 800; line-height: 1;
    letter-spacing: -1px; color: #0f172a;
    font-family: 'Sora', sans-serif;
}
.rv-stat-label {
    font-size: 10.5px; color: #64748b; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; margin-top: 2px;
}
.rv-stat-bar {
    height: 3px; border-radius: 2px; opacity: .4; margin-top: 2px;
}
.rv-stat-bar-blue   { background: linear-gradient(90deg, #0ea5e9, transparent); }
.rv-stat-bar-amber  { background: linear-gradient(90deg, #f59e0b, transparent); }
.rv-stat-bar-indigo { background: linear-gradient(90deg, #6366f1, transparent); }
.rv-stat-bar-teal   { background: linear-gradient(90deg, #0d9488, transparent); }
.rv-stat-bar-rose   { background: linear-gradient(90deg, #e11d48, transparent); }

/* ══════════════════════════════════════════
   LAYOUT
══════════════════════════════════════════ */
.rv-layout {
    display: grid;
    grid-template-columns: 270px 1fr;
    gap: 18px; align-items: start;
}
@media (max-width: 880px) { .rv-layout { grid-template-columns: 1fr; } }
.rv-sidebar { display: flex; flex-direction: column; gap: 18px; }

/* ══════════════════════════════════════════
   PANEL (self-contained)
══════════════════════════════════════════ */
.rv-panel {
    background: #fff; border-radius: 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    overflow: hidden;
    animation: rvFadeUp .3s ease both;
}
@keyframes rvFadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.rv-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e2e8f0; gap: 10px; flex-wrap: wrap;
}
.rv-panel-title {
    display: flex; align-items: center; gap: 10px;
}
.rv-panel-title h2 {
    font-size: 14.5px; font-weight: 700; letter-spacing: -0.2px;
    color: #0f172a; font-family: 'Sora', sans-serif; margin: 0;
}
.rv-panel-icon {
    width: 32px; height: 32px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.rv-panel-icon-blue   { background: #e0f2fe; color: #0ea5e9; }
.rv-panel-icon-amber  { background: #fef3c7; color: #b45309; }
.rv-panel-icon-rose   { background: #ffe4e6; color: #e11d48; }
.rv-panel-icon-teal   { background: #ccfbf1; color: #0d9488; }
.rv-panel-icon-indigo { background: #e0e7ff; color: #6366f1; }

/* ── ID photo box ── */
.rv-id-box {
    border: 2px dashed #e2e8f0; border-radius: 12px;
    padding: 14px; text-align: center; background: #f8fafc;
    cursor: pointer; transition: border-color .2s; margin: 14px;
}
.rv-id-box:hover { border-color: #0ea5e9; }
.rv-id-box img { max-width: 100%; border-radius: 8px; display: block; }
.rv-id-hint {
    margin-top: 7px; font-size: 11px; color: #64748b;
    display: flex; align-items: center; justify-content: center; gap: 4px;
}

/* ── Quick info rows ── */
.rv-qrow {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 20px; border-bottom: 1px solid #f1f5f9; gap: 8px;
}
.rv-qrow:last-child { border-bottom: none; }
.rv-qlabel {
    font-size: 10.5px; font-weight: 600; color: #64748b;
    text-transform: uppercase; letter-spacing: .05em;
    display: flex; align-items: center; gap: 5px; flex-shrink: 0;
}
.rv-qval {
    font-weight: 600; color: #0f172a; font-size: 12.5px;
    text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    max-width: 160px;
}

/* ── Info grid ── */
.rv-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(165px, 1fr));
    padding: 4px 20px 16px;
}
.rv-item {
    padding: 12px 14px 12px 0;
    border-bottom: 1px solid #f1f5f9;
}
.rv-item-full  { grid-column: 1 / -1; }
.rv-item-last  { border-bottom: none; }
.rv-item-2col  { grid-template-columns: 1fr 1fr; }
.rv-ilabel {
    font-size: 10px; font-weight: 700; color: #94a3b8;
    text-transform: uppercase; letter-spacing: .06em;
    display: flex; align-items: center; gap: 4px; margin-bottom: 5px;
}
.rv-ival {
    font-size: 13.5px; color: #0f172a; font-weight: 500;
    font-family: 'Sora', sans-serif;
}

/* ── Table ── */
.rv-table-wrap { overflow-x: auto; }
.rv-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.rv-table thead tr {
    background: linear-gradient(135deg, #0d1b36, #1c3461);
}
.rv-table thead th {
    color: rgba(255,255,255,.8); font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .8px;
    padding: 11px 16px; white-space: nowrap; border: none;
    font-family: 'Sora', sans-serif;
}
.rv-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.rv-table tbody tr:last-child { border-bottom: none; }
.rv-table tbody tr:hover { background: #f5f8ff; }
.rv-table tbody td { padding: 11px 16px; vertical-align: middle; }
.rv-tid { font-size: 11px; color: #6366f1; font-weight: 600; }
.rv-tsm { font-size: 11.5px; color: #64748b; }

/* ── Empty state ── */
.rv-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 40px 24px; text-align: center; gap: 10px;
}
.rv-empty i { font-size: 36px; color: #e2e8f0; }
.rv-empty p { font-size: 13px; color: #64748b; }

/* ── Notice ── */
.rv-notice {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; border-radius: 12px;
    background: #eff6ff; border: 1px solid #bfdbfe;
    color: #1d4ed8; font-size: 13px; margin-bottom: 18px;
}

/* ── Alert ── */
.rv-alert {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 18px; border-radius: 12px;
    margin: 12px 24px 0; font-weight: 500; font-size: 13px;
    border-left: 4px solid; font-family: 'Sora', sans-serif;
}
.rv-alert-success { background: #d1fae5; color: #065f46; border-color: #10b981; }
.rv-alert-error   { background: #fee2e2; color: #7f1d1d; border-color: #ef4444; }
.rv-alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .5; }
.rv-alert-close:hover { opacity: 1; }

/* ── Buttons ── */
.rv-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 600;
    cursor: pointer; text-decoration: none; border: 1px solid transparent;
    transition: all .18s;
}
.rv-btn-primary {
    background: linear-gradient(135deg, #0d1b36, #1c3461); color: #fff;
}
.rv-btn-primary:hover { background: linear-gradient(135deg, #1c3461, #2748a0); color: #fff; transform: translateY(-1px); }
/* ══════════════════════════════════════
   DARK MODE — view.php overrides
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; }

/* Banner stays dark (already dark-colored, just tweak) */
body.dark-mode .rv-banner { box-shadow: 0 8px 40px rgba(0,0,0,.4) !important; }

/* Stat cards */
body.dark-mode .rv-stat {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rv-stat-num  { color: #f1f5f9 !important; }
body.dark-mode .rv-stat-label { color: #94a3b8 !important; }
body.dark-mode .rv-stat-icon-blue   { background: rgba(14,165,233,.15) !important; }
body.dark-mode .rv-stat-icon-amber  { background: rgba(245,158,11,.15) !important; }
body.dark-mode .rv-stat-icon-indigo { background: rgba(99,102,241,.15) !important; }
body.dark-mode .rv-stat-icon-teal   { background: rgba(13,148,136,.15) !important; }
body.dark-mode .rv-stat-icon-rose   { background: rgba(225,29,72,.15)  !important; }

/* Panels */
body.dark-mode .rv-panel {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .rv-panel-header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .rv-panel-title h2 { color: #f1f5f9 !important; }

/* Panel icons */
body.dark-mode .rv-panel-icon-blue   { background: rgba(14,165,233,.15) !important; }
body.dark-mode .rv-panel-icon-teal   { background: rgba(13,148,136,.15) !important; }
body.dark-mode .rv-panel-icon-rose   { background: rgba(225,29,72,.15)  !important; }
body.dark-mode .rv-panel-icon-amber  { background: rgba(245,158,11,.15) !important; }
body.dark-mode .rv-panel-icon-indigo { background: rgba(99,102,241,.15) !important; }

/* Info grid */
body.dark-mode .rv-item { border-bottom-color: #334155 !important; }
body.dark-mode .rv-ilabel { color: #64748b !important; }
body.dark-mode .rv-ival   { color: #e2e8f0 !important; }

/* Quick info rows */
body.dark-mode .rv-qrow  { border-bottom-color: #334155 !important; }
body.dark-mode .rv-qlabel { color: #94a3b8 !important; }
body.dark-mode .rv-qval   { color: #f1f5f9 !important; }

/* ID box */
body.dark-mode .rv-id-box {
    background: #0f172a !important;
    border-color: #334155 !important;
}
body.dark-mode .rv-id-hint { color: #94a3b8 !important; }

/* Table */
body.dark-mode .rv-table tbody tr { border-bottom-color: #334155 !important; }
body.dark-mode .rv-table tbody tr:hover { background: #243044 !important; }
body.dark-mode .rv-table tbody td { color: #e2e8f0 !important; }
body.dark-mode .rv-tid { color: #a5b4fc !important; }
body.dark-mode .rv-tsm { color: #94a3b8 !important; }

/* Empty state */
body.dark-mode .rv-empty p { color: #94a3b8 !important; }
body.dark-mode .rv-empty i { color: #334155 !important; }

/* Badges */
body.dark-mode .rv-badge-success { background: rgba(16,185,129,.2)  !important; color: #6ee7b7 !important; }
body.dark-mode .rv-badge-warning { background: rgba(245,158,11,.2)  !important; color: #fcd34d !important; }
body.dark-mode .rv-badge-info    { background: rgba(59,130,246,.2)  !important; color: #93c5fd !important; }
body.dark-mode .rv-badge-danger  { background: rgba(239,68,68,.2)   !important; color: #fca5a5 !important; }
body.dark-mode .rv-badge-muted   { background: #334155 !important; color: #94a3b8 !important; border-color: #475569 !important; }

/* Notice */
body.dark-mode .rv-notice {
    background: rgba(59,130,246,.12) !important;
    border-color: rgba(59,130,246,.3) !important;
    color: #93c5fd !important;
}

/* Alerts */
body.dark-mode .rv-alert-success {
    background: rgba(16,185,129,.15) !important;
    color: #6ee7b7 !important; border-color: #10b981 !important;
}
body.dark-mode .rv-alert-error {
    background: rgba(239,68,68,.15) !important;
    color: #fca5a5 !important; border-color: #ef4444 !important;
}

</style>

<!-- ════════ PRINT LAYOUT ════════ -->
<div class="print-only" style="padding:15px 20px;">
    <div style="text-align:center;margin-bottom:15px;">
        <h5 style="margin:0;font-size:10pt;">Republic of the Philippines — Barangay Centro</h5>
        <h4 style="margin:5px 0;font-size:13pt;font-weight:bold;">RESIDENT BIO-PROFILE</h4>
    </div>
    <div style="border:2px solid #000;padding:20px;border-radius:5px;">
        <div style="background:#000;color:#fff;padding:5px 10px;font-weight:bold;font-size:10pt;margin-bottom:12px;text-align:center;">PERSONAL INFORMATION</div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:8px;"><tr><td style="padding:6px 0;"><span style="font-size:9pt;font-weight:600;width:100px;display:inline-block;">Full Name:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:inline-block;min-width:400px;padding:2px 8px;"><?= htmlspecialchars($resident['first_name'].' '.($resident['middle_name']??'').' '.$resident['last_name'].' '.($resident['ext_name']??'')) ?></span></td></tr></table>
        <table style="width:100%;border-collapse:collapse;margin-bottom:8px;"><tr>
            <td style="width:33%;padding:6px 8px 6px 0;"><span style="font-size:9pt;font-weight:600;display:block;">Date of Birth:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['date_of_birth']??'N/A') ?></span></td>
            <td style="width:17%;padding:6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Age:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= $age ?></span></td>
            <td style="width:50%;padding:6px 0 6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Birthplace:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['birthplace']??'N/A') ?></span></td>
        </tr></table>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;"><tr>
            <td style="width:33%;padding:6px 8px 6px 0;"><span style="font-size:9pt;font-weight:600;display:block;">Gender:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['gender']??'N/A') ?></span></td>
            <td style="width:33%;padding:6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Civil Status:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['civil_status']??'N/A') ?></span></td>
            <td style="width:34%;padding:6px 0 6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Occupation:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['occupation']??'N/A') ?></span></td>
        </tr></table>
        <div style="background:#000;color:#fff;padding:5px 10px;font-weight:bold;font-size:10pt;margin-bottom:12px;text-align:center;">CONTACT INFORMATION</div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;"><tr>
            <td style="width:40%;padding:6px 8px 6px 0;"><span style="font-size:9pt;font-weight:600;display:block;">Contact Number:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['contact_number']??'N/A') ?></span></td>
            <td style="width:60%;padding:6px 0 6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Email Address:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['email']??'N/A') ?></span></td>
        </tr></table>
        <div style="background:#000;color:#fff;padding:5px 10px;font-weight:bold;font-size:10pt;margin-bottom:12px;text-align:center;">ADDRESS INFORMATION</div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:8px;"><tr>
            <td style="width:50%;padding:6px 8px 6px 0;"><span style="font-size:9pt;font-weight:600;display:block;">House/Bldg No.:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['permanent_address']??'N/A') ?></span></td>
            <td style="width:50%;padding:6px 0 6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Street:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['street']??'N/A') ?></span></td>
        </tr></table>
        <table style="width:100%;border-collapse:collapse;margin-bottom:8px;"><tr>
            <td style="width:33%;padding:6px 8px 6px 0;"><span style="font-size:9pt;font-weight:600;display:block;">Barangay:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['barangay']??'N/A') ?></span></td>
            <td style="width:34%;padding:6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Municipality/City:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['town']??'N/A') ?></span></td>
            <td style="width:33%;padding:6px 0 6px 8px;"><span style="font-size:9pt;font-weight:600;display:block;">Province:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['province']??'N/A') ?></span></td>
        </tr></table>
        <table style="width:100%;border-collapse:collapse;"><tr><td style="padding:6px 0;"><span style="font-size:9pt;font-weight:600;display:block;">Complete Residential Address:</span><span style="font-size:9pt;border-bottom:1px solid #000;display:block;padding:2px 8px;"><?= htmlspecialchars($resident['address']??'N/A') ?></span></td></tr></table>
    </div>
    <div style="margin-top:30px;"><table style="width:100%;border-collapse:collapse;"><tr>
        <td style="width:50%;padding:10px;text-align:center;vertical-align:top;"><div style="font-size:9pt;margin-bottom:40px;">Prepared by:</div><div style="border-top:2px solid #000;display:inline-block;min-width:220px;padding-top:3px;"><div style="font-size:10pt;font-weight:bold;">Christian James B. Ortouste</div><div style="font-size:8pt;color:#666;">Staff Name &amp; Signature / Staff</div></div></td>
        <td style="width:50%;padding:10px;text-align:center;vertical-align:top;"><div style="font-size:9pt;margin-bottom:40px;">Verified by:</div><div style="border-top:2px solid #000;display:inline-block;min-width:220px;padding-top:3px;"><div style="font-size:10pt;font-weight:bold;">Elijah Pen Ompad</div><div style="font-size:8pt;color:#666;">Barangay Official / Brgy. Captain</div></div></td>
    </tr></table></div>
    <div style="text-align:center;margin-top:25px;padding-top:10px;border-top:1px solid #ccc;font-size:7pt;color:#666;line-height:1.6;">
        Document Generated: <?= date('F d, Y h:i A') ?> | BarangayLink Management System | Resident ID: #<?= str_pad($resident_id,4,'0',STR_PAD_LEFT) ?><br>
        <em>This is a computer-generated document.</em>
    </div>
</div>

<!-- ════════ ALERTS ════════ -->
<?php if ($success): ?>
<div class="rv-alert rv-alert-success no-print">
    <i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($success) ?></span>
    <button class="rv-alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="rv-alert rv-alert-error no-print">
    <i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($error) ?></span>
    <button class="rv-alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ════════ MAIN PAGE ════════ -->
<div class="rv-page no-print">

    <?php if ($is_tanod): ?>
    <div class="rv-notice">
        <i class="fas fa-shield-alt"></i>
        <div><strong>View Only Mode:</strong> As a Tanod you can view resident information but cannot make edits.</div>
    </div>
    <?php endif; ?>

    <!-- ══ PROFILE BANNER ══ -->
    <div class="rv-banner">
        <!-- Avatar -->
        <div class="rv-avatar">
            <?php if ($has_photo): ?>
                <img src="../../uploads/profiles/<?= htmlspecialchars($resident['profile_photo']) ?>" alt="Profile">
            <?php else: ?>
                <?= strtoupper(substr($resident['first_name'],0,1).substr($resident['last_name'],0,1)) ?>
            <?php endif; ?>
        </div>

        <!-- Name + badges -->
        <div class="rv-banner-info">
            <div class="rv-banner-name">
                <?= htmlspecialchars(trim(
                    $resident['first_name']
                    .(!empty($resident['middle_name']) ? ' '.$resident['middle_name'] : '')
                    .' '.$resident['last_name']
                    .(!empty($resident['ext_name']) ? ' '.$resident['ext_name'] : '')
                )) ?>
            </div>
            <div class="rv-banner-id">
                Resident ID &nbsp;·&nbsp; #<?= str_pad($resident_id, 4, '0', STR_PAD_LEFT) ?>
            </div>
            <div class="rv-banner-badges">
                <?php if ($resident['is_verified']): ?>
                    <span class="rv-badge rv-badge-success"><i class="fas fa-check-circle"></i> Verified</span>
                <?php else: ?>
                    <span class="rv-badge rv-badge-warning"><i class="fas fa-clock"></i> Pending Verification</span>
                <?php endif; ?>
                <?php if (!empty($resident['gender'])): ?>
                    <span class="rv-badge rv-badge-info"><i class="fas fa-venus-mars"></i> <?= htmlspecialchars($resident['gender']) ?></span>
                <?php endif; ?>
                <?php if (!empty($resident['civil_status'])): ?>
                    <span class="rv-badge rv-badge-muted"><?= htmlspecialchars($resident['civil_status']) ?></span>
                <?php endif; ?>
                <span class="rv-badge rv-badge-muted"><i class="fas fa-birthday-cake"></i> <?= $age ?> yrs old</span>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="rv-banner-actions">
            <?php if ($can_edit): ?>
            <a href="edit.php?id=<?= $resident_id ?>" class="rv-wbtn rv-wbtn-solid">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
            <?php endif; ?>
            <button onclick="window.print()" class="rv-wbtn">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="manage.php" class="rv-wbtn">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- ══ REQUEST STATS ══ -->
    <div class="rv-stats">
        <div class="rv-stat">
            <div class="rv-stat-icon rv-stat-icon-blue"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rv-stat-num"><?= intval($req_stats['total_requests'] ?? 0) ?></div>
                <div class="rv-stat-label">Total Requests</div>
            </div>
            <div class="rv-stat-bar rv-stat-bar-blue"></div>
        </div>
        <div class="rv-stat">
            <div class="rv-stat-icon rv-stat-icon-amber"><i class="fas fa-hourglass-half"></i></div>
            <div>
                <div class="rv-stat-num"><?= intval($req_stats['pending'] ?? 0) ?></div>
                <div class="rv-stat-label">Pending</div>
            </div>
            <div class="rv-stat-bar rv-stat-bar-amber"></div>
        </div>
        <div class="rv-stat">
            <div class="rv-stat-icon rv-stat-icon-indigo"><i class="fas fa-check-double"></i></div>
            <div>
                <div class="rv-stat-num"><?= intval($req_stats['approved'] ?? 0) ?></div>
                <div class="rv-stat-label">Approved</div>
            </div>
            <div class="rv-stat-bar rv-stat-bar-indigo"></div>
        </div>
        <div class="rv-stat">
            <div class="rv-stat-icon rv-stat-icon-teal"><i class="fas fa-box-open"></i></div>
            <div>
                <div class="rv-stat-num"><?= intval($req_stats['released'] ?? 0) ?></div>
                <div class="rv-stat-label">Released</div>
            </div>
            <div class="rv-stat-bar rv-stat-bar-teal"></div>
        </div>
        <div class="rv-stat">
            <div class="rv-stat-icon rv-stat-icon-rose"><i class="fas fa-times-circle"></i></div>
            <div>
                <div class="rv-stat-num"><?= intval($req_stats['rejected'] ?? 0) ?></div>
                <div class="rv-stat-label">Rejected</div>
            </div>
            <div class="rv-stat-bar rv-stat-bar-rose"></div>
        </div>
    </div>

    <!-- ══ TWO-COLUMN LAYOUT ══ -->
    <div class="rv-layout">

        <!-- ─── SIDEBAR ─── -->
        <div class="rv-sidebar">

            <!-- Identity Verification -->
            <div class="rv-panel">
                <div class="rv-panel-header">
                    <div class="rv-panel-title">
                        <div class="rv-panel-icon rv-panel-icon-indigo"><i class="fas fa-id-card"></i></div>
                        <h2>Identity Verification</h2>
                    </div>
                </div>
                <?php if ($has_id): ?>
                    <?php if ($file_ext === 'pdf'): ?>
                    <div class="rv-id-box" style="cursor:default;">
                        <i class="fas fa-file-pdf" style="font-size:2.2rem;color:#ef4444;display:block;margin-bottom:.5rem;"></i>
                        <p style="font-size:12px;color:#64748b;margin-bottom:.75rem;">PDF Document</p>
                        <a href="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" target="_blank" class="rv-btn rv-btn-primary">
                            <i class="fas fa-eye"></i> View PDF
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="rv-id-box" onclick="openIdModal()">
                        <img src="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" alt="Valid ID">
                        <div class="rv-id-hint"><i class="fas fa-search-plus"></i> Click to enlarge</div>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                <div class="rv-empty">
                    <i class="fas fa-id-card"></i>
                    <p>No ID photo uploaded.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Info -->
            <div class="rv-panel">
                <div class="rv-panel-header">
                    <div class="rv-panel-title">
                        <div class="rv-panel-icon rv-panel-icon-teal"><i class="fas fa-info-circle"></i></div>
                        <h2>Quick Info</h2>
                    </div>
                </div>
                <div class="rv-qrow">
                    <span class="rv-qlabel"><i class="fas fa-venus-mars"></i> Gender</span>
                    <span class="rv-qval"><?= htmlspecialchars($resident['gender'] ?? '—') ?></span>
                </div>
                <div class="rv-qrow">
                    <span class="rv-qlabel"><i class="fas fa-heart"></i> Civil</span>
                    <span class="rv-qval"><?= htmlspecialchars($resident['civil_status'] ?? '—') ?></span>
                </div>
                <div class="rv-qrow">
                    <span class="rv-qlabel"><i class="fas fa-briefcase"></i> Work</span>
                    <span class="rv-qval"><?= htmlspecialchars($resident['occupation'] ?? '—') ?></span>
                </div>
                <div class="rv-qrow">
                    <span class="rv-qlabel"><i class="fas fa-phone"></i> Contact</span>
                    <span class="rv-qval"><?= htmlspecialchars($resident['contact_number'] ?? '—') ?></span>
                </div>
                <div class="rv-qrow">
                    <span class="rv-qlabel"><i class="fas fa-envelope"></i> Email</span>
                    <span class="rv-qval" title="<?= htmlspecialchars($resident['email'] ?? '') ?>"><?= htmlspecialchars($resident['email'] ?? '—') ?></span>
                </div>
            </div>

        </div><!-- /sidebar -->

        <!-- ─── MAIN PANELS ─── -->
        <div style="display:flex;flex-direction:column;gap:18px;">

            <!-- Personal Information -->
            <div class="rv-panel">
                <div class="rv-panel-header">
                    <div class="rv-panel-title">
                        <div class="rv-panel-icon rv-panel-icon-blue"><i class="fas fa-user-circle"></i></div>
                        <h2>Personal Information</h2>
                    </div>
                </div>
                <div class="rv-grid">
                    <div class="rv-item"><div class="rv-ilabel">Last Name</div><div class="rv-ival"><?= htmlspecialchars($resident['last_name']) ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">First Name</div><div class="rv-ival"><?= htmlspecialchars($resident['first_name']) ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">Middle Name</div><div class="rv-ival"><?= htmlspecialchars($resident['middle_name'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">Extension</div><div class="rv-ival"><?= htmlspecialchars($resident['ext_name'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel"><i class="fas fa-calendar"></i> Date of Birth</div><div class="rv-ival"><?= !empty($resident['date_of_birth']) ? date('F d, Y', strtotime($resident['date_of_birth'])) : '—' ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel"><i class="fas fa-birthday-cake"></i> Age</div><div class="rv-ival"><?= $age ?> years old</div></div>
                    <div class="rv-item"><div class="rv-ilabel"><i class="fas fa-map-marker-alt"></i> Birthplace</div><div class="rv-ival"><?= htmlspecialchars($resident['birthplace'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel"><i class="fas fa-venus-mars"></i> Gender</div><div class="rv-ival"><?= htmlspecialchars($resident['gender'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel"><i class="fas fa-heart"></i> Civil Status</div><div class="rv-ival"><?= htmlspecialchars($resident['civil_status'] ?? '—') ?></div></div>
                    <div class="rv-item rv-item-last"><div class="rv-ilabel"><i class="fas fa-briefcase"></i> Occupation</div><div class="rv-ival"><?= htmlspecialchars($resident['occupation'] ?? '—') ?></div></div>
                </div>
            </div>

            <!-- Contact Information -->
            <div class="rv-panel">
                <div class="rv-panel-header">
                    <div class="rv-panel-title">
                        <div class="rv-panel-icon rv-panel-icon-teal"><i class="fas fa-address-book"></i></div>
                        <h2>Contact Information</h2>
                    </div>
                </div>
                <div class="rv-grid" style="grid-template-columns:1fr 1fr;">
                    <div class="rv-item rv-item-last"><div class="rv-ilabel"><i class="fas fa-phone"></i> Contact Number</div><div class="rv-ival"><?= htmlspecialchars($resident['contact_number'] ?? 'Not Provided') ?></div></div>
                    <div class="rv-item rv-item-last"><div class="rv-ilabel"><i class="fas fa-envelope"></i> Email Address</div><div class="rv-ival"><?= htmlspecialchars($resident['email'] ?? 'Not Provided') ?></div></div>
                </div>
            </div>

            <!-- Address Information -->
            <div class="rv-panel">
                <div class="rv-panel-header">
                    <div class="rv-panel-title">
                        <div class="rv-panel-icon rv-panel-icon-rose"><i class="fas fa-map-marked-alt"></i></div>
                        <h2>Address Information</h2>
                    </div>
                </div>
                <div class="rv-grid">
                    <div class="rv-item rv-item-full"><div class="rv-ilabel"><i class="fas fa-home"></i> Permanent Address / House No.</div><div class="rv-ival"><?= htmlspecialchars($resident['permanent_address'] ?? 'Not Provided') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">Street</div><div class="rv-ival"><?= htmlspecialchars($resident['street'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">Barangay</div><div class="rv-ival"><?= htmlspecialchars($resident['barangay'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">Town / City</div><div class="rv-ival"><?= htmlspecialchars($resident['town'] ?? '—') ?></div></div>
                    <div class="rv-item"><div class="rv-ilabel">Province</div><div class="rv-ival"><?= htmlspecialchars($resident['province'] ?? '—') ?></div></div>
                    <div class="rv-item rv-item-full rv-item-last"><div class="rv-ilabel"><i class="fas fa-map"></i> Complete Residential Address</div><div class="rv-ival"><?= htmlspecialchars($resident['address'] ?? 'Not Provided') ?></div></div>
                </div>
            </div>

            <!-- Document Request History -->
            <div class="rv-panel">
                <div class="rv-panel-header">
                    <div class="rv-panel-title">
                        <div class="rv-panel-icon rv-panel-icon-amber"><i class="fas fa-file-invoice"></i></div>
                        <h2>Document Request History</h2>
                    </div>
                    <span class="rv-badge rv-badge-muted">Last 10</span>
                </div>
                <?php if (!empty($document_requests)):
                    $bmap = ['Pending'=>'rv-badge-warning','Approved'=>'rv-badge-info','Released'=>'rv-badge-success','Rejected'=>'rv-badge-danger'];
                ?>
                <div class="rv-table-wrap">
                    <table class="rv-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>Date</th><th>Document Type</th><th>Purpose</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($document_requests as $req):
                            $bc = $bmap[$req['status']] ?? 'rv-badge-muted';
                            $p  = $req['purpose'] ?? '';
                        ?>
                        <tr>
                            <td><span class="rv-tid">#<?= str_pad($req['request_id'],5,'0',STR_PAD_LEFT) ?></span></td>
                            <td><span class="rv-tsm"><?= date('M d, Y', strtotime($req['request_date'])) ?></span></td>
                            <td><?= htmlspecialchars($req['request_type_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(strlen($p)>40 ? substr($p,0,40).'…' : $p) ?></td>
                            <td><span class="rv-badge <?= $bc ?>"><?= htmlspecialchars($req['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="rv-empty">
                    <i class="fas fa-inbox"></i>
                    <p>No document requests found for this resident.</p>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /main panels -->
    </div><!-- /rv-layout -->
</div><!-- /rv-page -->

<!-- ════════ ID PHOTO MODAL ════════ -->
<?php if ($has_id && $file_ext !== 'pdf'): ?>
<div id="idPhotoModal" style="display:none;position:fixed;inset:0;background:rgba(13,27,54,.6);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;" onclick="if(event.target===this)closeIdModal()">
    <div style="position:relative;text-align:center;">
        <button onclick="closeIdModal()" style="position:absolute;top:-38px;right:0;background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;">×</button>
        <img src="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" alt="Valid ID" style="max-width:88vw;max-height:78vh;border-radius:14px;box-shadow:0 24px 80px rgba(0,0,0,.45);display:block;">
        <div style="color:rgba(255,255,255,.7);font-size:12px;margin-top:12px;">
            Valid ID — <?= htmlspecialchars($resident['first_name'].' '.$resident['last_name']) ?>
        </div>
        <div style="margin-top:12px;">
            <a href="../../uploads/ids/<?= htmlspecialchars($resident['id_photo']) ?>" download class="rv-btn rv-btn-primary">
                <i class="fas fa-download"></i> Download
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openIdModal() {
    var m = document.getElementById('idPhotoModal');
    if (m) { m.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}
function closeIdModal() {
    var m = document.getElementById('idPhotoModal');
    if (m) { m.style.display = 'none'; document.body.style.overflow = ''; }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeIdModal();
});

// Auto-dismiss alerts
setTimeout(function() {
    document.querySelectorAll('.rv-alert').forEach(function(a) {
        a.style.transition = 'opacity .4s, transform .4s';
        a.style.opacity = '0'; a.style.transform = 'translateY(-8px)';
        setTimeout(function() { a.remove(); }, 400);
    });
}, 5000);
</script>

<?php $conn->close(); include '../../includes/footer.php'; ?>