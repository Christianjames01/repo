<?php
// modules/community/polls.php - Polls and Surveys for Residents
session_start();
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
require_once '../../includes/functions.php';

// Auto-close function and winner detection
function checkAndCloseExpiredPolls($conn) {
    $query = "UPDATE tbl_polls SET status = 'closed' WHERE status = 'active' AND end_date IS NOT NULL AND end_date <= NOW()";
    return $conn->query($query);
}

function getPollWinner($conn, $poll_id) {
    $query = "
        SELECT po.option_id, po.option_text, COUNT(pv.vote_id) as vote_count
        FROM tbl_poll_options po
        LEFT JOIN tbl_poll_votes pv ON po.option_id = pv.option_id
        WHERE po.poll_id = ?
        GROUP BY po.option_id, po.option_text
        ORDER BY vote_count DESC, po.option_order ASC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $poll_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $options = [];
    $max_votes = -1;
    while ($row = $result->fetch_assoc()) {
        if ($max_votes === -1) $max_votes = $row['vote_count'];
        if ($row['vote_count'] === $max_votes) $options[] = $row;
    }
    return $options;
}

if (!isLoggedIn()) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

checkAndCloseExpiredPolls($conn);

$user_id = getCurrentUserId();
$page_title = "Polls & Surveys";

$resident_query = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$resident_query->bind_param("i", $user_id);
$resident_query->execute();
$resident_result = $resident_query->get_result();

