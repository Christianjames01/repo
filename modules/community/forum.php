<?php
// modules/community/forum.php - Community Board for Residents

session_start();
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

$user_id = getCurrentUserId();
$page_title = "Community Board";

// Handle new topic creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_topic'])) {
    $category_id = intval($_POST['category_id']);
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);

    if (!empty($title) && !empty($content) && $category_id > 0) {
        $stmt = $conn->prepare("INSERT INTO tbl_forum_topics (category_id, user_id, title, content) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $category_id, $user_id, $title, $content);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Topic created successfully!";
            header("Location: forum.php");
            exit();
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reply'])) {
    $topic_id = intval($_POST['topic_id']);
    $reply_content = trim($_POST['reply_content']);
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_POST['ajax']);

    // TEMP DEBUG - remove after fixing
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['debug' => true, 'topic_id' => $topic_id, 'content_length' => strlen($reply_content), 'post_keys' => array_keys($_POST)]);
        exit();
    }

    if (!empty($reply_content) && $topic_id > 0) {
        $check_stmt = $conn->prepare("SELECT is_locked FROM tbl_forum_topics WHERE topic_id = ?");
        $check_stmt->bind_param("i", $topic_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows > 0) {
            $topic = $result->fetch_assoc();
            if ($topic['is_locked'] == 0) {
                $stmt = $conn->prepare("INSERT INTO tbl_forum_replies (topic_id, user_id, content, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("iis", $topic_id, $user_id, $reply_content);
                if ($stmt->execute()) {
                    $update_stmt = $conn->prepare("UPDATE tbl_forum_topics SET updated_at = NOW() WHERE topic_id = ?");
                    $update_stmt->bind_param("i", $topic_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true]);
                        exit();
                    }
                    $_SESSION['success_message'] = "Reply posted successfully!";
                } else {
                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Database error']);
                        exit();
                    }
                    $_SESSION['error_message'] = "Error posting reply. Please try again.";
                }
                $stmt->close();
            } else {
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Topic is locked']);
                    exit();
                }
                $_SESSION['error_message'] = "This topic is locked and cannot receive new replies.";
            }
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Topic not found']);
                exit();
            }
            $_SESSION['error_message'] = "Topic not found.";
        }
        $check_stmt->close();
        header("Location: forum.php#topic-" . $topic_id);
        exit();
    } else {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid reply data']);
            exit();
        }
        $_SESSION['error_message'] = "Invalid reply data.";
        header("Location: forum.php");
        exit();
    }
}

// Get all categories with topic count
$categories_query = "
    SELECT c.*,
        COUNT(DISTINCT t.topic_id) as topic_count,
        COUNT(DISTINCT r.reply_id) as reply_count
    FROM tbl_forum_categories c
    LEFT JOIN tbl_forum_topics t ON c.category_id = t.category_id
    LEFT JOIN tbl_forum_replies r ON t.topic_id = r.topic_id
    WHERE c.is_active = 1
    GROUP BY c.category_id
    ORDER BY c.display_order ASC
";
$categories_result = $conn->query($categories_query);

