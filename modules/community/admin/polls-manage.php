<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
require_once __DIR__ . '/check-poll-expiry.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);
checkAndCloseExpiredPolls($conn);

$page_title = 'Manage Polls & Surveys';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'edit') {
        $poll_id = intval($_POST['poll_id']); $question = trim($_POST['question']); $description = trim($_POST['description']);
        $status = $_POST['status']; $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null; $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
        if (empty($question)) { echo json_encode(['success'=>false,'message'=>'Question is required']); exit(); }
        if ($end_date) { $stmt = $conn->prepare("UPDATE tbl_polls SET question=?,description=?,status=?,end_date=?,allow_multiple=? WHERE poll_id=?"); $stmt->bind_param("ssssii",$question,$description,$status,$end_date,$allow_multiple,$poll_id); }
        else { $stmt = $conn->prepare("UPDATE tbl_polls SET question=?,description=?,status=?,end_date=NULL,allow_multiple=? WHERE poll_id=?"); $stmt->bind_param("sssii",$question,$description,$status,$allow_multiple,$poll_id); }
        echo json_encode($stmt->execute() ? ['success'=>true,'message'=>'Poll updated'] : ['success'=>false,'message'=>'Failed']); exit();
    }
    if ($_POST['action'] === 'close') {
        $poll_id = intval($_POST['poll_id']); $stmt = $conn->prepare("UPDATE tbl_polls SET status='closed' WHERE poll_id=?"); $stmt->bind_param("i",$poll_id);
        echo json_encode($stmt->execute() ? ['success'=>true,'message'=>'Poll closed'] : ['success'=>false,'message'=>'Failed']); exit();
    }
    if ($_POST['action'] === 'delete') {
        $poll_id = intval($_POST['poll_id']);
        $conn->query("DELETE FROM tbl_poll_votes WHERE poll_id=$poll_id"); $conn->query("DELETE FROM tbl_poll_options WHERE poll_id=$poll_id");
        $stmt = $conn->prepare("DELETE FROM tbl_polls WHERE poll_id=?"); $stmt->bind_param("i",$poll_id);
        echo json_encode($stmt->execute() ? ['success'=>true,'message'=>'Poll deleted'] : ['success'=>false,'message'=>'Failed']); exit();
    }
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where_conditions = []; $params = []; $types = '';
if ($status_filter !== 'all') { $where_conditions[] = "p.status=?"; $params[] = $status_filter; $types .= 's'; }
if (!empty($search)) { $where_conditions[] = "(p.question LIKE ? OR p.description LIKE ?)"; $sp="%{$search}%"; $params[]=$sp; $params[]=$sp; $types.='ss'; }
$where_clause = !empty($where_conditions) ? 'WHERE '.implode(' AND ',$where_conditions) : '';

$query = "SELECT p.*, CONCAT(r.first_name,' ',r.last_name) as created_by_name, COUNT(DISTINCT v.vote_id) as total_votes
    FROM tbl_polls p LEFT JOIN tbl_residents r ON p.created_by=r.resident_id LEFT JOIN tbl_poll_votes v ON p.poll_id=v.poll_id
    $where_clause GROUP BY p.poll_id ORDER BY p.created_at DESC";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types,...$params); }
$stmt->execute(); $polls = $stmt->get_result();

$stats_result = $conn->query("SELECT COUNT(DISTINCT p.poll_id) as total_polls, COUNT(DISTINCT v.vote_id) as total_votes, COUNT(DISTINCT CASE WHEN p.status='active' THEN p.poll_id END) as active_polls, COUNT(DISTINCT CASE WHEN p.status='closed' THEN p.poll_id END) as closed_polls FROM tbl_polls p LEFT JOIN tbl_poll_votes v ON p.poll_id=v.poll_id");
$stats = $stats_result->fetch_assoc();

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
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#065f46,var(--db-teal));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);text-decoration:none;color:inherit;transition:transform .2s,box-shadow .2s,border-color .2s;cursor:pointer;}
.db-stat-card:hover,.db-stat-card.active{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}

.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}

.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}

