<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireAnyRole(['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod', 'Resident']);

$current_user_id = getCurrentUserId();
$current_role    = getCurrentUserRole();

$staff_roles = ['Admin', 'Super Admin', 'Super Administrator', 'Barangay Captain', 'Barangay Tanod', 'Staff', 'Secretary', 'Treasurer', 'Tanod'];
$is_resident = !in_array($current_role, $staff_roles);
$is_tanod    = ($current_role === 'Tanod' || $current_role === 'Barangay Tanod');
$can_modify  = !$is_resident && !$is_tanod;

error_log("=== INCIDENT DETAILS DEBUG ===");
error_log("GET params: " . print_r($_GET, true));
error_log("User ID: $current_user_id | Role: $current_role");

$incident_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$incident_id || $incident_id <= 0) {
    $_SESSION['error_message'] = 'Invalid incident ID provided';
    header('Location: view-incidents.php'); exit;
}

$resident_id = null;
if ($is_resident) {
    $stmt = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $stmt->bind_param("i", $current_user_id); $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $resident_id = $row['resident_id'];
    $stmt->close();
}

$table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_images'");
$has_images_table = ($table_check && $table_check->num_rows > 0);

$table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_responses'");
$has_responses_table = ($table_check && $table_check->num_rows > 0);

$sql = "SELECT i.*,
        CONCAT(r.first_name,' ',r.last_name) as resident_name,
        r.contact_number as resident_contact,
        r.email as resident_email,
        r.address as resident_address,
        CONCAT(resp_r.first_name,' ',resp_r.last_name) as responder_name,
        resp_u.role as responder_role
        FROM tbl_incidents i
        LEFT JOIN tbl_residents r ON i.resident_id = r.resident_id
        LEFT JOIN tbl_users resp_u ON i.responder_id = resp_u.user_id
        LEFT JOIN tbl_residents resp_r ON resp_u.resident_id = resp_r.resident_id
        WHERE i.incident_id = ?";

if ($is_resident && $resident_id) {
    $sql .= " AND i.resident_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $incident_id, $resident_id);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $incident_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $check_stmt = $conn->prepare("SELECT incident_id, resident_id FROM tbl_incidents WHERE incident_id = ?");
    $check_stmt->bind_param("i", $incident_id); $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $_SESSION['error_message'] = $check_result->num_rows === 0
        ? 'Incident not found. It may have been deleted.'
        : 'You do not have permission to view this incident.';
    $check_stmt->close(); $stmt->close();
    header('Location: view-incidents.php'); exit;
}

$incident = $result->fetch_assoc();
$stmt->close();

