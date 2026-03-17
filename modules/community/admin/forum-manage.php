<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Community Forum';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $topic_id = intval($_POST['topic_id'] ?? 0);
    $response = ['success' => false, 'message' => 'Invalid action'];
    if ($topic_id > 0) {
        if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM tbl_forum_topics WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Topic deleted successfully!'];
            $stmt->close();
        } elseif ($action === 'pin') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_pinned = 1 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Topic pinned successfully!'];
            $stmt->close();
        } elseif ($action === 'unpin') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_pinned = 0 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Topic unpinned successfully!'];
            $stmt->close();
        } elseif ($action === 'lock') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_locked = 1 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Topic locked successfully!'];
            $stmt->close();
        } elseif ($action === 'unlock') {
            $stmt = $conn->prepare("UPDATE tbl_forum_topics SET is_locked = 0 WHERE topic_id = ?");
            $stmt->bind_param("i", $topic_id);
            if ($stmt->execute()) $response = ['success' => true, 'message' => 'Topic unlocked successfully!'];
            $stmt->close();
        }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode($response); exit();
    } else {
        if ($response['success']) $_SESSION['success_message'] = $response['message'];
        header("Location: " . BASE_URL . "/modules/community/admin/forum-manage.php"); exit();
    }
}

$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_conditions = [];
$params = [];
$types = '';

if ($category_filter > 0) { $where_conditions[] = "t.category_id = ?"; $params[] = $category_filter; $types .= 'i'; }
if (!empty($search)) {
    $where_conditions[] = "(t.title LIKE ? OR t.content LIKE ? OR u.username LIKE ?)";
    $sp = "%{$search}%"; $params[] = $sp; $params[] = $sp; $params[] = $sp; $types .= 'sss';
}
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$query = "
    SELECT t.*, u.username,
           CONCAT(res.first_name, ' ', res.last_name) as author_name,
           res.profile_photo, c.category_name,
           (SELECT COUNT(*) FROM tbl_forum_replies WHERE topic_id = t.topic_id) as reply_count
    FROM tbl_forum_topics t
    LEFT JOIN tbl_users u ON t.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
    $where_clause
    ORDER BY t.is_pinned DESC, t.created_at DESC
";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$topics = $stmt->get_result();

$stats_result = $conn->query("
    SELECT 
        COUNT(DISTINCT t.topic_id) as total_topics,
        COUNT(DISTINCT r.reply_id) as total_replies,
        COUNT(DISTINCT CASE WHEN t.is_pinned = 1 THEN t.topic_id END) as pinned_topics,
        COUNT(DISTINCT CASE WHEN t.is_locked = 1 THEN t.topic_id END) as locked_topics
    FROM tbl_forum_topics t LEFT JOIN tbl_forum_replies r ON t.topic_id = r.topic_id
");
$stats = $stats_result->fetch_assoc();

$categories_result = $conn->query("SELECT * FROM tbl_forum_categories WHERE is_active = 1 ORDER BY display_order");

include '../../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
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
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4338ca,var(--db-indigo));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}

.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}

.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}

.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--danger{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));color:#fff;}
.db-btn--danger:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--warning{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--warning:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}
.db-btn--secondary{background:linear-gradient(135deg,#374151,#6b7280);color:#fff;}
.db-btn--secondary:hover{transform:translateY(-1px);color:#fff;}

.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}

.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* Topic row */
.topic-item{padding:20px 22px;border-bottom:1px solid var(--db-border);transition:background .12s;}
.topic-item:last-child{border-bottom:none;}
.topic-item:hover{background:var(--db-surf2);}
.topic-header{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
.author-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--db-navy),var(--db-indigo));display:flex;align-items:center;justify-content:center;font-weight:600;color:rgba(255,255,255,.85);font-size:15px;overflow:hidden;flex-shrink:0;}
.author-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
.topic-meta{flex:1;}
.author-name{font-weight:600;font-size:13px;}
.topic-date{font-size:11px;color:var(--db-muted);font-family:'DM Mono',monospace;}
.topic-badges{display:flex;gap:6px;flex-wrap:wrap;}
.topic-title{font-size:15px;font-weight:700;color:var(--db-text);margin-bottom:6px;line-height:1.4;}
.topic-content{color:var(--db-muted);line-height:1.7;font-size:13px;margin-bottom:14px;}
.topic-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.topic-stats{display:flex;gap:16px;font-size:12px;color:var(--db-muted);}
.topic-actions{display:flex;gap:6px;flex-wrap:wrap;}
.topic-id{font-family:'DM Mono',monospace;font-size:10px;color:var(--db-indigo);font-weight:500;}