if ($resident_result->num_rows > 0) {
    $current_resident_id = $resident_result->fetch_assoc()['resident_id'];
} else {
    $current_resident_id = 0;
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';

if ($status_filter === 'active') {
    $where_clause = "WHERE p.status = 'active' AND (p.end_date IS NULL OR p.end_date > NOW())";
} elseif ($status_filter === 'closed') {
    $where_clause = "WHERE p.status = 'closed' OR (p.end_date IS NOT NULL AND p.end_date <= NOW())";
} elseif ($status_filter === 'voted') {
    $where_clause = "WHERE EXISTS (SELECT 1 FROM tbl_poll_votes pv WHERE pv.poll_id = p.poll_id AND pv.resident_id = $current_resident_id)";
} else {
    $where_clause = "WHERE p.status IN ('active', 'closed')";
}

$polls = $conn->query("
    SELECT p.*,
           CONCAT(r.first_name, ' ', r.last_name) as creator_name,
           (SELECT COUNT(DISTINCT resident_id) FROM tbl_poll_votes WHERE poll_id = p.poll_id) as total_votes,
           (SELECT COUNT(*) FROM tbl_poll_votes WHERE poll_id = p.poll_id AND resident_id = $current_resident_id) as has_voted
    FROM tbl_polls p
    LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
    $where_clause
    ORDER BY p.created_at DESC
");

include '../../includes/header.php';
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

/* Hero */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1e4d8c,var(--db-sky));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.rm-hero__stats{display:flex;gap:20px;flex-wrap:wrap;}
.rm-hero__stat{text-align:center;}
.rm-hero__stat-val{font-family:'DM Mono',monospace;font-size:22px;font-weight:700;color:#fff;line-height:1;}
.rm-hero__stat-lbl{font-size:11px;color:rgba(255,255,255,.5);margin-top:3px;text-transform:uppercase;letter-spacing:.5px;}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--ghost{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);backdrop-filter:blur(4px);}
.db-btn--ghost:hover{background:rgba(255,255,255,.22);color:#fff;}

/* Alert */
.db-alert{padding:14px 18px;border-radius:var(--db-radius-sm);margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;font-size:13px;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-left:4px solid var(--db-success);}
.db-alert--danger{background:var(--db-rose-light);color:#7f1d1d;border-left:4px solid var(--db-rose);}
.db-alert--info{background:var(--db-sky-light);color:#075985;border-left:4px solid var(--db-sky);}
.db-alert i{flex-shrink:0;margin-top:1px;}
.db-alert .btn-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:14px;padding:0;}

/* Filter tabs */
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.filter-tab{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:30px;font-family:'Sora',sans-serif;font-size:12.5px;font-weight:600;text-decoration:none;border:1.5px solid var(--db-border);background:var(--db-surf);color:var(--db-muted);transition:all .18s;cursor:pointer;}
.filter-tab:hover{border-color:var(--db-navy-light);color:var(--db-navy-light);}
.filter-tab.active{background:var(--db-navy);border-color:var(--db-navy);color:#fff;box-shadow:0 2px 10px rgba(13,27,54,.22);}
.filter-tab .tab-count{font-family:'DM Mono',monospace;font-size:10px;background:rgba(255,255,255,.2);padding:1px 6px;border-radius:10px;}
.filter-tab:not(.active) .tab-count{background:var(--db-border);color:var(--db-muted);}

/* Poll card */
.poll-card{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1.5px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:16px;overflow:hidden;cursor:pointer;transition:all .22s;animation:dbFadeUp .35s ease both;}
.poll-card:hover{box-shadow:var(--db-shadow-lg);border-color:var(--db-teal);transform:translateY(-2px);}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.poll-card__head{padding:18px 22px 14px;}
.poll-card__meta{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:10px;flex-wrap:wrap;}
.poll-card__title{font-size:15px;font-weight:700;color:var(--db-text);margin-bottom:5px;transition:color .18s;line-height:1.4;}
.poll-card:hover .poll-card__title{color:var(--db-teal);}
.poll-card__desc{font-size:12.5px;color:var(--db-muted);line-height:1.5;}

/* Badge */
.db-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10.5px;font-weight:600;letter-spacing:.2px;white-space:nowrap;}
.db-badge--active{background:var(--db-success-light);color:#065f46;}
.db-badge--closed{background:#f1f5f9;color:var(--db-muted);}
.db-badge--voted{background:var(--db-sky-light);color:#075985;}
.db-badge--dot{width:7px;height:7px;border-radius:50%;display:inline-block;}
.db-badge--active .db-badge--dot{background:#10b981;animation:pulse 1.8s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* Winner banner */
.winner-banner{background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border-left:4px solid var(--db-amber);padding:10px 16px;margin:0 22px 14px;border-radius:var(--db-radius-sm);display:flex;align-items:center;gap:10px;font-size:12.5px;color:#78350f;}
.winner-banner i{color:var(--db-amber);font-size:16px;flex-shrink:0;}
.winner-banner strong{color:#92400e;}

/* Options preview */
.poll-card__options{padding:0 22px 14px;}
.options-preview{background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1px solid var(--db-border);overflow:hidden;}
.option-row-preview{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid var(--db-border);font-size:12.5px;transition:background .15s;}
.option-row-preview:last-child{border-bottom:none;}
.option-row-preview:hover{background:#f0fdfa;}
.option-row-preview__text{color:var(--db-text);font-weight:500;}
.option-row-preview__votes{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);background:var(--db-border);padding:2px 8px;border-radius:10px;}
.options-more{text-align:center;padding:8px;font-size:11.5px;color:var(--db-muted);font-style:italic;}

/* Stats bar */
.poll-card__stats{display:flex;align-items:center;gap:0;border-top:1px solid var(--db-border);flex-wrap:wrap;}
.stat-pill{display:flex;align-items:center;gap:6px;padding:10px 18px;font-size:12px;color:var(--db-muted);border-right:1px solid var(--db-border);}
.stat-pill:last-child{border-right:none;}
.stat-pill i{font-size:11px;color:var(--db-teal);}
.stat-pill strong{color:var(--db-text);font-weight:600;font-family:'DM Mono',monospace;}

/* Empty state */
.empty-state{text-align:center;padding:48px 24px;color:var(--db-muted);}
.empty-state__icon{width:64px;height:64px;border-radius:18px;background:var(--db-surf2);border:1.5px solid var(--db-border);display:flex;align-items:center;justify-content:center;font-size:26px;color:var(--db-muted);margin:0 auto 16px;}
.empty-state h4{font-size:15px;font-weight:700;color:var(--db-text);margin-bottom:6px;}
.empty-state p{font-size:13px;}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .poll-card,body.dark-mode .options-preview{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .poll-card__stats,body.dark-mode .stat-pill,body.dark-mode .option-row-preview{border-color:#334155 !important;}
body.dark-mode .option-row-preview:hover{background:#0f172a !important;}
body.dark-mode .filter-tab{background:#1e293b !important;border-color:#334155 !important;color:#94a3b8 !important;}
body.dark-mode .filter-tab.active{background:var(--db-navy) !important;border-color:var(--db-navy) !important;color:#fff !important;}
body.dark-mode .options-preview{background:#0f172a !important;}

@media(max-width:640px){
    .rm-hero{padding:20px 18px;}
    .rm-hero__title{font-size:17px;}
    .rm-hero__stats{display:none;}
    .poll-card__stats{flex-direction:column;align-items:flex-start;}
    .stat-pill{border-right:none;border-bottom:1px solid var(--db-border);width:100%;}
    .stat-pill:last-child{border-bottom:none;}
}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-poll-h"></i></div>
            <div>
                <div class="rm-hero__title">Polls &amp; Surveys</div>
                <div class="rm-hero__sub">Share your voice and help shape our community</div>
            </div>
        </div>
        <div class="rm-hero__stats">
            <?php
            $total_active = $conn->query("SELECT COUNT(*) as c FROM tbl_polls WHERE status='active' AND (end_date IS NULL OR end_date > NOW())")->fetch_assoc()['c'];
            $total_voted  = $conn->query("SELECT COUNT(DISTINCT poll_id) as c FROM tbl_poll_votes WHERE resident_id=$current_resident_id")->fetch_assoc()['c'];
            $total_all    = $conn->query("SELECT COUNT(*) as c FROM tbl_polls WHERE status IN ('active','closed')")->fetch_assoc()['c'];
            ?>
            <div class="rm-hero__stat">
                <div class="rm-hero__stat-val"><?php echo $total_active; ?></div>
                <div class="rm-hero__stat-lbl">Active</div>
            </div>
            <div class="rm-hero__stat">
                <div class="rm-hero__stat-val"><?php echo $total_voted; ?></div>
                <div class="rm-hero__stat-lbl">My Votes</div>
            </div>
            <div class="rm-hero__stat">
                <div class="rm-hero__stat-val"><?php echo $total_all; ?></div>
                <div class="rm-hero__stat-lbl">Total</div>
            </div>
        </div>
    </div>
</div>

<div style="padding:0 24px 32px;">

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="db-alert db-alert--success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></span>
            <button class="btn-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="db-alert db-alert--danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></span>
            <button class="btn-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
        </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="filter-bar">
        <a href="?status=active" class="filter-tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle"></i> Active
        </a>
        <a href="?status=voted" class="filter-tab <?php echo $status_filter === 'voted' ? 'active' : ''; ?>">
            <i class="fas fa-vote-yea"></i> My Votes
        </a>
        <a href="?status=closed" class="filter-tab <?php echo $status_filter === 'closed' ? 'active' : ''; ?>">
            <i class="fas fa-lock"></i> Closed
        </a>
        <a href="?status=all" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> All Polls
        </a>
    </div>

    <!-- Polls List -->
    <?php if ($polls->num_rows > 0): ?>
        <?php
        $delay = 0;
        while ($poll = $polls->fetch_assoc()):
            $options_query = "SELECT * FROM tbl_poll_options WHERE poll_id = ? ORDER BY option_order LIMIT 3";
            $options_stmt  = $conn->prepare($options_query);
            $options_stmt->bind_param("i", $poll['poll_id']);
            $options_stmt->execute();
            $options = $options_stmt->get_result();

            $is_closed = $poll['status'] === 'closed' || ($poll['end_date'] && strtotime($poll['end_date']) <= time());
            $winners   = $is_closed ? getPollWinner($conn, $poll['poll_id']) : [];

            $total_option_count_q = $conn->prepare("SELECT COUNT(*) as c FROM tbl_poll_options WHERE poll_id=?");
            $total_option_count_q->bind_param("i", $poll['poll_id']);
            $total_option_count_q->execute();
            $total_options = $total_option_count_q->get_result()->fetch_assoc()['c'];
        ?>
        <div class="poll-card" style="animation-delay:<?php echo $delay * 60; ?>ms"
             onclick="window.location.href='poll-detail.php?id=<?php echo $poll['poll_id']; ?>'">

            <!-- Head -->
            <div class="poll-card__head">
                <div class="poll-card__meta">
                    <div>
                        <?php if ($poll['has_voted'] > 0): ?>
                            <span class="db-badge db-badge--voted"><i class="fas fa-check"></i> Voted</span>
                        <?php elseif ($is_closed): ?>
                            <span class="db-badge db-badge--closed"><i class="fas fa-lock"></i> Closed</span>
                        <?php else: ?>
                            <span class="db-badge db-badge--active"><span class="db-badge--dot"></span> Active</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($poll['end_date']): ?>
                        <span style="font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);">
                            <i class="fas fa-calendar me-1" style="color:var(--db-teal);"></i>
                            <?php echo $is_closed ? 'Ended' : 'Ends'; ?>
                            <?php echo date('M d, Y · g:i A', strtotime($poll['end_date'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="poll-card__title"><?php echo htmlspecialchars($poll['question']); ?></div>
                <?php if (!empty($poll['description'])): ?>
                    <div class="poll-card__desc">
                        <?php echo htmlspecialchars(substr($poll['description'], 0, 160)) . (strlen($poll['description']) > 160 ? '…' : ''); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Winner banner -->
            <?php if ($is_closed && !empty($winners)): ?>
                <div class="winner-banner">
                    <i class="fas fa-trophy"></i>
                    <span>
                        <?php if (count($winners) === 1): ?>
                            Winner: <strong><?php echo htmlspecialchars($winners[0]['option_text']); ?></strong>
                            &mdash; <?php echo $winners[0]['vote_count']; ?> votes
                        <?php else: ?>
                            Tie: <strong><?php echo implode(', ', array_map(fn($w) => htmlspecialchars($w['option_text']), $winners)); ?></strong>
                            &mdash; <?php echo $winners[0]['vote_count']; ?> votes each
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Options preview -->
            <div class="poll-card__options">
                <div class="options-preview">
                    <?php
                    $shown = 0;
                    while ($opt = $options->fetch_assoc()):
                        $shown++;
                        $vc_stmt = $conn->prepare("SELECT COUNT(*) as c FROM tbl_poll_votes WHERE option_id=?");
                        $vc_stmt->bind_param("i", $opt['option_id']);
                        $vc_stmt->execute();
                        $vc = $vc_stmt->get_result()->fetch_assoc()['c'];
                    ?>
                        <div class="option-row-preview">
                            <span class="option-row-preview__text"><?php echo htmlspecialchars($opt['option_text']); ?></span>
                            <span class="option-row-preview__votes"><?php echo $vc; ?> votes</span>
                        </div>
                    <?php endwhile; ?>
                    <?php if ($total_options > 3): ?>
                        <div class="options-more">+<?php echo $total_options - 3; ?> more option<?php echo ($total_options - 3) > 1 ? 's' : ''; ?> — click to view all</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats bar -->
            <div class="poll-card__stats">
                <div class="stat-pill">
                    <i class="fas fa-users"></i>
                    <span><strong><?php echo $poll['total_votes']; ?></strong> votes</span>
                </div>
                <div class="stat-pill">
                    <i class="fas fa-user"></i>
                    <span>By <strong><?php echo htmlspecialchars($poll['creator_name']); ?></strong></span>
                </div>
                <div class="stat-pill">
                    <i class="fas fa-clock"></i>
                    <span>Posted <strong><?php echo date('M d, Y', strtotime($poll['created_at'])); ?></strong></span>
                </div>
                <?php if ($poll['allow_multiple']): ?>
                <div class="stat-pill">
                    <i class="fas fa-check-double"></i>
                    <span>Multi-select</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php $delay++; endwhile; ?>

    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state__icon">
                <i class="fas fa-poll-h"></i>
            </div>
            <h4>No polls found</h4>
            <p>
                <?php
                switch ($status_filter) {
                    case 'active':  echo "No active polls right now. Check back later!"; break;
                    case 'voted':   echo "You haven't voted in any polls yet. Check out the active polls!"; break;
                    case 'closed':  echo "No closed polls yet."; break;
                    default:        echo "No polls available at the moment.";
                }
                ?>
            </p>
        </div>
    <?php endif; ?>

</div>

<?php include '../../includes/footer.php'; ?>