.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--danger{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));color:#fff;}
.db-btn--danger:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--secondary{background:linear-gradient(135deg,#374151,#6b7280);color:#fff;}
.db-btn--secondary:hover{transform:translateY(-1px);color:#fff;}
.db-btn--warning{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--warning:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}

.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--active{background:var(--db-success-light);color:#065f46;}
.db-badge--closed{background:var(--db-rose-light);color:#9f1239;}
.db-badge--draft{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}

/* Poll items */
.poll-item{padding:20px 22px;border-bottom:1px solid var(--db-border);transition:background .12s;}
.poll-item:last-child{border-bottom:none;}
.poll-item:hover{background:var(--db-surf2);}
.poll-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;gap:12px;}
.poll-question{font-size:15px;font-weight:700;color:var(--db-text);line-height:1.4;margin-bottom:4px;}
.poll-description{font-size:12.5px;color:var(--db-muted);line-height:1.5;}
.poll-meta{display:flex;flex-wrap:wrap;gap:14px;margin:10px 0;font-size:12px;color:var(--db-muted);}
.poll-meta span{display:flex;align-items:center;gap:5px;}
.poll-options{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;margin:10px 0;}
.poll-option{display:flex;justify-content:space-between;align-items:center;padding:8px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;}
.poll-option:last-child{border-bottom:none;}
.poll-option.winner{background:var(--db-amber-light);border-left:3px solid var(--db-amber);}
.option-votes{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--db-muted);}
.poll-winner-banner{background:linear-gradient(135deg,var(--db-amber-light),#fde68a);border-left:4px solid var(--db-amber);padding:10px 14px;border-radius:var(--db-radius-sm);margin:8px 0;display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:#78350f;}
.poll-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:12px;}
.poll-id{font-family:'DM Mono',monospace;font-size:10px;color:var(--db-indigo);font-weight:500;}
.poll-actions{display:flex;gap:6px;flex-wrap:wrap;}

.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box.large{max-width:700px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--rose{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-field{margin-bottom:16px;}
.db-field label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.db-field input,.db-field textarea,.db-field select{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-field textarea{min-height:90px;resize:vertical;}
.db-field input:focus,.db-field textarea:focus,.db-field select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-detail-row{margin-bottom:14px;}
.db-detail-label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--db-muted);letter-spacing:.5px;margin-bottom:4px;}
.db-detail-value{font-size:13.5px;color:var(--db-text);line-height:1.6;}
.db-detail-value.large{font-size:15px;font-weight:700;}
.db-options-list{list-style:none;padding:0;margin:0;border:1px solid var(--db-border);border-radius:var(--db-radius-sm);overflow:hidden;}
.db-options-list li{padding:9px 14px;border-bottom:1px solid var(--db-border);display:flex;justify-content:space-between;align-items:center;font-size:13px;}
.db-options-list li:last-child{border-bottom:none;}
.db-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.db-notice{display:flex;gap:10px;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-bottom:14px;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;border-left:3px solid var(--db-amber);}
.db-help{font-size:11px;color:var(--db-muted);margin-top:4px;}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-field input,body.dark-mode .db-field textarea,body.dark-mode .db-field select{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .poll-item:hover{background:#1e293b !important;}
body.dark-mode .poll-options{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .poll-option{border-bottom-color:#334155 !important;}
body.dark-mode .poll-question{color:#f1f5f9 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-options-list li{border-bottom-color:#334155 !important;}
body.dark-mode .db-options-list{border-color:#334155 !important;}
body.dark-mode .db-detail-value{color:#e2e8f0 !important;}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-poll"></i></div>
            <div>
                <div class="rm-hero__title">Manage Polls & Surveys</div>
                <div class="rm-hero__sub">Create, manage, and analyze community polls</div>
            </div>
        </div>
        <a href="create-poll.php" class="db-btn db-btn--success"><i class="fas fa-plus"></i> New Poll</a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<!-- Stats (clickable filter) -->
<div class="db-stats-row">
    <a href="?status=all" class="db-stat-card <?php echo $status_filter==='all'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-poll"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_polls']); ?></div><div class="db-stat-card__label">Total Polls</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </a>
    <a href="?status=all" class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-vote-yea"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_votes']); ?></div><div class="db-stat-card__label">Total Votes</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
    <a href="?status=active" class="db-stat-card <?php echo $status_filter==='active'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-spinner"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo number_format($stats['active_polls']); ?></div><div class="db-stat-card__label">Active Polls</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="?status=closed" class="db-stat-card <?php echo $status_filter==='closed'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-lock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo number_format($stats['closed_polls']); ?></div><div class="db-stat-card__label">Closed Polls</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </a>
</div>

<!-- Filters -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div>
            <h2>Filter Polls</h2>
        </div>
        <?php if ($search || $status_filter !== 'all'): ?>
            <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div style="flex:1;min-width:200px;">
                    <label class="db-filter-label">Search</label>
                    <input type="text" name="search" class="db-input" style="width:100%;" placeholder="Search polls…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="min-width:160px;">
                    <label class="db-filter-label">Status</label>
                    <select name="status" class="db-select" style="width:100%;">
                        <option value="all" <?php echo $status_filter==='all'?'selected':''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter==='active'?'selected':''; ?>>Active</option>
                        <option value="closed" <?php echo $status_filter==='closed'?'selected':''; ?>>Closed</option>
                        <option value="draft" <?php echo $status_filter==='draft'?'selected':''; ?>>Draft</option>
                    </select>
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Polls List -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list"></i></div>
            <h2><?php echo $status_filter !== 'all' ? ucfirst($status_filter).' Polls' : 'All Polls'; ?></h2>
            <span class="db-badge db-badge--sky"><?php echo $polls->num_rows; ?></span>
        </div>
    </div>

    <?php if ($polls->num_rows > 0): ?>
        <?php while ($poll = $polls->fetch_assoc()):
            $options_stmt = $conn->prepare("SELECT * FROM tbl_poll_options WHERE poll_id=? ORDER BY option_order");
            $options_stmt->bind_param("i", $poll['poll_id']); $options_stmt->execute();
            $options_result = $options_stmt->get_result();
            $is_closed = $poll['status']==='closed' || ($poll['end_date'] && strtotime($poll['end_date'])<=time());
            $winners = $is_closed ? getPollWinner($conn, $poll['poll_id']) : [];
        ?>
        <div class="poll-item">
            <div class="poll-header">
                <div style="flex:1;">
                    <div class="poll-question"><?php echo htmlspecialchars($poll['question']); ?></div>
                    <?php if ($poll['description']): ?>
                        <div class="poll-description"><?php echo htmlspecialchars($poll['description']); ?></div>
                    <?php endif; ?>
                </div>
                <span class="db-badge db-badge--<?php echo $poll['status']; ?>"><?php echo ucfirst($poll['status']); ?></span>
            </div>

            <div class="poll-meta">
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($poll['created_by_name']); ?></span>
                <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($poll['created_at'])); ?></span>
                <span><i class="fas fa-vote-yea"></i> <?php echo $poll['total_votes']; ?> votes</span>
                <?php if ($poll['end_date']): ?>
                    <span><i class="fas fa-clock"></i> <?php echo $is_closed?'Ended':'Ends'; ?>: <?php echo date('M d, Y g:i A', strtotime($poll['end_date'])); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($is_closed && !empty($winners)): ?>
                <div class="poll-winner-banner">
                    <i class="fas fa-trophy"></i>
                    <?php if (count($winners)===1): ?>
                        Winner: <?php echo htmlspecialchars($winners[0]['option_text']); ?> (<?php echo $winners[0]['vote_count']; ?> votes)
                    <?php else: ?>
                        Tie: <?php echo implode(', ', array_map(fn($w)=>htmlspecialchars($w['option_text']), $winners)); ?> (<?php echo $winners[0]['vote_count']; ?> each)
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="poll-options">
                <?php while ($option = $options_result->fetch_assoc()):
                    $vc_stmt = $conn->prepare("SELECT COUNT(*) as c FROM tbl_poll_votes WHERE option_id=?");
                    $vc_stmt->bind_param("i",$option['option_id']); $vc_stmt->execute();
                    $vote_count = $vc_stmt->get_result()->fetch_assoc()['c'];
                    $is_winner = !empty(array_filter($winners, fn($w)=>$w['option_id']===$option['option_id']));
                ?>
                <div class="poll-option <?php echo $is_winner?'winner':''; ?>">
                    <span>
                        <?php if ($is_winner): ?><i class="fas fa-trophy" style="color:var(--db-amber);margin-right:6px;"></i><?php endif; ?>
                        <?php echo htmlspecialchars($option['option_text']); ?>
                    </span>
                    <span class="option-votes"><?php echo $vote_count; ?> votes</span>
                </div>
                <?php endwhile; ?>
            </div>

            <div class="poll-footer">
                <span class="poll-id">#<?php echo str_pad($poll['poll_id'],5,'0',STR_PAD_LEFT); ?></span>
                <div class="poll-actions">
                    <button onclick="showViewModal(<?php echo $poll['poll_id']; ?>)" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-eye"></i> View</button>
                    <button onclick="showEditModal(<?php echo $poll['poll_id']; ?>)" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-edit"></i> Edit</button>
                    <?php if ($poll['status']==='active'): ?>
                        <button onclick="showCloseModal(<?php echo $poll['poll_id']; ?>, '<?php echo htmlspecialchars($poll['question'],ENT_QUOTES); ?>')" class="db-btn db-btn--secondary db-btn--sm"><i class="fas fa-lock"></i> Close</button>
                    <?php endif; ?>
                    <button onclick="showDeleteModal(<?php echo $poll['poll_id']; ?>, '<?php echo htmlspecialchars($poll['question'],ENT_QUOTES); ?>')" class="db-btn db-btn--danger db-btn--sm"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="db-empty">
            <i class="fas fa-poll"></i>
            <p>No polls found.</p>
            <?php if ($search || $status_filter !== 'all'): ?>
                <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filters</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</div><!-- /padding -->

<!-- View Modal -->
<div class="db-modal" id="viewModal">
    <div class="db-modal__box large">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-eye"></i> Poll Details</h3>
            <button class="db-modal__close" onclick="closeModal('viewModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-detail-row"><div class="db-detail-label">Question</div><div class="db-detail-value large" id="viewQuestion"></div></div>
            <div class="db-detail-row"><div class="db-detail-label">Description</div><div class="db-detail-value" id="viewDescription"></div></div>
            <div class="db-detail-row"><div class="db-detail-label">Options & Votes</div><ul class="db-options-list" id="viewOptions"></ul></div>
            <div class="db-grid-2">
                <div class="db-detail-row"><div class="db-detail-label">Status</div><div class="db-detail-value" id="viewStatus"></div></div>
                <div class="db-detail-row"><div class="db-detail-label">Total Votes</div><div class="db-detail-value" id="viewTotalVotes"></div></div>
            </div>
            <div class="db-grid-2">
                <div class="db-detail-row"><div class="db-detail-label">Created By</div><div class="db-detail-value" id="viewCreatedBy"></div></div>
                <div class="db-detail-row"><div class="db-detail-label">Created At</div><div class="db-detail-value" id="viewCreatedAt" style="font-family:'DM Mono',monospace;font-size:12px;"></div></div>
            </div>
            <div class="db-detail-row" id="viewEndDateSection" style="display:none;">
                <div class="db-detail-label">End Date & Time</div>
                <div class="db-detail-value" id="viewEndDate" style="font-family:'DM Mono',monospace;font-size:12px;"></div>
            </div>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('viewModal')"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="db-modal" id="editModal">
    <div class="db-modal__box large">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-edit"></i> Edit Poll</h3>
            <button class="db-modal__close" onclick="closeModal('editModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form id="editPollForm">
                <input type="hidden" id="editPollId" name="poll_id">
                <div class="db-field"><label>Question *</label><input type="text" id="editQuestion" name="question" required></div>
                <div class="db-field"><label>Description</label><textarea id="editDescription" name="description"></textarea></div>
                <div class="db-grid-2">
                    <div class="db-field"><label>Status *</label>
                        <select id="editStatus" name="status" required>
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <div class="db-field"><label>End Date & Time</label><input type="datetime-local" id="editEndDate" name="end_date"><div class="db-help">Poll closes automatically at this time</div></div>
                </div>
                <div class="db-field">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;font-size:13px;">
                        <input type="checkbox" id="editAllowMultiple" name="allow_multiple" value="1"> Allow multiple selections
                    </label>
                </div>
            </form>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Cancel</button>
                <button class="db-btn db-btn--primary" onclick="saveEditPoll()"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Close Modal -->
<div class="db-modal" id="closePollModal">
    <div class="db-modal__box" style="max-width:440px;">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-lock"></i> Close Poll</h3>
            <button class="db-modal__close" onclick="closeModal('closePollModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-notice db-notice--amber"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:1px;"></i><span>Once closed, residents will no longer be able to vote on this poll.</span></div>
            <p style="color:var(--db-muted);font-size:13px;">Close "<strong id="closePollTitle" style="color:var(--db-text);"></strong>"?</p>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('closePollModal')"><i class="fas fa-times"></i> Cancel</button>
                <button class="db-btn db-btn--secondary" onclick="confirmClosePoll()"><i class="fas fa-lock"></i> Close Poll</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="db-modal" id="deletePollModal">
    <div class="db-modal__box" style="max-width:440px;">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-trash"></i> Delete Poll</h3>
            <button class="db-modal__close" onclick="closeModal('deletePollModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p style="color:var(--db-muted);line-height:1.7;margin-bottom:4px;">Permanently delete "<strong id="deletePollTitle" style="color:var(--db-text);"></strong>"? <strong style="color:var(--db-rose);">All votes will be permanently removed and cannot be undone.</strong></p>
            <div class="db-modal__footer">
                <button class="db-btn db-btn--ghost" onclick="closeModal('deletePollModal')"><i class="fas fa-times"></i> Cancel</button>
                <button class="db-btn db-btn--danger" onclick="confirmDeletePoll()"><i class="fas fa-trash"></i> Delete Poll</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentPollId = null;

function closeModal(id){ document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
window.addEventListener('click', e=>{ if(e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e=>{ if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function showViewModal(id){ currentPollId=id; loadPollDetails(id); openModal('viewModal'); }

async function loadPollDetails(id){
    const r=await fetch('get-poll-details.php?id='+id); const d=await r.json();
    if(d.success){
        const p=d.poll;
        document.getElementById('viewQuestion').textContent=p.question;
        document.getElementById('viewDescription').textContent=p.description||'No description';
        document.getElementById('viewStatus').innerHTML=`<span class="db-badge db-badge--${p.status}">${p.status.charAt(0).toUpperCase()+p.status.slice(1)}</span>`;
        document.getElementById('viewTotalVotes').textContent=p.total_votes+' votes';
        document.getElementById('viewCreatedBy').textContent=p.created_by_name;
        document.getElementById('viewCreatedAt').textContent=new Date(p.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        if(p.end_date){ document.getElementById('viewEndDateSection').style.display='block'; document.getElementById('viewEndDate').textContent=new Date(p.end_date).toLocaleString(); }
        else document.getElementById('viewEndDateSection').style.display='none';
        const ol=document.getElementById('viewOptions'); ol.innerHTML='';
        p.options.forEach(o=>{ const li=document.createElement('li'); li.innerHTML=`<span>${escapeHtml(o.option_text)}</span><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:600;">${o.vote_count} votes</span>`; ol.appendChild(li); });
    }
}

function showEditModal(id){
    currentPollId=id; openModal('editModal');
    fetch('get-poll-details.php?id='+id).then(r=>r.json()).then(d=>{
        if(d.success){
            const p=d.poll;
            document.getElementById('editPollId').value=p.poll_id;
            document.getElementById('editQuestion').value=p.question;
            document.getElementById('editDescription').value=p.description||'';
            document.getElementById('editStatus').value=p.status;
            if(p.end_date){ const ed=new Date(p.end_date); document.getElementById('editEndDate').value=ed.getFullYear()+'-'+String(ed.getMonth()+1).padStart(2,'0')+'-'+String(ed.getDate()).padStart(2,'0')+'T'+String(ed.getHours()).padStart(2,'0')+':'+String(ed.getMinutes()).padStart(2,'0'); }
            else document.getElementById('editEndDate').value='';
            document.getElementById('editAllowMultiple').checked=p.allow_multiple==1;
        }
    });
}

function saveEditPoll(){
    const fd=new FormData(document.getElementById('editPollForm')); fd.append('action','edit');
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.success){location.reload();}else alert('Error: '+d.message); });
}

function showCloseModal(id,title){ currentPollId=id; document.getElementById('closePollTitle').textContent=title; openModal('closePollModal'); }
function confirmClosePoll(){
    const fd=new FormData(); fd.append('action','close'); fd.append('poll_id',currentPollId);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.success)location.reload(); else{alert('Error: '+d.message);closeModal('closePollModal');} });
}

function showDeleteModal(id,title){ currentPollId=id; document.getElementById('deletePollTitle').textContent=title; openModal('deletePollModal'); }
function confirmDeletePoll(){
    const fd=new FormData(); fd.append('action','delete'); fd.append('poll_id',currentPollId);
    fetch('',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{ if(d.success)location.reload(); else{alert('Error: '+d.message);closeModal('deletePollModal');} });
}

function escapeHtml(t){ const d=document.createElement('div');d.textContent=t;return d.innerHTML; }
</script>

<?php include '../../../includes/footer.php'; ?>