$images = [];
if ($has_images_table) {
    $stmt = $conn->prepare("SELECT * FROM tbl_incident_images WHERE incident_id = ? ORDER BY uploaded_at DESC");
    $stmt->bind_param("i", $incident_id); $stmt->execute();
    $images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$updates = [];
if ($has_responses_table) {
    $stmt = $conn->prepare("SELECT ir.*, CONCAT(resp_r.first_name,' ',resp_r.last_name) as responder_name
                            FROM tbl_incident_responses ir
                            LEFT JOIN tbl_users resp_u ON ir.responder_id = resp_u.user_id
                            LEFT JOIN tbl_residents resp_r ON resp_u.resident_id = resp_r.resident_id
                            WHERE ir.incident_id = ? ORDER BY ir.response_date DESC");
    $stmt->bind_param("i", $incident_id); $stmt->execute();
    $updates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$responders = [];
if ($can_modify) {
    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.role FROM tbl_users u
                            WHERE u.role IN ('Admin','Super Admin','Super Administrator','Barangay Captain','Staff','Secretary','Treasurer','Barangay Tanod','Tanod')
                            AND u.status = 'active'
                            ORDER BY CASE WHEN u.role IN ('Super Admin','Super Administrator','Admin') THEN 1
                                WHEN u.role='Barangay Captain' THEN 2
                                WHEN u.role IN ('Barangay Tanod','Tanod') THEN 3 ELSE 4 END, u.username ASC");
    $stmt->execute();
    $responders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$upload_url = defined('UPLOAD_URL') ? UPLOAD_URL : (defined('BASE_URL') ? BASE_URL . 'uploads/' : '/barangaylink/uploads/');
$upload_url = rtrim($upload_url, '/') . '/';

$page_title = 'Incident Details - ' . htmlspecialchars($incident['reference_no']);
include '../../includes/header.php';

// ── helpers ──
function incidentStatusBadge($status) {
    $s = trim($status);
    $map = ['Pending'=>['amber','clock'],'Under Investigation'=>['sky','search'],'In Progress'=>['sky','spinner'],'Resolved'=>['success','check-circle'],'Closed'=>['muted','times-circle']];
    [$c,$i] = $map[$s] ?? ['muted','circle'];
    return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($s)."</span>";
}
function incidentSeverityBadge($severity) {
    $s = trim($severity);
    $map = ['Low'=>['success','circle'],'Medium'=>['amber','exclamation-circle'],'High'=>['rose','exclamation-triangle'],'Critical'=>['rose','fire']];
    [$c,$i] = $map[$s] ?? ['muted','circle'];
    return "<span class='db-badge db-badge--$c'><i class='fas fa-$i'></i> ".htmlspecialchars($s)."</span>";
}
function incidentTypeIcon($type) {
    $map = ['Crime'=>'fa-user-secret','Fire'=>'fa-fire','Accident'=>'fa-car-crash','Health Emergency'=>'fa-ambulance','Violation'=>'fa-gavel','Natural Disaster'=>'fa-cloud-showers-heavy'];
    return $map[$type] ?? 'fa-exclamation-triangle';
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-info:#3b82f6;--db-info-light:#dbeafe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* Hero */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a4a2e 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(245,158,11,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__badges{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}

/* Alerts */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--info{background:var(--db-info-light);color:#1e40af;border-color:var(--db-info);}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-panel__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__body{padding:22px;}

/* Info items */
.db-info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:12px;}
.db-info-item{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px 14px;transition:background .15s,transform .15s;}
.db-info-item:hover{background:#f0f4ff;transform:translateX(3px);}
.db-info-item--full{grid-column:1/-1;}
.db-info-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.db-info-value{font-size:13.5px;font-weight:600;color:var(--db-text);display:flex;align-items:center;gap:6px;line-height:1.5;}
.db-info-value--block{display:block;line-height:1.7;}

/* Badges */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--navy{background:#e8edf7;color:var(--db-navy);}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber),#d97706);color:#fff;}
.db-btn--amber:hover{background:linear-gradient(135deg,#d97706,#b45309);transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,var(--db-success),#059669);color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--block{width:100%;justify-content:center;margin-bottom:8px;}
.db-btn--block:last-child{margin-bottom:0;}

/* Timeline */
.db-timeline{padding-left:24px;}
.db-timeline-item{position:relative;border-left:3px solid var(--db-border);padding:0 0 20px 20px;transition:border-color .2s;}
.db-timeline-item:last-child{border-left:3px solid transparent;padding-bottom:0;}
.db-timeline-item::before{content:'';position:absolute;left:-8px;top:2px;width:14px;height:14px;border-radius:50%;background:var(--db-sky);border:3px solid var(--db-surf);box-shadow:0 0 0 2px var(--db-sky);}
.db-timeline-card{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:14px 16px;transition:background .15s,transform .15s;}
.db-timeline-card:hover{background:#f0f4ff;transform:translateX(4px);}
.db-timeline-meta{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:8px;}
.db-timeline-author{font-size:13px;font-weight:700;color:var(--db-text);display:flex;align-items:center;gap:6px;}
.db-timeline-date{font-family:'DM Mono',monospace;font-size:10px;color:var(--db-muted);}
.db-timeline-msg{font-size:12.5px;color:var(--db-muted);line-height:1.7;}

/* Image gallery */
.db-img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-top:14px;}
.db-img-card{border-radius:var(--db-radius-sm);overflow:hidden;border:1px solid var(--db-border);background:var(--db-surf);box-shadow:var(--db-shadow);transition:transform .2s,box-shadow .2s;}
.db-img-card:hover{transform:translateY(-4px);box-shadow:var(--db-shadow-lg);}
.db-img-card img{height:180px;width:100%;object-fit:cover;transition:transform .3s;}
.db-img-card:hover img{transform:scale(1.04);}
.db-img-card__foot{padding:10px 12px;display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--db-border);}

/* Empty state */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px;text-align:center;gap:10px;}
.db-empty i{font-size:40px;color:var(--db-border);}
.db-empty p{font-size:13px;color:var(--db-muted);margin:0;}

/* Modal */
.db-modal .modal-content{border:none;border-radius:var(--db-radius-lg);box-shadow:var(--db-shadow-lg);}
.db-modal .modal-header{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));border-bottom:none;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal .modal-title{color:#fff;font-weight:700;font-size:15px;display:flex;align-items:center;gap:8px;}
.db-modal .btn-close{filter:invert(1) brightness(2);}
.db-modal .modal-body{padding:22px;}
.db-modal .modal-footer{background:var(--db-surf2);border-top:1px solid var(--db-border);padding:14px 22px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);}
.db-modal .form-label{font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:6px;}
.db-modal .form-control,.db-modal .form-select{border:2px solid var(--db-border);border-radius:var(--db-radius-sm);font-size:13px;padding:9px 12px;font-family:'Sora',sans-serif;transition:border-color .18s,box-shadow .18s;}
.db-modal .form-control:focus,.db-modal .form-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.12);outline:none;}

/* Sidebar contact links */
.db-contact-link{color:var(--db-sky);font-weight:600;text-decoration:none;font-size:13px;}
.db-contact-link:hover{color:var(--db-navy);text-decoration:underline;}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-info-grid{grid-template-columns:1fr;}}

/* ══════════════════════════════════════
   DARK MODE OVERRIDES
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }

/* Panels */
body.dark-mode .db-panel {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-panel__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-panel__title h2 {
    color: #f1f5f9 !important;
}

/* Info items */
body.dark-mode .db-info-item {
    background: #243044 !important;
    border-color: #334155 !important;
}
body.dark-mode .db-info-item:hover {
    background: #1e3a5f !important;
}
body.dark-mode .db-info-label {
    color: #64748b !important;
}
body.dark-mode .db-info-value {
    color: #e2e8f0 !important;
}
body.dark-mode .db-info-value--block {
    color: #94a3b8 !important;
}

/* Panel icons */
body.dark-mode .db-panel__icon--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-panel__icon--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-panel__icon--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-panel__icon--navy {
    background: #1e3a5f !important;
    color: #93c5fd !important;
}
body.dark-mode .db-panel__icon--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}

/* Badges */
body.dark-mode .db-badge--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-badge--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-badge--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-badge--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-badge--muted {
    background: #1e293b !important;
    color: #94a3b8 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-badge--navy {
    background: #1e3a5f !important;
    color: #93c5fd !important;
}
body.dark-mode .db-badge--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}

/* Alerts */
body.dark-mode .db-alert--info {
    background: #0c2a40 !important;
    color: #93c5fd !important;
    border-color: #3b82f6 !important;
}
body.dark-mode .db-alert--success {
    background: #052e16 !important;
    color: #86efac !important;
    border-color: #4ade80 !important;
}
body.dark-mode .db-alert--error {
    background: #2d1c1c !important;
    color: #fca5a5 !important;
    border-color: #ef4444 !important;
}

/* Buttons */
body.dark-mode .db-btn--ghost {
    background: #1e293b !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-btn--ghost:hover {
    background: #334155 !important;
}

/* Timeline */
body.dark-mode .db-timeline-item {
    border-left-color: #334155 !important;
}
body.dark-mode .db-timeline-item::before {
    border-color: #1e293b !important;
}
body.dark-mode .db-timeline-card {
    background: #243044 !important;
    border-color: #334155 !important;
}
body.dark-mode .db-timeline-card:hover {
    background: #1e3a5f !important;
}
body.dark-mode .db-timeline-author {
    color: #f1f5f9 !important;
}
body.dark-mode .db-timeline-date {
    color: #64748b !important;
}
body.dark-mode .db-timeline-msg {
    color: #94a3b8 !important;
}

/* Image cards */
body.dark-mode .db-img-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-img-card__foot {
    border-top-color: #334155 !important;
}

/* Empty state */
body.dark-mode .db-empty i {
    color: #334155 !important;
}
body.dark-mode .db-empty p {
    color: #64748b !important;
}

/* Contact links */
body.dark-mode .db-contact-link {
    color: #38bdf8 !important;
}
body.dark-mode .db-contact-link:hover {
    color: #93c5fd !important;
}

/* Modals */
body.dark-mode .db-modal .modal-content {
    background: #1e293b !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-modal .modal-body {
    background: #1e293b !important;
}
body.dark-mode .db-modal .modal-footer {
    background: #243044 !important;
    border-top-color: #334155 !important;
}
body.dark-mode .db-modal .form-label {
    color: #94a3b8 !important;
}
body.dark-mode .db-modal .form-control,
body.dark-mode .db-modal .form-select {
    background: #334155 !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-modal .form-control:focus,
body.dark-mode .db-modal .form-select:focus {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15) !important;
}
body.dark-mode .db-modal .form-control::placeholder {
    color: #64748b !important;
}
body.dark-mode .db-modal .form-check-label {
    color: #e2e8f0 !important;
}

/* Status display box inside modal */
body.dark-mode .db-modal [style*="background:var(--db-surf2)"] {
    background: #243044 !important;
    border-color: #334155 !important;
}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-shield-alt"></i></div>
            <div>
                <div class="rm-hero__title">Incident Details</div>
                <div class="rm-hero__sub">
                    <i class="fas fa-hashtag" style="opacity:.6;margin-right:4px;"></i>
                    Reference: <strong><?php echo htmlspecialchars($incident['reference_no']); ?></strong>
                </div>
            </div>
        </div>
        <div class="rm-hero__badges">
            <?php echo incidentStatusBadge($incident['status']); ?>
            <?php echo incidentSeverityBadge($incident['severity']); ?>
            <a href="<?php echo $is_resident ? 'view-incidents.php' : 'manage-incidents.php'; ?>" class="db-btn db-btn--ghost db-btn--sm" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.2);">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($is_tanod): ?>
<div class="db-alert db-alert--info"><i class="fas fa-info-circle"></i><strong>View Only Mode:</strong>&nbsp;As a Tanod, you can view incident details but cannot make modifications.<button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<div class="row g-3">
    <!-- ── LEFT COLUMN ── -->
    <div class="col-lg-8">

        <!-- Incident Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-info-circle"></i></div>
                    <h2>Incident Information</h2>
                </div>
                <span class="db-badge db-badge--amber">
                    <i class="fas <?php echo incidentTypeIcon($incident['incident_type']); ?>"></i>
                    <?php echo htmlspecialchars($incident['incident_type']); ?>
                </span>
            </div>
            <div class="db-panel__body">
                <div class="db-info-grid">
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-calendar-alt"></i> Date Reported</div>
                        <div class="db-info-value">
                            <i class="fas fa-clock" style="color:var(--db-sky);font-size:11px;"></i>
                            <?php echo date('M d, Y · h:i A', strtotime($incident['date_reported'])); ?>
                        </div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-chart-bar"></i> Severity</div>
                        <div class="db-info-value"><?php echo incidentSeverityBadge($incident['severity']); ?></div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-circle-notch"></i> Status</div>
                        <div class="db-info-value"><?php echo incidentStatusBadge($incident['status']); ?></div>
                    </div>
                    <div class="db-info-item">
                        <div class="db-info-label"><i class="fas fa-map-marker-alt"></i> Location</div>
                        <div class="db-info-value">
                            <i class="fas fa-map-pin" style="color:var(--db-rose);font-size:11px;"></i>
                            <?php echo htmlspecialchars($incident['location']); ?>
                        </div>
                    </div>
                    <div class="db-info-item db-info-item--full">
                        <div class="db-info-label"><i class="fas fa-align-left"></i> Description</div>
                        <div class="db-info-value db-info-value--block" style="font-weight:400;color:var(--db-muted);">
                            <?php echo nl2br(htmlspecialchars($incident['description'])); ?>
                        </div>
                    </div>
                </div>

                <?php if ($has_images_table && !empty($images)): ?>
                <div style="margin-top:8px;">
                    <div class="db-info-label" style="margin-bottom:4px;">
                        <i class="fas fa-images"></i> Incident Photos
                        <span class="db-badge db-badge--navy" style="margin-left:6px;"><?php echo count($images); ?></span>
                    </div>
                    <div class="db-img-grid">
                        <?php foreach ($images as $image):
                            $raw_path = ltrim($image['image_path'], '/');
                            $raw_path = preg_replace('#^uploads/#', '', $raw_path);
                            if (strpos($raw_path, 'incidents/') !== 0) $raw_path = 'incidents/' . $raw_path;
                            $image_url = rtrim($upload_url, '/') . '/' . $raw_path;
                        ?>
                        <div class="db-img-card">
                            <img src="<?php echo htmlspecialchars($image_url); ?>"
                                 alt="Incident Photo"
                                 onerror="this.onerror=null;this.src='../../assets/images/no-image.png';">
                            <div class="db-img-card__foot">
                                <span class="db-badge db-badge--amber">
                                    <i class="fas fa-camera"></i>
                                    <?php echo ucfirst(htmlspecialchars($image['image_type'] ?? 'Evidence')); ?>
                                </span>
                                <a href="<?php echo htmlspecialchars($image_url); ?>" target="_blank" class="db-btn db-btn--sm db-btn--ghost">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Updates & Responses -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-comments"></i></div>
                    <h2>Updates &amp; Responses</h2>
                    <?php if (!empty($updates)): ?>
                    <span class="db-badge db-badge--sky"><?php echo count($updates); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($can_modify && $has_responses_table): ?>
                <button class="db-btn db-btn--sm db-btn--success" data-bs-toggle="modal" data-bs-target="#addResponseModal">
                    <i class="fas fa-reply"></i> Add Response
                </button>
                <?php endif; ?>
            </div>
            <div class="db-panel__body">
                <?php if (!$has_responses_table): ?>
                <div class="db-empty"><i class="fas fa-database"></i><p>Response tracking not available</p></div>
                <?php elseif (empty($updates)): ?>
                <div class="db-empty"><i class="fas fa-inbox"></i><p>No updates yet</p></div>
                <?php else: ?>
                <div class="db-timeline">
                    <?php foreach ($updates as $upd): ?>
                    <div class="db-timeline-item">
                        <div class="db-timeline-card">
                            <div class="db-timeline-meta">
                                <div class="db-timeline-author">
                                    <i class="fas fa-user-shield" style="color:var(--db-sky);font-size:11px;"></i>
                                    <?php echo htmlspecialchars($upd['responder_name'] ?? 'System'); ?>
                                    <?php if (!empty($upd['action_taken'])): ?>
                                    <span class="db-badge db-badge--indigo" style="margin-left:4px;"><?php echo htmlspecialchars($upd['action_taken']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="db-timeline-date">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M d, Y · h:i A', strtotime($upd['response_date'])); ?>
                                </span>
                            </div>
                            <div class="db-timeline-msg"><?php echo nl2br(htmlspecialchars($upd['response_message'])); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /col-lg-8 -->

    <!-- ── RIGHT COLUMN ── -->
    <div class="col-lg-4">

        <?php if (!$is_resident): ?>
        <!-- Reporter Info -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-user"></i></div>
                    <h2>Reporter</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <div class="db-info-item" style="margin-bottom:10px;">
                    <div class="db-info-label"><i class="fas fa-user"></i> Name</div>
                    <div class="db-info-value"><?php echo htmlspecialchars($incident['resident_name'] ?? 'Unknown'); ?></div>
                </div>
                <?php if (!empty($incident['resident_contact'])): ?>
                <div class="db-info-item" style="margin-bottom:10px;">
                    <div class="db-info-label"><i class="fas fa-phone"></i> Contact</div>
                    <div class="db-info-value">
                        <a href="tel:<?php echo htmlspecialchars($incident['resident_contact']); ?>" class="db-contact-link">
                            <i class="fas fa-phone-alt" style="font-size:11px;"></i>
                            <?php echo htmlspecialchars($incident['resident_contact']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($incident['resident_email'])): ?>
                <div class="db-info-item" style="margin-bottom:10px;">
                    <div class="db-info-label"><i class="fas fa-envelope"></i> Email</div>
                    <div class="db-info-value">
                        <a href="mailto:<?php echo htmlspecialchars($incident['resident_email']); ?>" class="db-contact-link">
                            <i class="fas fa-envelope" style="font-size:11px;"></i>
                            <?php echo htmlspecialchars($incident['resident_email']); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($incident['resident_address'])): ?>
                <div class="db-info-item">
                    <div class="db-info-label"><i class="fas fa-home"></i> Address</div>
                    <div class="db-info-value db-info-value--block" style="font-weight:400;"><?php echo htmlspecialchars($incident['resident_address']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Response Team -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-users"></i></div>
                    <h2>Response Team</h2>
                </div>
            </div>
            <div class="db-panel__body">
                <?php if (!empty($incident['responder_name'])): ?>
                <div class="db-info-item" style="margin-bottom:14px;">
                    <div class="db-info-label"><i class="fas fa-user-shield"></i> Assigned Responder</div>
                    <div class="db-info-value" style="flex-direction:column;align-items:flex-start;gap:4px;">
                        <span>
                            <i class="fas fa-user-check" style="color:var(--db-sky);font-size:11px;margin-right:5px;"></i>
                            <strong><?php echo htmlspecialchars($incident['responder_name']); ?></strong>
                        </span>
                        <?php if (!empty($incident['responder_role'])): ?>
                        <span class="db-badge db-badge--navy" style="margin-top:2px;">
                            <i class="fas fa-id-badge"></i><?php echo htmlspecialchars($incident['responder_role']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="db-empty" style="padding:24px;">
                    <i class="fas fa-user-slash"></i>
                    <p>No responder assigned yet</p>
                </div>
                <?php endif; ?>

                <?php if ($can_modify): ?>
                <div style="margin-top:4px;">
                    <?php if (!empty($responders)): ?>
                    <button class="db-btn db-btn--primary db-btn--block" data-bs-toggle="modal" data-bs-target="#assignResponderModal">
                        <i class="fas fa-user-plus"></i>
                        <?php echo !empty($incident['responder_name']) ? 'Reassign Responder' : 'Assign Responder'; ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($incident['status'] !== 'Closed'): ?>
                    <button class="db-btn db-btn--amber db-btn--block" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                        <i class="fas fa-edit"></i> Update Status
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /col-lg-4 -->
</div><!-- /row -->
</div><!-- /padding wrapper -->

<!-- ══════════ MODALS ══════════ -->
<?php if ($can_modify && $has_responses_table): ?>
<div class="modal fade db-modal" id="addResponseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-reply"></i> Add Response</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="process-incident-response.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="incident_id" value="<?php echo $incident_id; ?>">
                    <input type="hidden" name="action" value="add_response">
                    <div class="mb-3">
                        <label class="form-label">Response Message <span style="color:var(--db-rose)">*</span></label>
                        <textarea class="form-control" name="response_message" rows="5" required placeholder="Enter your response or update…"></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Action Taken</label>
                        <select class="form-select" name="action_taken">
                            <option value="">Select action type (optional)</option>
                            <option>Investigated</option><option>Responded</option><option>Resolved</option>
                            <option>Forwarded</option><option>Updated</option><option>On-site</option><option>Coordinated</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-paper-plane"></i> Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_modify && $incident['status'] !== 'Closed'): ?>
<div class="modal fade db-modal" id="updateStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Update Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="process-incident-response.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="incident_id" value="<?php echo $incident_id; ?>">
                    <input type="hidden" name="action" value="update_status">
                    <div class="mb-3">
                        <label class="form-label">Current Status</label>
                        <div style="padding:10px 14px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);">
                            <?php echo incidentStatusBadge($incident['status']); ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Change To <span style="color:var(--db-rose)">*</span></label>
                        <select class="form-select" name="new_status" id="new_status" required>
                            <option value="">Select new status…</option>
                            <?php foreach (['Pending','Under Investigation','In Progress','Resolved','Closed'] as $s):
                                if ($s !== $incident['status']): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Severity Level</label>
                        <select class="form-select" name="severity">
                            <?php foreach (['Low','Medium','High','Critical'] as $sev): ?>
                            <option value="<?php echo $sev; ?>" <?php echo ($incident['severity']==$sev)?'selected':''; ?>><?php echo $sev; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="status_notes" rows="3" placeholder="Add notes about this status change…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--amber"><i class="fas fa-save"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_modify && !empty($responders)): ?>
<div class="modal fade db-modal" id="assignResponderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Responder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="process-incident-response.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="incident_id" value="<?php echo $incident_id; ?>">
                    <input type="hidden" name="action" value="assign_responder">
                    <?php if (!empty($incident['responder_name'])): ?>
                    <div class="db-alert db-alert--info" style="margin-bottom:16px;">
                        <i class="fas fa-info-circle"></i>
                        Current: <strong><?php echo htmlspecialchars($incident['responder_name']); ?></strong> — selecting a new responder will replace this assignment.
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Select Responder <span style="color:var(--db-rose)">*</span></label>
                        <select class="form-select" name="responder_id" required>
                            <option value="">Choose a staff member…</option>
                            <?php foreach ($responders as $r): ?>
                            <option value="<?php echo $r['user_id']; ?>" <?php echo (isset($incident['responder_id']) && $incident['responder_id']==$r['user_id'])?'selected':''; ?>>
                                <?php echo htmlspecialchars($r['username']).' – '.htmlspecialchars($r['role']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assignment Notes (Optional)</label>
                        <textarea class="form-control" name="assignment_notes" rows="3" placeholder="Instructions for the assigned responder…"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="notify_responder" name="notify_responder" value="1" checked>
                        <label class="form-check-label" for="notify_responder" style="font-size:13px;">
                            <i class="fas fa-bell" style="color:var(--db-amber);margin-right:4px;"></i>Notify responder of assignment
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="db-btn db-btn--ghost" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-user-check"></i> Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alerts (except info)
    setTimeout(() => {
        document.querySelectorAll('.db-alert:not(.db-alert--info)').forEach(a => {
            a.style.transition = 'opacity .4s'; a.style.opacity = '0';
            setTimeout(() => a.remove(), 400);
        });
    }, 5000);

    // Confirm close status
    const ns = document.getElementById('new_status');
    if (ns) ns.addEventListener('change', function () {
        if (this.value === 'Closed' && !confirm('Close this incident? Further modifications may be restricted.')) this.value = '';
    });

    // Reset modals on close
    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('hidden.bs.modal', function () {
            const f = this.querySelector('form');
            if (f) { f.reset(); f.classList.remove('was-validated'); }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>