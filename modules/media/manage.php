<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Media';
$success_message = '';
$error_message = '';

// Create uploads directory if it doesn't exist
$upload_dir = '../../uploads/media/photos/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_photo') {
            $caption = trim($_POST['caption']);
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $filename = $_FILES['photo']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    if ($_FILES['photo']['size'] <= 5 * 1024 * 1024) {
                        $new_filename = 'photo_' . time() . '_' . uniqid() . '.' . $ext;
                        $filepath = $upload_dir . $new_filename;
                        if (move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
                            $db_path = 'uploads/media/photos/' . $new_filename;
                            $stmt = $conn->prepare("INSERT INTO tbl_barangay_media (media_type, file_path, caption) VALUES ('photo', ?, ?)");
                            $stmt->bind_param("ss", $db_path, $caption);
                            if ($stmt->execute()) $success_message = "Photo uploaded successfully!";
                            else { $error_message = "Error saving photo to database."; @unlink($filepath); }
                            $stmt->close();
                        } else { $error_message = "Error uploading file."; }
                    } else { $error_message = "File size must not exceed 5MB."; }
                } else { $error_message = "Invalid file type. Only JPG, PNG, GIF, and WEBP allowed."; }
            } else { $error_message = "Please select a photo to upload."; }

        } elseif ($_POST['action'] === 'add_video') {
            $video_url = trim($_POST['video_url']);
            $caption = trim($_POST['caption']);
            if (strpos($video_url, 'youtube.com/watch') !== false) {
                parse_str(parse_url($video_url, PHP_URL_QUERY), $params);
                if (isset($params['v'])) $video_url = 'https://www.youtube.com/embed/' . $params['v'];
            } elseif (strpos($video_url, 'youtu.be/') !== false) {
                $video_id = substr(parse_url($video_url, PHP_URL_PATH), 1);
                $video_url = 'https://www.youtube.com/embed/' . $video_id;
            }
            $stmt = $conn->prepare("INSERT INTO tbl_barangay_media (media_type, video_url, caption) VALUES ('video', ?, ?)");
            $stmt->bind_param("ss", $video_url, $caption);
            if ($stmt->execute()) $success_message = "Video added successfully!";
            else $error_message = "Error adding video.";
            $stmt->close();

        } elseif ($_POST['action'] === 'toggle') {
            $id = intval($_POST['id']);
            $is_active = intval($_POST['is_active']);
            $stmt = $conn->prepare("UPDATE tbl_barangay_media SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_active, $id);
            if ($stmt->execute()) $success_message = "Media status updated!";
            $stmt->close();

        } elseif ($_POST['action'] === 'delete') {
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
            $stmt->close();
        }
    }
}

// Fetch all media
$photos = [];
$videos = [];

$stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'photo' ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $photos[] = $row; }
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM tbl_barangay_media WHERE media_type = 'video' ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $videos[] = $row; }
$stmt->close();

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');

/* ── Variables (in sync with dashboard-index.css) ── */
:root {
    --mm-navy:        #0d1b36;
    --mm-navy-mid:    #152849;
    --mm-navy-light:  #1c3461;
    --mm-amber:       #f59e0b;
    --mm-amber-light: #fef3c7;
    --mm-amber-dark:  #b45309;
    --mm-teal:        #0d9488;
    --mm-teal-light:  #ccfbf1;
    --mm-rose:        #e11d48;
    --mm-rose-light:  #ffe4e6;
    --mm-indigo:      #6366f1;
    --mm-indigo-light:#e0e7ff;
    --mm-success:     #10b981;
    --mm-success-light:#d1fae5;
    --mm-danger:      #ef4444;
    --mm-danger-light:#fee2e2;
    --mm-bg:     #eef2f7;
    --mm-surf:   #ffffff;
    --mm-surf2:  #f8fafc;
    --mm-border: #e2e8f0;
    --mm-text:   #0f172a;
    --mm-muted:  #64748b;
    --mm-radius:    14px;
    --mm-radius-sm: 8px;
    --mm-radius-lg: 20px;
    --mm-shadow:    0 1px 3px rgba(13,27,54,.06), 0 4px 16px rgba(13,27,54,.07);
    --mm-shadow-lg: 0 8px 40px rgba(13,27,54,.14), 0 2px 8px rgba(13,27,54,.06);
}