.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:500px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box.large{max-width:760px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--rose{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));}
.db-modal__header--indigo{background:linear-gradient(135deg,#312e81,var(--db-indigo));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-topic-preview{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px 14px;margin-top:12px;}
.db-topic-preview-label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--db-muted);letter-spacing:.5px;margin-bottom:4px;}
.db-topic-preview-title{font-size:13px;font-weight:600;color:var(--db-text);}

/* View modal body */
.view-modal-scroll{max-height:65vh;overflow-y:auto;}
.topic-full-header{padding:18px 22px;border-bottom:1px solid var(--db-border);background:var(--db-surf2);}
.topic-full-title{font-size:17px;font-weight:700;color:var(--db-text);margin-bottom:10px;line-height:1.4;}
.topic-full-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--db-muted);}
.topic-full-content{padding:18px 22px;line-height:1.8;color:var(--db-muted);font-size:13.5px;white-space:pre-wrap;}
.replies-section{border-top:1px solid var(--db-border);}
.replies-header{padding:12px 22px;background:var(--db-surf2);border-bottom:1px solid var(--db-border);font-weight:600;font-size:13px;color:var(--db-text);}
.reply-item{padding:16px 22px;border-bottom:1px solid var(--db-border);background:var(--db-surf);}
.reply-item:last-child{border-bottom:none;}
.reply-header{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
.reply-author{font-weight:600;font-size:12.5px;}
.reply-date{font-size:11px;color:var(--db-muted);font-family:'DM Mono',monospace;}
.reply-content{color:var(--db-muted);line-height:1.6;font-size:13px;padding-left:50px;}
.no-replies{padding:24px;text-align:center;color:var(--db-muted);font-size:13px;}
.loading-spinner{display:flex;align-items:center;justify-content:center;padding:3rem;}
.spinner{border:3px solid var(--db-border);border-top:3px solid var(--db-indigo);border-radius:50%;width:40px;height:40px;animation:spin 1s linear infinite;}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-input,body.dark-mode .db-select{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .topic-item:hover{background:#1e293b !important;}
body.dark-mode .topic-title{color:#f1f5f9 !important;}
body.dark-mode .topic-full-header,.dark-mode .replies-header{background:#0f172a !important;}
body.dark-mode .reply-item{background:#1e293b !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-topic-preview{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-topic-preview-title{color:#f1f5f9 !important;}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-comments"></i></div>
            <div>
                <div class="rm-hero__title">Manage Community Forum</div>
                <div class="rm-hero__sub">Moderate topics, pin announcements, and manage discussions</div>
            </div>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
    <div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-comments"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_topics']); ?></div><div class="db-stat-card__label">Total Topics</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-reply"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_replies']); ?></div><div class="db-stat-card__label">Total Replies</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-thumbtack"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['pinned_topics']); ?></div><div class="db-stat-card__label">Pinned Topics</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-lock"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['locked_topics']); ?></div><div class="db-stat-card__label">Locked Topics</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div>
            <h2>Filter Topics</h2>
        </div>
        <?php if ($search || $category_filter): ?>
            <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div style="flex:1;min-width:200px;">
                    <label class="db-filter-label">Search</label>
                    <input type="text" name="search" class="db-input" style="width:100%;" placeholder="Search topics, content, author…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="min-width:180px;">
                    <label class="db-filter-label">Category</label>
                    <select name="category" class="db-select" style="width:100%;">
                        <option value="0">All Categories</option>
                        <?php while ($cat = $categories_result->fetch_assoc()): ?>
                            <option value="<?php echo $cat['category_id']; ?>" <?php echo $category_filter == $cat['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Topics List -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></div>
            <h2>Forum Topics</h2>
            <span class="db-badge db-badge--indigo"><?php echo $topics->num_rows; ?></span>
        </div>
        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-info-circle"></i> Click actions to moderate</span>
    </div>

    <?php if ($topics->num_rows > 0): ?>
        <?php while ($topic = $topics->fetch_assoc()): ?>
        <div class="topic-item">
            <div class="topic-header">
                <div class="author-avatar">
                    <?php if (!empty($topic['profile_photo'])): ?>
                        <img src="<?php echo BASE_URL; ?>/uploads/profiles/<?php echo htmlspecialchars($topic['profile_photo']); ?>" alt="" onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo strtoupper(substr($topic['username'] ?? 'U', 0, 1)); ?>';">
                    <?php else: ?>
                        <?php echo strtoupper(substr($topic['username'] ?? 'U', 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="topic-meta">
                    <div class="author-name"><?php echo htmlspecialchars($topic['author_name'] ?: ($topic['username'] ?? 'Unknown')); ?></div>
                    <div class="topic-date"><?php echo date('M d, Y h:i A', strtotime($topic['created_at'])); ?></div>
                </div>
                <div class="topic-badges">
                    <?php if ($topic['is_pinned']): ?>
                        <span class="db-badge db-badge--amber"><i class="fas fa-thumbtack"></i> Pinned</span>
                    <?php endif; ?>
                    <?php if ($topic['is_locked']): ?>
                        <span class="db-badge db-badge--rose"><i class="fas fa-lock"></i> Locked</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="topic-title"><?php echo htmlspecialchars($topic['title']); ?></div>
            <div class="topic-content">
                <?php echo nl2br(htmlspecialchars(substr($topic['content'], 0, 200))); ?>
                <?php if (strlen($topic['content']) > 200): ?>…<?php endif; ?>
            </div>

            <div class="topic-footer">
                <div class="topic-stats">
                    <span><i class="fas fa-comment"></i> <?php echo $topic['reply_count']; ?> replies</span>
                    <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($topic['category_name'] ?? 'Uncategorized'); ?></span>
                    <span class="topic-id">#<?php echo str_pad($topic['topic_id'], 5, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div class="topic-actions">
                    <button onclick="showViewModal(<?php echo $topic['topic_id']; ?>)" class="db-btn db-btn--ghost db-btn--sm">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <?php if (!$topic['is_pinned']): ?>
                        <button onclick="showActionModal('pin', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" class="db-btn db-btn--warning db-btn--sm">
                            <i class="fas fa-thumbtack"></i> Pin
                        </button>
                    <?php else: ?>
                        <button onclick="showActionModal('unpin', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" class="db-btn db-btn--secondary db-btn--sm">
                            <i class="fas fa-thumbtack"></i> Unpin
                        </button>
                    <?php endif; ?>
                    <?php if (!$topic['is_locked']): ?>
                        <button onclick="showActionModal('lock', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" class="db-btn db-btn--warning db-btn--sm">
                            <i class="fas fa-lock"></i> Lock
                        </button>
                    <?php else: ?>
                        <button onclick="showActionModal('unlock', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" class="db-btn db-btn--secondary db-btn--sm">
                            <i class="fas fa-unlock"></i> Unlock
                        </button>
                    <?php endif; ?>
                    <button onclick="showActionModal('delete', <?php echo $topic['topic_id']; ?>, '<?php echo htmlspecialchars(addslashes($topic['title'])); ?>')" class="db-btn db-btn--danger db-btn--sm">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="db-empty">
            <i class="fas fa-comments"></i>
            <p>No forum topics found.</p>
            <?php if ($search || $category_filter): ?>
                <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filters</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</div><!-- /padding -->

<!-- Action Modal -->
<div class="db-modal" id="actionModal">
    <div class="db-modal__box">
        <div class="db-modal__header" id="actionModalHeader">
            <h3 id="actionModalTitle"><i class="fas fa-question"></i> Action</h3>
            <button class="db-modal__close" onclick="closeModal('actionModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p id="actionModalMessage" style="color:var(--db-muted);line-height:1.7;"></p>
            <div class="db-topic-preview">
                <div class="db-topic-preview-label">Topic</div>
                <div class="db-topic-preview-title" id="actionModalTopicTitle"></div>
            </div>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('actionModal')"><i class="fas fa-times"></i> Cancel</button>
                <button class="db-btn" id="actionModalConfirmBtn" onclick="confirmAction()"></button>
            </div>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="db-modal" id="viewModal">
    <div class="db-modal__box large">
        <div class="db-modal__header db-modal__header--indigo">
            <h3><i class="fas fa-eye"></i> View Topic</h3>
            <button class="db-modal__close" onclick="closeModal('viewModal')">×</button>
        </div>
        <div class="view-modal-scroll" id="viewModalBody">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
        <div style="padding:14px 22px;border-top:1px solid var(--db-border);display:flex;justify-content:flex-end;">
            <button class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<form id="actionForm" method="POST" style="display:none;">
    <input type="hidden" name="topic_id" id="formTopicId">
    <input type="hidden" name="action" id="formAction">
</form>

<script>
let currentAction = '', currentTopicId = 0;

const modalConfig = {
    pin:    { title:'Pin Topic',    message:'Pinning this topic will keep it at the top of the forum list.',  icon:'fa-thumbtack', header:'db-modal__header--amber',  btn:'Pin Topic',    btnClass:'db-btn--warning' },
    unpin:  { title:'Unpin Topic',  message:'This topic will return to normal ordering based on recent activity.', icon:'fa-thumbtack', header:'db-modal__header--navy', btn:'Unpin Topic',  btnClass:'db-btn--secondary' },
    lock:   { title:'Lock Topic',   message:'Locking this topic will prevent users from adding new replies. Existing replies remain visible.', icon:'fa-lock', header:'db-modal__header--amber', btn:'Lock Topic',   btnClass:'db-btn--warning' },
    unlock: { title:'Unlock Topic', message:'Users will be able to reply to this topic again.', icon:'fa-unlock', header:'db-modal__header--navy', btn:'Unlock Topic', btnClass:'db-btn--secondary' },
    delete: { title:'Delete Topic', message:'This will permanently delete the topic and all its replies. This action cannot be undone.', icon:'fa-trash', header:'db-modal__header--rose', btn:'Delete Topic',  btnClass:'db-btn--danger' }
};

function closeModal(id){ document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
window.addEventListener('click', e => { if(e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function showActionModal(action, topicId, topicTitle) {
    currentAction = action; currentTopicId = topicId;
    const cfg = modalConfig[action];
    const header = document.getElementById('actionModalHeader');
    header.className = 'db-modal__header ' + cfg.header;
    document.getElementById('actionModalTitle').innerHTML = `<i class="fas ${cfg.icon}"></i> ${cfg.title}`;
    document.getElementById('actionModalMessage').textContent = cfg.message;
    document.getElementById('actionModalTopicTitle').textContent = topicTitle;
    const btn = document.getElementById('actionModalConfirmBtn');
    btn.textContent = cfg.btn;
    btn.className = 'db-btn ' + cfg.btnClass;
    openModal('actionModal');
}

function confirmAction() {
    document.getElementById('formTopicId').value = currentTopicId;
    document.getElementById('formAction').value = currentAction;
    document.getElementById('actionForm').submit();
}

function showViewModal(topicId) {
    document.getElementById('viewModalBody').innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';
    openModal('viewModal');
    loadTopicDetails(topicId);
}

async function loadTopicDetails(topicId) {
    try {
        const response = await fetch('<?php echo BASE_URL; ?>/modules/community/admin/get-topic-details.php?id=' + topicId);
        const data = await response.json();
        if (data.success) renderTopicDetails(data.topic, data.replies);
        else document.getElementById('viewModalBody').innerHTML = '<div class="no-replies"><i class="fas fa-exclamation-circle" style="color:var(--db-rose);font-size:2rem;display:block;text-align:center;margin-bottom:8px;"></i><p>Error loading topic details</p></div>';
    } catch(e) {
        document.getElementById('viewModalBody').innerHTML = '<div class="no-replies"><p>Error loading topic details</p></div>';
    }
}

function renderTopicDetails(topic, replies) {
    const badges = [];
    if(topic.is_pinned==1) badges.push('<span class="db-badge db-badge--amber"><i class="fas fa-thumbtack"></i> Pinned</span>');
    if(topic.is_locked==1) badges.push('<span class="db-badge db-badge--rose"><i class="fas fa-lock"></i> Locked</span>');

    let repliesHtml = replies && replies.length > 0
        ? replies.map(r=>`
            <div class="reply-item">
                <div class="reply-header">
                    <div class="author-avatar" style="width:32px;height:32px;font-size:12px;flex-shrink:0;">
                        ${r.profile_photo?`<img src="<?php echo BASE_URL; ?>/uploads/profiles/${r.profile_photo}" alt="">`:(r.username||'U').charAt(0).toUpperCase()}
                    </div>
                    <div><div class="reply-author">${escapeHtml(r.author_name||r.username)}</div><div class="reply-date">${formatDate(r.created_at)}</div></div>
                </div>
                <div class="reply-content">${escapeHtml(r.content)}</div>
            </div>`).join('')
        : '<div class="no-replies"><i class="fas fa-comments" style="font-size:2rem;display:block;text-align:center;margin-bottom:8px;opacity:.3;"></i><p>No replies yet</p></div>';

    document.getElementById('viewModalBody').innerHTML = `
        <div class="topic-full-header">
            <div class="topic-full-title">${escapeHtml(topic.title)}</div>
            <div class="topic-full-meta">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="author-avatar" style="width:28px;height:28px;font-size:11px;flex-shrink:0;">
                        ${topic.profile_photo?`<img src="<?php echo BASE_URL; ?>/uploads/profiles/${topic.profile_photo}" alt="">`:(topic.username||'U').charAt(0).toUpperCase()}
                    </div>
                    <span>${escapeHtml(topic.author_name||topic.username)}</span>
                </div>
                <span style="font-family:'DM Mono',monospace;font-size:11px;">${formatDate(topic.created_at)}</span>
                ${badges.join('')}
                <span class="db-badge db-badge--sky"><i class="fas fa-tag"></i> ${escapeHtml(topic.category_name||'')}</span>
            </div>
        </div>
        <div class="topic-full-content">${escapeHtml(topic.content)}</div>
        <div class="replies-section">
            <div class="replies-header"><i class="fas fa-comments"></i> Replies (${replies?replies.length:0})</div>
            ${repliesHtml}
        </div>`;
}

function formatDate(d){ return new Date(d).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); }
function escapeHtml(t){ const d=document.createElement('div');d.textContent=t;return d.innerHTML.replace(/\n/g,'<br>'); }
</script>

<?php include '../../../includes/footer.php'; ?>