// Get total stats
$stats_result = $conn->query("
    SELECT
        COUNT(DISTINCT t.topic_id) as total_topics,
        COUNT(DISTINCT r.reply_id) as total_replies,
        COUNT(DISTINCT t.user_id) as total_members,
        COUNT(DISTINCT CASE WHEN t.is_pinned = 1 THEN t.topic_id END) as pinned_topics
    FROM tbl_forum_topics t
    LEFT JOIN tbl_forum_replies r ON t.topic_id = r.topic_id
");
$stats = $stats_result->fetch_assoc();

// Get recent topics
$recent_topics_query = "
    SELECT t.*, u.username, res.first_name, res.last_name, res.profile_photo, c.category_name,
        (SELECT COUNT(*) FROM tbl_forum_replies WHERE topic_id = t.topic_id) as reply_count
    FROM tbl_forum_topics t
    LEFT JOIN tbl_users u ON t.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
    ORDER BY t.is_pinned DESC, t.created_at DESC
    LIMIT 8
";
$recent_topics = $conn->query($recent_topics_query);

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root {
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-violet:#7c3aed;--db-violet-light:#ede9fe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#3730a3,var(--db-indigo));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);text-decoration:none;color:inherit;transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--violet{background:linear-gradient(90deg,var(--db-violet),transparent);}

.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
.db-panel:nth-child(2){animation-delay:.05s;}
.db-panel:nth-child(3){animation-delay:.1s;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--violet{background:var(--db-violet-light);color:var(--db-violet);}

.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--indigo{background:linear-gradient(135deg,#3730a3,var(--db-indigo));color:#fff;}
.db-btn--indigo:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(99,102,241,.35);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--indigo{background:var(--db-indigo-light);color:#3730a3;}
.db-badge--teal{background:var(--db-teal-light);color:#065f46;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}

.category-item{padding:18px 22px;border-bottom:1px solid var(--db-border);transition:background .12s;cursor:pointer;display:flex;align-items:center;gap:16px;}
.category-item:last-child{border-bottom:none;}
.category-item:hover{background:var(--db-surf2);}
.category-item:hover .category-item__name{color:var(--db-indigo);}
.category-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.category-icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.category-icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.category-icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.category-icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.category-item__body{flex:1;min-width:0;}
.category-item__name{font-size:14px;font-weight:700;color:var(--db-text);margin-bottom:3px;transition:color .15s;}
.category-item__desc{font-size:12px;color:var(--db-muted);line-height:1.5;margin-bottom:8px;}
.category-item__stats{display:flex;gap:10px;flex-wrap:wrap;}
.cat-stat{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:var(--db-muted);font-family:'DM Mono',monospace;}
.category-item__arrow{color:var(--db-muted);font-size:13px;flex-shrink:0;transition:transform .15s,color .15s;}
.category-item:hover .category-item__arrow{transform:translateX(4px);color:var(--db-indigo);}

.topic-item{padding:14px 22px;border-bottom:1px solid var(--db-border);cursor:pointer;transition:background .12s;display:flex;align-items:flex-start;gap:12px;}
.topic-item:last-child{border-bottom:none;}
.topic-item:hover{background:var(--db-surf2);}
.topic-item:hover .topic-item__title{color:var(--db-indigo);}
.topic-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--db-border);flex-shrink:0;}
.topic-avatar-placeholder{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
.topic-item__body{flex:1;min-width:0;}
.topic-item__title{font-size:13px;font-weight:600;color:var(--db-text);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:color .15s;}
.topic-item__meta{font-size:11px;color:var(--db-muted);display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.topic-item__replies{font-family:'DM Mono',monospace;font-size:10px;font-weight:600;color:var(--db-teal);background:var(--db-teal-light);padding:1px 7px;border-radius:10px;white-space:nowrap;}

.db-alert{padding:12px 16px;border-radius:var(--db-radius-sm);margin-bottom:18px;border:1.5px solid;font-size:13px;display:flex;align-items:center;gap:10px;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:#6ee7b7;}
.db-alert--danger{background:var(--db-rose-light);color:#9f1239;border-color:#fda4af;}

.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box--lg{max-width:720px;}
.db-modal__box--xl{max-width:900px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--indigo{background:linear-gradient(135deg,#3730a3,var(--db-indigo));}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}

.db-field{margin-bottom:16px;}
.db-field label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.db-field input,.db-field textarea,.db-field select{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-field textarea{min-height:120px;resize:vertical;}
.db-field input:focus,.db-field textarea:focus,.db-field select:focus{border-color:var(--db-indigo);box-shadow:0 0 0 3px rgba(99,102,241,.1);}
.db-field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:36px;cursor:pointer;}

.topic-view__header{padding:20px 22px;border-bottom:1px solid var(--db-border);background:var(--db-surf2);}
.topic-view__title{font-size:17px;font-weight:800;color:var(--db-text);margin-bottom:12px;line-height:1.4;}
.topic-view__author{display:flex;align-items:center;gap:10px;}
.topic-view__content{padding:20px 22px;border-bottom:1px solid var(--db-border);font-size:14px;line-height:1.7;color:var(--db-text);}
.topic-view__replies-header{padding:14px 22px;background:var(--db-surf2);border-bottom:1px solid var(--db-border);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);display:flex;align-items:center;gap:8px;}
.reply-item{padding:16px 22px;border-bottom:1px solid var(--db-border);display:flex;gap:12px;}
.reply-item:last-child{border-bottom:none;}
.reply-item__body{flex:1;font-size:13px;line-height:1.6;color:var(--db-text);}
.reply-item__meta{font-size:11px;color:var(--db-muted);margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.reply-form{padding:16px 22px;border-top:1px solid var(--db-border);background:var(--db-surf2);}
.locked-notice{padding:14px 22px;display:flex;align-items:center;gap:10px;background:var(--db-amber-light);color:#78350f;font-size:13px;font-weight:500;}

.db-loading{padding:48px;text-align:center;color:var(--db-muted);}
.db-loading i{font-size:28px;margin-bottom:10px;animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.db-empty{padding:48px;text-align:center;color:var(--db-muted);}
.db-empty i{font-size:40px;margin-bottom:12px;opacity:.3;display:block;}

body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header,body.dark-mode .topic-view__header,body.dark-mode .topic-view__replies-header,body.dark-mode .reply-form{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .category-item,body.dark-mode .topic-item,body.dark-mode .reply-item{border-bottom-color:#334155 !important;}
body.dark-mode .category-item:hover,body.dark-mode .topic-item:hover{background:#0f172a !important;}
body.dark-mode .db-field input,body.dark-mode .db-field textarea,body.dark-mode .db-field select{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .topic-item__title,body.dark-mode .category-item__name,body.dark-mode .topic-view__title,body.dark-mode .reply-item__body{color:#f1f5f9 !important;}
body.dark-mode .topic-view__content{color:#e2e8f0 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}

@media(max-width:768px){
    .rm-hero{padding:20px 16px;}
    .db-stats-row .db-stat-card{flex:1 1 calc(50% - 6px);}
    .main-grid{grid-template-columns:1fr !important;}
}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-comments"></i></div>
            <div>
                <div class="rm-hero__title">Community Board</div>
                <div class="rm-hero__sub">Connect, discuss, and share with your community</div>
            </div>
        </div>
        <button class="db-btn db-btn--indigo" onclick="openModal('createTopicModal')">
            <i class="fas fa-plus"></i> New Topic
        </button>
    </div>
</div>

<div style="padding:0 24px 24px;">

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="db-alert db-alert--success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="db-alert db-alert--danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
        </div>
    <?php endif; ?>

    <div class="db-stats-row">
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-comments"></i></div>
            <div><div class="db-stat-card__num"><?php echo number_format($stats['total_topics'] ?? 0); ?></div><div class="db-stat-card__label">Total Topics</div></div>
            <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-reply"></i></div>
            <div><div class="db-stat-card__num"><?php echo number_format($stats['total_replies'] ?? 0); ?></div><div class="db-stat-card__label">Total Replies</div></div>
            <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-users"></i></div>
            <div><div class="db-stat-card__num"><?php echo number_format($stats['total_members'] ?? 0); ?></div><div class="db-stat-card__label">Contributors</div></div>
            <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
        </div>
        <div class="db-stat-card">
            <div class="db-stat-card__icon db-stat-card__icon--violet"><i class="fas fa-thumbtack"></i></div>
            <div><div class="db-stat-card__num"><?php echo number_format($stats['pinned_topics'] ?? 0); ?></div><div class="db-stat-card__label">Pinned Topics</div></div>
            <div class="db-stat-card__bar db-stat-card__bar--violet"></div>
        </div>
    </div>

    <div class="main-grid" style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">

        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-th-list"></i></div>
                    <h2>Discussion Categories</h2>
                    <span class="db-badge db-badge--sky"><?php echo $categories_result->num_rows; ?></span>
                </div>
            </div>
            <?php if ($categories_result->num_rows > 0):
                $cat_colors = ['indigo','teal','amber','sky','indigo','teal'];
                $ci = 0;
                while ($category = $categories_result->fetch_assoc()):
                    $col = $cat_colors[$ci % count($cat_colors)]; $ci++;
            ?>
                <div class="category-item" onclick="viewCategory(<?php echo $category['category_id']; ?>, '<?php echo htmlspecialchars(addslashes($category['category_name'])); ?>')">
                    <div class="category-icon category-icon--<?php echo $col; ?>">
                        <i class="<?php echo htmlspecialchars($category['icon']); ?>"></i>
                    </div>
                    <div class="category-item__body">
                        <div class="category-item__name"><?php echo htmlspecialchars($category['category_name']); ?></div>
                        <div class="category-item__desc"><?php echo htmlspecialchars($category['description']); ?></div>
                        <div class="category-item__stats">
                            <span class="cat-stat"><i class="fas fa-comment" style="font-size:10px;"></i> <?php echo number_format($category['topic_count']); ?> topics</span>
                            <span class="cat-stat"><i class="fas fa-reply" style="font-size:10px;"></i> <?php echo number_format($category['reply_count']); ?> replies</span>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right category-item__arrow"></i>
                </div>
            <?php endwhile; else: ?>
                <div class="db-empty"><i class="fas fa-folder-open"></i><p>No categories yet</p></div>
            <?php endif; ?>
        </div>

        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-clock"></i></div>
                    <h2>Recent Topics</h2>
                </div>
            </div>
            <?php if ($recent_topics->num_rows > 0):
                while ($topic = $recent_topics->fetch_assoc()):
                    $author = !empty($topic['first_name']) ? $topic['first_name'] . ' ' . $topic['last_name'] : $topic['username'];
                    $initial = strtoupper(substr($topic['username'] ?? 'U', 0, 1));
            ?>
                <div class="topic-item" onclick="viewTopic(<?php echo $topic['topic_id']; ?>)">
                    <?php if (!empty($topic['profile_photo'])): ?>
                        <img src="<?php echo BASE_URL; ?>/uploads/profiles/<?php echo htmlspecialchars($topic['profile_photo']); ?>" class="topic-avatar" alt="">
                    <?php else: ?>
                        <div class="topic-avatar-placeholder"><?php echo $initial; ?></div>
                    <?php endif; ?>
                    <div class="topic-item__body">
                        <div class="topic-item__title">
                            <?php if ($topic['is_pinned']): ?><i class="fas fa-thumbtack" style="color:var(--db-amber);font-size:10px;margin-right:4px;"></i><?php endif; ?>
                            <?php echo htmlspecialchars($topic['title']); ?>
                        </div>
                        <div class="topic-item__meta">
                            <span><?php echo htmlspecialchars($author); ?></span>
                            <span class="topic-item__replies"><i class="fas fa-reply" style="font-size:9px;"></i> <?php echo $topic['reply_count']; ?></span>
                        </div>
                    </div>
                </div>
            <?php endwhile; else: ?>
                <div class="db-empty"><i class="fas fa-comments"></i><p>No topics yet</p></div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Create Topic Modal -->
<div class="db-modal" id="createTopicModal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--indigo">
            <h3><i class="fas fa-plus"></i> Create New Topic</h3>
            <button class="db-modal__close" onclick="closeModal('createTopicModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST" action="">
                <div class="db-field">
                    <label>Category *</label>
                    <select name="category_id" required>
                        <option value="">Select a category…</option>
                        <?php $categories_result->data_seek(0); while ($cat = $categories_result->fetch_assoc()): ?>
                            <option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="db-field">
                    <label>Topic Title *</label>
                    <input type="text" name="title" placeholder="Enter a clear, descriptive title" required>
                </div>
                <div class="db-field">
                    <label>Content *</label>
                    <textarea name="content" placeholder="Share your thoughts with the community…" required></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('createTopicModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" name="create_topic" class="db-btn db-btn--indigo"><i class="fas fa-paper-plane"></i> Post Topic</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Category Topics Modal -->
<div class="db-modal" id="categoryModal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-th-list"></i> <span id="categoryModalTitle">Category Topics</span></h3>
            <button class="db-modal__close" onclick="closeModal('categoryModal')">×</button>
        </div>
        <div style="max-height:70vh;overflow-y:auto;" id="categoryModalBody">
            <div class="db-loading"><i class="fas fa-spinner"></i><p>Loading topics…</p></div>
        </div>
    </div>
</div>

<!-- View Topic Modal -->
<div class="db-modal" id="viewTopicModal">
    <div class="db-modal__box db-modal__box--xl">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-comment-dots"></i> Topic</h3>
            <button class="db-modal__close" onclick="closeModal('viewTopicModal')">×</button>
        </div>
        <div style="max-height:80vh;overflow-y:auto;" id="topicModalBody">
            <div class="db-loading"><i class="fas fa-spinner"></i><p>Loading topic…</p></div>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?php echo BASE_URL; ?>';

function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click', e=>{ if(e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function viewCategory(categoryId, categoryName) {
    document.getElementById('categoryModalTitle').textContent = categoryName;
    openModal('categoryModal');
    loadCategoryTopics(categoryId);
}

async function loadCategoryTopics(categoryId) {
    const body = document.getElementById('categoryModalBody');
    body.innerHTML = '<div class="db-loading"><i class="fas fa-spinner"></i><p>Loading topics…</p></div>';
    try {
        const r = await fetch(`${BASE_URL}/modules/community/get-category-topics.php?id=${categoryId}`);
        const d = await r.json();
        if (d.success && d.topics.length > 0) {
            body.innerHTML = d.topics.map(t => `
                <div class="topic-item" onclick="viewTopicFromCategory(${t.topic_id})">
                    ${t.profile_photo ? `<img src="${BASE_URL}/uploads/profiles/${t.profile_photo}" class="topic-avatar" alt="">` : `<div class="topic-avatar-placeholder">${t.username.charAt(0).toUpperCase()}</div>`}
                    <div class="topic-item__body">
                        <div class="topic-item__title">${escapeHtml(t.title)}</div>
                        <div class="topic-item__meta">
                            <span>${escapeHtml(t.username)}</span>
                            <span class="topic-item__replies"><i class="fas fa-reply" style="font-size:9px;"></i> ${t.reply_count}</span>
                            <span>${formatDate(t.created_at)}</span>
                        </div>
                        <div style="font-size:12px;color:var(--db-muted);margin-top:4px;line-height:1.5;">${escapeHtml(t.content.substring(0,120))}${t.content.length>120?'…':''}</div>
                    </div>
                    <i class="fas fa-chevron-right category-item__arrow" style="color:var(--db-muted);font-size:12px;flex-shrink:0;"></i>
                </div>`).join('');
        } else {
            body.innerHTML = '<div class="db-empty"><i class="fas fa-comments"></i><p>No topics in this category yet</p></div>';
        }
    } catch {
        body.innerHTML = '<div class="db-empty"><i class="fas fa-exclamation-circle" style="color:var(--db-rose);"></i><p>Error loading topics</p></div>';
    }
}

function viewTopicFromCategory(topicId) {
    closeModal('categoryModal');
    setTimeout(() => viewTopic(topicId), 200);
}

function viewTopic(topicId) {
    openModal('viewTopicModal');
    loadTopicDetails(topicId);
}

async function loadTopicDetails(topicId) {
    const body = document.getElementById('topicModalBody');
    body.innerHTML = '<div class="db-loading"><i class="fas fa-spinner"></i><p>Loading topic…</p></div>';
    try {
        const r = await fetch(`${BASE_URL}/modules/community/get-topic-data.php?id=${topicId}`);
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch { throw new Error('Invalid server response'); }
        if (d.success) renderTopicView(d.topic, d.replies);
        else body.innerHTML = `<div class="db-empty"><i class="fas fa-exclamation-circle" style="color:var(--db-rose);"></i><p>${d.message || 'Error loading topic'}</p></div>`;
    } catch (err) {
        body.innerHTML = `<div class="db-empty"><i class="fas fa-exclamation-circle" style="color:var(--db-rose);"></i><p>Error: ${err.message}</p></div>`;
    }
}


function renderTopicView(topic, replies) {
    console.log('topic_id:', topic.topic_id, 'full topic:', topic); // TEMP DEBUG
    const authorName = topic.author_name || topic.username;
    
    const initial = topic.username.charAt(0).toUpperCase();
    const avatarHtml = topic.profile_photo
        ? `<img src="${BASE_URL}/uploads/profiles/${topic.profile_photo}" class="topic-avatar" alt="">`
        : `<div class="topic-avatar-placeholder">${initial}</div>`;

    const repliesHtml = replies.length > 0
        ? replies.map(rep => {
            const rName = rep.author_name || rep.username;
            const rInit = rep.username.charAt(0).toUpperCase();
            const rAvatar = rep.profile_photo
                ? `<img src="${BASE_URL}/uploads/profiles/${rep.profile_photo}" class="topic-avatar" alt="">`
                : `<div class="topic-avatar-placeholder">${rInit}</div>`;
            return `<div class="reply-item">
                ${rAvatar}
                <div class="reply-item__body">
                    <div class="reply-item__meta">
                        <strong>${escapeHtml(rName)}</strong>
                        <span>·</span>
                        <span>${formatDate(rep.created_at)}</span>
                    </div>
                    ${escapeHtml(rep.content)}
                </div>
            </div>`;
        }).join('')
        : '<div class="db-empty" style="padding:24px;"><i class="fas fa-comment-slash"></i><p>No replies yet — be the first!</p></div>';

    const replySection = topic.is_locked != 1
        ? `<div class="reply-form">
            <div class="db-field" style="margin-bottom:10px;">
                <textarea id="replyContent_${topic.topic_id}" placeholder="Write a reply…" rows="3"></textarea>
            </div>
            <button type="button" class="db-btn db-btn--indigo db-btn--sm" onclick="submitReply(${topic.topic_id})">
                <i class="fas fa-paper-plane"></i> Post Reply
            </button>
            <span id="replyMsg_${topic.topic_id}" style="font-size:12px;margin-left:10px;"></span>
        </div>`
        : `<div class="locked-notice"><i class="fas fa-lock"></i> This topic is locked and not accepting new replies.</div>`;

    document.getElementById('topicModalBody').innerHTML = `
        <div class="topic-view__header">
            <div class="topic-view__title">
                ${topic.is_pinned == 1 ? '<i class="fas fa-thumbtack" style="color:var(--db-amber);margin-right:8px;font-size:14px;"></i>' : ''}
                ${escapeHtml(topic.title)}
            </div>
            <div class="topic-view__author">
                ${avatarHtml}
                <div>
                    <div style="font-size:13px;font-weight:700;">${escapeHtml(authorName)}</div>
                    <div style="font-size:11px;color:var(--db-muted);">${formatDate(topic.created_at)}</div>
                </div>
                ${topic.is_locked == 1 ? '<span class="db-badge db-badge--amber" style="margin-left:auto;"><i class="fas fa-lock"></i> Locked</span>' : ''}
            </div>
        </div>
        <div class="topic-view__content">${escapeHtml(topic.content)}</div>
        <div class="topic-view__replies-header">
            <i class="fas fa-reply"></i> Replies
            <span class="db-badge db-badge--sky">${replies.length}</span>
        </div>
        ${repliesHtml}
        ${replySection}
    `;
}

async function submitReply(topicId) {
    const textarea = document.getElementById('replyContent_' + topicId);
    const msg = document.getElementById('replyMsg_' + topicId);
    const content = textarea.value.trim();

    if (!content) {
        msg.style.color = 'var(--db-rose)';
        msg.textContent = 'Please write something first.';
        return;
    }

    msg.style.color = 'var(--db-muted)';
    msg.textContent = 'Posting…';

    const fd = new FormData();
    fd.append('topic_id', topicId);
    fd.append('reply_content', content);
    fd.append('add_reply', '1');
    fd.append('ajax', '1');

    try {
        const r = await fetch('/barangaylink1/modules/community/forum.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            msg.style.color = 'var(--db-success)';
            msg.textContent = 'Reply posted!';
            textarea.value = '';
            setTimeout(() => loadTopicDetails(topicId), 500);
        } else {
            msg.style.color = 'var(--db-rose)';
            msg.textContent = d.message || 'Error posting reply.';
        }
    } catch (err) {
        msg.style.color = 'var(--db-rose)';
        msg.textContent = 'Error posting reply.';
    }
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML.replace(/\n/g, '<br>');
}

function formatDate(ds) {
    return new Date(ds).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
</script>

<?php include '../../includes/footer.php'; ?>