*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'Sora', sans-serif;
    background: var(--mm-bg);
    color: var(--mm-text);
    font-size: 13.5px;
}

/* ── PAGE HERO ── */
.mm-hero {
    background: linear-gradient(135deg, var(--mm-navy) 0%, var(--mm-navy-light) 65%, #224090 100%);
    padding: 28px 36px;
    margin-bottom: 24px;
    border-radius: 0 0 var(--mm-radius-lg) var(--mm-radius-lg);
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}
.mm-hero::before {
    content: '';
    position: absolute;
    top: -80px; right: -60px;
    width: 260px; height: 260px;
    background: radial-gradient(circle, rgba(245,158,11,.14) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}
.mm-hero::after {
    content: '';
    position: absolute;
    bottom: -60px; left: 38%;
    width: 160px; height: 160px;
    background: radial-gradient(circle, rgba(13,148,136,.12) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
}

.mm-hero__left {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative; z-index: 1;
}
.mm-hero__icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--mm-amber), #d97706);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff;
    box-shadow: 0 4px 16px rgba(245,158,11,.35);
    flex-shrink: 0;
}
.mm-hero__title { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.4px; margin-bottom: 3px; }
.mm-hero__sub { font-size: 13px; color: rgba(255,255,255,.55); }

.mm-hero__right { position: relative; z-index: 1; }
.mm-back-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 10px 18px;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.18);
    color: rgba(255,255,255,.9);
    border-radius: var(--mm-radius-sm);
    text-decoration: none;
    font-family: 'Sora', sans-serif;
    font-size: 13px; font-weight: 600;
    transition: all .2s;
    backdrop-filter: blur(8px);
}
.mm-back-btn:hover { background: rgba(255,255,255,.2); color: #fff; }

/* ── ALERTS ── */
.mm-alert {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 18px;
    border-radius: var(--mm-radius);
    margin: 0 0 18px;
    font-weight: 500;
    border-left: 4px solid;
    transition: opacity .3s;
}
.mm-alert--success { background: var(--mm-success-light); color: #065f46; border-color: var(--mm-success); }
.mm-alert--error   { background: var(--mm-danger-light);  color: #7f1d1d;  border-color: var(--mm-danger);  }
.mm-alert__close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 17px; opacity: .6; }
.mm-alert__close:hover { opacity: 1; }

/* ── STATS ROW ── */
.mm-stats {
    display: flex;
    gap: 14px;
    margin-bottom: 22px;
    flex-wrap: wrap;
}
.mm-stat {
    flex: 1 1 160px;
    background: var(--mm-surf);
    border-radius: var(--mm-radius);
    padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    border: 1px solid var(--mm-border);
    box-shadow: var(--mm-shadow);
}
.mm-stat__icon {
    width: 42px; height: 42px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.mm-stat__icon--indigo { background: var(--mm-indigo-light); color: var(--mm-indigo); }
.mm-stat__icon--teal   { background: var(--mm-teal-light);   color: var(--mm-teal);   }
.mm-stat__icon--amber  { background: var(--mm-amber-light);  color: var(--mm-amber-dark); }
.mm-stat__num  { font-size: 26px; font-weight: 800; letter-spacing: -1px; line-height: 1; }
.mm-stat__label { font-size: 11px; color: var(--mm-muted); text-transform: uppercase; letter-spacing: .5px; font-weight: 500; margin-top: 2px; }

/* ── TABS ── */
.mm-tabs-wrap {
    background: var(--mm-surf);
    border-radius: var(--mm-radius-lg) var(--mm-radius-lg) 0 0;
    border: 1px solid var(--mm-border);
    border-bottom: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 22px;
    gap: 10px;
    flex-wrap: wrap;
}

.mm-tabs {
    display: flex;
    gap: 0;
}
.mm-tab-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 17px 22px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-family: 'Sora', sans-serif;
    font-size: 13.5px; font-weight: 600;
    color: var(--mm-muted);
    transition: all .2s;
    white-space: nowrap;
}
.mm-tab-btn .mm-tab-count {
    background: var(--mm-surf2);
    border: 1px solid var(--mm-border);
    border-radius: 20px;
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    font-weight: 500;
    padding: 1px 7px;
    transition: all .2s;
}
.mm-tab-btn:hover { color: var(--mm-navy); }
.mm-tab-btn.active {
    color: var(--mm-navy);
    border-bottom-color: var(--mm-amber);
}
.mm-tab-btn.active .mm-tab-count {
    background: var(--mm-amber-light);
    border-color: var(--mm-amber);
    color: var(--mm-amber-dark);
}

.mm-tab-actions { padding: 10px 0; display: flex; gap: 8px; align-items: center; }

/* ── TAB PANEL ── */
.mm-tab-panel {
    display: none;
    background: var(--mm-surf);
    border: 1px solid var(--mm-border);
    border-top: none;
    border-radius: 0 0 var(--mm-radius-lg) var(--mm-radius-lg);
    padding: 22px;
    min-height: 200px;
    animation: mmFadeIn .25s ease;
}
.mm-tab-panel.active { display: block; }

@keyframes mmFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── MEDIA GRID ── */
.mm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-top: 18px;
}

.mm-card {
    background: var(--mm-surf);
    border-radius: var(--mm-radius);
    overflow: hidden;
    border: 1px solid var(--mm-border);
    box-shadow: var(--mm-shadow);
    transition: transform .2s ease, box-shadow .2s ease;
    display: flex;
    flex-direction: column;
}
.mm-card:hover { transform: translateY(-3px); box-shadow: var(--mm-shadow-lg); }

/* Status stripe on top */
.mm-card::before {
    content: '';
    display: block;
    height: 3px;
    background: var(--mm-border);
    transition: background .2s;
}
.mm-card.is-active::before { background: var(--mm-success); }
.mm-card.is-inactive::before { background: var(--mm-muted); }

.mm-card__thumb {
    width: 100%;
    height: 200px;
    object-fit: cover;
    display: block;
    background: var(--mm-surf2);
}

.mm-card__iframe {
    width: 100%;
    height: 200px;
    border: none;
    display: block;
    background: var(--mm-navy);
}

/* Thumbnail overlay for photos */
.mm-card__img-wrap {
    position: relative;
    overflow: hidden;
}
.mm-card__img-wrap::after {
    content: '\f002';
    font-family: 'Font Awesome 5 Free';
    font-weight: 900;
    position: absolute;
    inset: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
    color: #fff;
    background: rgba(13,27,54,.45);
    opacity: 0;
    transition: opacity .2s;
}
.mm-card:hover .mm-card__img-wrap::after { opacity: 1; }

.mm-card__body {
    padding: 14px 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.mm-card__caption {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--mm-text);
    line-height: 1.45;
}

.mm-card__meta {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}

.mm-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px;
    border-radius: 20px;
    font-family: 'DM Mono', monospace;
    font-size: 10px; font-weight: 500;
    letter-spacing: .3px;
    white-space: nowrap;
}
.mm-badge--active   { background: var(--mm-success-light); color: #065f46; }
.mm-badge--inactive { background: var(--mm-surf2); color: var(--mm-muted); border: 1px solid var(--mm-border); }

.mm-card__date {
    font-family: 'DM Mono', monospace;
    font-size: 10.5px;
    color: var(--mm-muted);
    display: flex; align-items: center; gap: 5px;
}

.mm-card__footer {
    display: flex;
    gap: 6px;
    padding: 10px 16px 14px;
    border-top: 1px solid var(--mm-border);
    margin-top: auto;
}

/* ── BUTTONS ── */
.mm-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    border-radius: var(--mm-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 12.5px; font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all .18s ease;
    white-space: nowrap;
}
.mm-btn--sm { padding: 6px 12px; font-size: 12px; }
.mm-btn--full { width: 100%; justify-content: center; }

.mm-btn--primary {
    background: linear-gradient(135deg, var(--mm-navy), var(--mm-navy-light));
    color: #fff;
}
.mm-btn--primary:hover { background: linear-gradient(135deg, var(--mm-navy-light), #2748a0); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(13,27,54,.25); color: #fff; }

.mm-btn--ghost {
    background: var(--mm-surf2);
    color: var(--mm-text);
    border-color: var(--mm-border);
}
.mm-btn--ghost:hover { background: var(--mm-border); }

.mm-btn--show {
    background: var(--mm-teal-light);
    color: var(--mm-teal);
    border-color: rgba(13,148,136,.25);
    flex: 1;
}
.mm-btn--show:hover { background: var(--mm-teal); color: #fff; }

.mm-btn--hide {
    background: var(--mm-surf2);
    color: var(--mm-muted);
    border-color: var(--mm-border);
    flex: 1;
}
.mm-btn--hide:hover { background: var(--mm-border); color: var(--mm-text); }

.mm-btn--danger {
    background: var(--mm-danger-light);
    color: var(--mm-danger);
    border-color: rgba(239,68,68,.2);
    flex: 1;
}
.mm-btn--danger:hover { background: var(--mm-danger); color: #fff; }

/* ── EMPTY STATE ── */
.mm-empty {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 56px 24px;
    text-align: center; gap: 12px;
    background: var(--mm-surf2);
    border-radius: var(--mm-radius);
    border: 1px dashed var(--mm-border);
    margin-top: 18px;
}
.mm-empty i { font-size: 44px; color: var(--mm-border); }
.mm-empty h3 { font-size: 17px; font-weight: 700; color: var(--mm-muted); }
.mm-empty p  { font-size: 13px; color: var(--mm-muted); max-width: 280px; }

/* ── MODALS ── */
.mm-modal {
    display: none;
    position: fixed; inset: 0;
    background: rgba(13,27,54,.55);
    backdrop-filter: blur(5px);
    z-index: 9999;
    align-items: center; justify-content: center;
    padding: 20px;
}
.mm-modal--open { display: flex; }

.mm-modal__box {
    background: var(--mm-surf);
    border-radius: var(--mm-radius-lg);
    width: 100%; max-width: 520px;
    max-height: 92vh; overflow-y: auto;
    box-shadow: var(--mm-shadow-lg);
    animation: mmModalIn .28s cubic-bezier(.34,1.56,.64,1);
}
@keyframes mmModalIn {
    from { opacity: 0; transform: scale(.9) translateY(16px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}

.mm-modal__header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px;
    background: linear-gradient(135deg, var(--mm-navy), var(--mm-navy-light));
    border-radius: var(--mm-radius-lg) var(--mm-radius-lg) 0 0;
}
.mm-modal__header h3 {
    color: #fff; font-size: 15px; font-weight: 700;
    display: flex; align-items: center; gap: 8px;
}
.mm-modal__close {
    background: rgba(255,255,255,.12); border: none; color: rgba(255,255,255,.85);
    width: 30px; height: 30px; border-radius: 7px; cursor: pointer;
    font-size: 18px; display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.mm-modal__close:hover { background: rgba(255,255,255,.22); color: #fff; }

.mm-modal__body { padding: 22px; display: flex; flex-direction: column; gap: 16px; }

/* ── FORM ── */
.mm-field { display: flex; flex-direction: column; gap: 5px; }
.mm-field label { font-size: 12.5px; font-weight: 600; color: var(--mm-text); }
.mm-field .req { color: var(--mm-rose); }

.mm-input {
    width: 100%;
    padding: 9px 13px;
    border: 1.5px solid var(--mm-border);
    border-radius: var(--mm-radius-sm);
    font-family: 'Sora', sans-serif;
    font-size: 13px; color: var(--mm-text);
    background: var(--mm-surf);
    outline: none;
    transition: all .18s;
    appearance: none;
}
.mm-input:focus { border-color: var(--mm-navy-light); box-shadow: 0 0 0 3px rgba(28,52,97,.1); }
.mm-input::placeholder { color: #94a3b8; }

.mm-dropzone {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 10px;
    padding: 28px 20px;
    border: 2px dashed var(--mm-border);
    border-radius: var(--mm-radius);
    background: var(--mm-surf2);
    cursor: pointer;
    transition: all .2s;
    text-align: center;
}
.mm-dropzone:hover, .mm-dropzone.dragover {
    border-color: var(--mm-navy-light);
    background: #f0f4ff;
}
.mm-dropzone i { font-size: 36px; color: var(--mm-border); transition: color .2s; }
.mm-dropzone:hover i { color: var(--mm-navy-light); }
.mm-dropzone__label { font-size: 13.5px; font-weight: 600; color: var(--mm-text); }
.mm-dropzone__sub   { font-size: 11.5px; color: var(--mm-muted); }
.mm-dropzone__filename { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--mm-indigo); font-weight: 500; }

.mm-help { font-size: 11.5px; color: var(--mm-muted); display: flex; align-items: center; gap: 4px; }

/* ── IMAGE LIGHTBOX ── */
.mm-lightbox {
    display: none;
    position: fixed; inset: 0;
    background: rgba(13,27,54,.8);
    backdrop-filter: blur(8px);
    z-index: 10000;
    align-items: center; justify-content: center;
    padding: 20px;
}
.mm-lightbox--open { display: flex; }
.mm-lightbox__img { max-width: 90vw; max-height: 82vh; border-radius: var(--mm-radius); box-shadow: 0 24px 80px rgba(0,0,0,.5); display: block; }
.mm-lightbox__cap { color: rgba(255,255,255,.75); font-size: 13px; text-align: center; margin-top: 12px; font-family: 'DM Mono', monospace; }
.mm-lightbox__close {
    position: absolute; top: 20px; right: 24px;
    background: rgba(255,255,255,.14); border: none; color: #fff;
    width: 36px; height: 36px; border-radius: 9px;
    cursor: pointer; font-size: 20px;
    display: flex; align-items: center; justify-content: center;
}

/* ── SCROLLBAR ── */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--mm-surf2); }
::-webkit-scrollbar-thumb { background: var(--mm-border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--mm-muted); }

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
    .mm-hero { padding: 18px; border-radius: 0; flex-direction: column; align-items: flex-start; }
    .mm-hero__title { font-size: 18px; }
    .mm-tabs-wrap { flex-direction: column; align-items: flex-start; gap: 0; }
    .mm-tab-actions { border-top: 1px solid var(--mm-border); padding-top: 10px; width: 100%; }
    .mm-grid { grid-template-columns: 1fr; }
    .mm-stats { flex-direction: column; }
}
</style>

<!-- ── PAGE HERO ── -->
<div class="mm-hero">
    <div class="mm-hero__left">
        <div class="mm-hero__icon"><i class="fas fa-photo-video"></i></div>
        <div>
            <div class="mm-hero__title">Barangay Media</div>
            <div class="mm-hero__sub">Upload and manage photos &amp; videos for the dashboard</div>
        </div>
    </div>
    <div class="mm-hero__right">
        <a href="../dashboard/index.php" class="mm-back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($success_message): ?>
<div class="mm-alert mm-alert--success">
    <i class="fas fa-check-circle"></i>
    <span><?php echo htmlspecialchars($success_message); ?></span>
    <button class="mm-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="mm-alert mm-alert--error">
    <i class="fas fa-exclamation-circle"></i>
    <span><?php echo htmlspecialchars($error_message); ?></span>
    <button class="mm-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- ── STATS ── -->
<div class="mm-stats">
    <div class="mm-stat">
        <div class="mm-stat__icon mm-stat__icon--indigo"><i class="fas fa-photo-video"></i></div>
        <div>
            <div class="mm-stat__num"><?php echo count($photos) + count($videos); ?></div>
            <div class="mm-stat__label">Total Media</div>
        </div>
    </div>
    <div class="mm-stat">
        <div class="mm-stat__icon mm-stat__icon--teal"><i class="fas fa-images"></i></div>
        <div>
            <div class="mm-stat__num"><?php echo count($photos); ?></div>
            <div class="mm-stat__label">Photos</div>
        </div>
    </div>
    <div class="mm-stat">
        <div class="mm-stat__icon mm-stat__icon--amber"><i class="fas fa-video"></i></div>
        <div>
            <div class="mm-stat__num"><?php echo count($videos); ?></div>
            <div class="mm-stat__label">Videos</div>
        </div>
    </div>
    <?php
        $active_photos = count(array_filter($photos, fn($p) => $p['is_active']));
        $active_videos = count(array_filter($videos, fn($v) => $v['is_active']));
    ?>
    <div class="mm-stat">
        <div class="mm-stat__icon mm-stat__icon--teal"><i class="fas fa-eye"></i></div>
        <div>
            <div class="mm-stat__num"><?php echo $active_photos + $active_videos; ?></div>
            <div class="mm-stat__label">Currently Active</div>
        </div>
    </div>
</div>

<!-- ── TABS ── -->
<div class="mm-tabs-wrap">
    <div class="mm-tabs">
        <button class="mm-tab-btn active" onclick="switchTab('photos', this)">
            <i class="fas fa-images"></i> Photos
            <span class="mm-tab-count"><?php echo count($photos); ?></span>
        </button>
        <button class="mm-tab-btn" onclick="switchTab('videos', this)">
            <i class="fas fa-video"></i> Videos
            <span class="mm-tab-count"><?php echo count($videos); ?></span>
        </button>
    </div>
    <div class="mm-tab-actions" id="tab-actions-photos">
        <button class="mm-btn mm-btn--primary mm-btn--sm" onclick="openModal('addPhotoModal')">
            <i class="fas fa-cloud-upload-alt"></i> Upload Photo
        </button>
    </div>
    <div class="mm-tab-actions" id="tab-actions-videos" style="display:none;">
        <button class="mm-btn mm-btn--primary mm-btn--sm" onclick="openModal('addVideoModal')">
            <i class="fas fa-plus"></i> Add Video
        </button>
    </div>
</div>

<!-- ── PHOTOS TAB ── -->
<div id="photos-tab" class="mm-tab-panel active">
    <?php if (empty($photos)): ?>
    <div class="mm-empty">
        <i class="fas fa-images"></i>
        <h3>No Photos Yet</h3>
        <p>Upload photos to showcase barangay events and activities</p>
        <button class="mm-btn mm-btn--primary mm-btn--sm" onclick="openModal('addPhotoModal')">
            <i class="fas fa-cloud-upload-alt"></i> Upload First Photo
        </button>
    </div>
    <?php else: ?>
    <div class="mm-grid">
        <?php foreach ($photos as $photo): ?>
        <div class="mm-card <?php echo $photo['is_active'] ? 'is-active' : 'is-inactive'; ?>">
            <div class="mm-card__img-wrap" onclick="openLightbox('../../<?php echo htmlspecialchars($photo['file_path']); ?>', '<?php echo htmlspecialchars(addslashes($photo['caption'])); ?>')" style="cursor:zoom-in;">
                <img src="../../<?php echo htmlspecialchars($photo['file_path']); ?>"
                     alt="<?php echo htmlspecialchars($photo['caption']); ?>"
                     class="mm-card__thumb">
            </div>
            <div class="mm-card__body">
                <div class="mm-card__caption"><?php echo htmlspecialchars($photo['caption']); ?></div>
                <div class="mm-card__meta">
                    <span class="mm-badge <?php echo $photo['is_active'] ? 'mm-badge--active' : 'mm-badge--inactive'; ?>">
                        <i class="fas fa-<?php echo $photo['is_active'] ? 'eye' : 'eye-slash'; ?>"></i>
                        <?php echo $photo['is_active'] ? 'Visible' : 'Hidden'; ?>
                    </span>
                    <span class="mm-card__date"><i class="far fa-calendar"></i><?php echo date('M j, Y', strtotime($photo['created_at'])); ?></span>
                </div>
            </div>
            <div class="mm-card__footer">
                <form method="POST" style="flex:1; display:flex;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                    <input type="hidden" name="is_active" value="<?php echo $photo['is_active'] ? 0 : 1; ?>">
                    <button type="submit" class="mm-btn <?php echo $photo['is_active'] ? 'mm-btn--hide' : 'mm-btn--show'; ?>">
                        <i class="fas fa-<?php echo $photo['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                        <?php echo $photo['is_active'] ? 'Hide' : 'Show'; ?>
                    </button>
                </form>
                <form method="POST" style="flex:1; display:flex;" onsubmit="return confirm('Delete this photo? This cannot be undone.');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $photo['id']; ?>">
                    <button type="submit" class="mm-btn mm-btn--danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── VIDEOS TAB ── -->
<div id="videos-tab" class="mm-tab-panel">
    <?php if (empty($videos)): ?>
    <div class="mm-empty">
        <i class="fas fa-video"></i>
        <h3>No Videos Yet</h3>
        <p>Add YouTube videos to showcase barangay updates</p>
        <button class="mm-btn mm-btn--primary mm-btn--sm" onclick="openModal('addVideoModal')">
            <i class="fas fa-plus"></i> Add First Video
        </button>
    </div>
    <?php else: ?>
    <div class="mm-grid">
        <?php foreach ($videos as $video): ?>
        <div class="mm-card <?php echo $video['is_active'] ? 'is-active' : 'is-inactive'; ?>">
            <iframe src="<?php echo htmlspecialchars($video['video_url']); ?>"
                    class="mm-card__iframe"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
            <div class="mm-card__body">
                <div class="mm-card__caption"><?php echo htmlspecialchars($video['caption']); ?></div>
                <div class="mm-card__meta">
                    <span class="mm-badge <?php echo $video['is_active'] ? 'mm-badge--active' : 'mm-badge--inactive'; ?>">
                        <i class="fas fa-<?php echo $video['is_active'] ? 'eye' : 'eye-slash'; ?>"></i>
                        <?php echo $video['is_active'] ? 'Visible' : 'Hidden'; ?>
                    </span>
                    <span class="mm-card__date"><i class="far fa-calendar"></i><?php echo date('M j, Y', strtotime($video['created_at'])); ?></span>
                </div>
            </div>
            <div class="mm-card__footer">
                <form method="POST" style="flex:1; display:flex;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo $video['id']; ?>">
                    <input type="hidden" name="is_active" value="<?php echo $video['is_active'] ? 0 : 1; ?>">
                    <button type="submit" class="mm-btn <?php echo $video['is_active'] ? 'mm-btn--hide' : 'mm-btn--show'; ?>">
                        <i class="fas fa-<?php echo $video['is_active'] ? 'eye-slash' : 'eye'; ?>"></i>
                        <?php echo $video['is_active'] ? 'Hide' : 'Show'; ?>
                    </button>
                </form>
                <form method="POST" style="flex:1; display:flex;" onsubmit="return confirm('Delete this video?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo $video['id']; ?>">
                    <button type="submit" class="mm-btn mm-btn--danger">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>


<!-- ═══ MODALS ═══ -->

<!-- Upload Photo Modal -->
<div id="addPhotoModal" class="mm-modal">
    <div class="mm-modal__box">
        <div class="mm-modal__header">
            <h3><i class="fas fa-cloud-upload-alt"></i> Upload Photo</h3>
            <button class="mm-modal__close" onclick="closeModal('addPhotoModal')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data" class="mm-modal__body">
            <input type="hidden" name="action" value="add_photo">
            <div class="mm-field">
                <label>Photo File <span class="req">*</span></label>
                <input type="file" name="photo" id="photo-file" accept="image/*" required style="display:none" onchange="updateDropzone(this)">
                <div class="mm-dropzone" id="photo-dropzone" onclick="document.getElementById('photo-file').click()"
                     ondragover="e=event;e.preventDefault();this.classList.add('dragover')"
                     ondragleave="this.classList.remove('dragover')"
                     ondrop="handleDrop(event)">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <div class="mm-dropzone__label">Click or drag & drop to upload</div>
                    <div class="mm-dropzone__sub" id="dropzone-filename">JPG, PNG, GIF, WEBP — max 5MB</div>
                </div>
            </div>
            <div class="mm-field">
                <label>Caption <span class="req">*</span></label>
                <input type="text" name="caption" class="mm-input" placeholder="e.g., Medical Mission 2026" required>
            </div>
            <button type="submit" class="mm-btn mm-btn--primary mm-btn--full">
                <i class="fas fa-upload"></i> Upload Photo
            </button>
        </form>
    </div>
</div>

<!-- Add Video Modal -->
<div id="addVideoModal" class="mm-modal">
    <div class="mm-modal__box">
        <div class="mm-modal__header">
            <h3><i class="fab fa-youtube"></i> Add YouTube Video</h3>
            <button class="mm-modal__close" onclick="closeModal('addVideoModal')">×</button>
        </div>
        <form method="POST" class="mm-modal__body">
            <input type="hidden" name="action" value="add_video">
            <div class="mm-field">
                <label>YouTube URL <span class="req">*</span></label>
                <input type="url" name="video_url" class="mm-input" placeholder="https://www.youtube.com/watch?v=…" required>
                <span class="mm-help"><i class="fas fa-info-circle"></i> Paste the full YouTube URL — it will be auto-converted to embed format.</span>
            </div>
            <div class="mm-field">
                <label>Caption <span class="req">*</span></label>
                <input type="text" name="caption" class="mm-input" placeholder="e.g., Barangay Updates January 2026" required>
            </div>
            <button type="submit" class="mm-btn mm-btn--primary mm-btn--full">
                <i class="fas fa-save"></i> Add Video
            </button>
        </form>
    </div>
</div>

<!-- Image Lightbox -->
<div id="mm-lightbox" class="mm-lightbox">
    <button class="mm-lightbox__close" onclick="closeLightbox()">×</button>
    <div style="text-align:center;">
        <img id="mm-lightbox-img" src="" alt="" class="mm-lightbox__img">
        <div id="mm-lightbox-cap" class="mm-lightbox__cap"></div>
    </div>
</div>

<script>
// ── Tab switching ──
function switchTab(tab, btn) {
    document.querySelectorAll('.mm-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.mm-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.mm-tab-actions').forEach(a => a.style.display = 'none');
    document.getElementById(tab + '-tab').classList.add('active');
    btn.classList.add('active');
    document.getElementById('tab-actions-' + tab).style.display = 'flex';
}

// ── Modals ──
function openModal(id) { document.getElementById(id).classList.add('mm-modal--open'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('mm-modal--open'); document.body.style.overflow = ''; }

window.addEventListener('click', e => { if (e.target.classList.contains('mm-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.mm-modal--open').forEach(m => closeModal(m.id));
        closeLightbox();
    }
});

// ── Dropzone ──
function updateDropzone(input) {
    const name = input.files[0]?.name;
    if (name) {
        document.getElementById('dropzone-filename').textContent = name;
        document.getElementById('dropzone-filename').style.color = 'var(--mm-indigo)';
        document.querySelector('.mm-dropzone__label').textContent = 'File selected — click to change';
    }
}
function handleDrop(e) {
    e.preventDefault();
    const zone = document.getElementById('photo-dropzone');
    zone.classList.remove('dragover');
    const dt = e.dataTransfer;
    if (dt.files.length) {
        const fi = document.getElementById('photo-file');
        fi.files = dt.files;
        updateDropzone(fi);
    }
}

// ── Lightbox ──
function openLightbox(src, cap) {
    document.getElementById('mm-lightbox-img').src = src;
    document.getElementById('mm-lightbox-cap').textContent = cap;
    document.getElementById('mm-lightbox').classList.add('mm-lightbox--open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('mm-lightbox').classList.remove('mm-lightbox--open');
    document.body.style.overflow = '';
}
document.getElementById('mm-lightbox').addEventListener('click', function(e) {
    if (e.target === this) closeLightbox();
});

// ── Auto-dismiss alerts ──
setTimeout(() => {
    document.querySelectorAll('.mm-alert').forEach(a => {
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>