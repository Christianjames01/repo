<?php
// modules/community/poll-detail.php - View and vote on a specific poll
session_start();
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';
require_once '../../includes/functions.php';

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
        if ($max_votes === -1) $max_votes = (int)$row['vote_count'];
        if ((int)$row['vote_count'] === (int)$max_votes) $options[] = $row;
    }
    return $options;
}

if (!isLoggedIn()) {
    header("Location: " . BASE_URL . "/modules/auth/login.php");
    exit();
}

checkAndCloseExpiredPolls($conn);
$user_id = getCurrentUserId();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid poll ID.";
    header("Location: polls.php");
    exit();
}

$poll_id = intval($_GET['id']);

$resident_query = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$resident_query->bind_param("i", $user_id);
$resident_query->execute();
$resident_result = $resident_query->get_result();

if ($resident_result->num_rows == 0) {
    $_SESSION['error_message'] = "User not found.";
    header("Location: polls.php");
    exit();
}

$current_resident_id = $resident_result->fetch_assoc()['resident_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote'])) {
    $selected_options = isset($_POST['options']) ? $_POST['options'] : [];

    if (empty($selected_options)) {
        $_SESSION['error_message'] = "Please select at least one option.";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }

    $check_poll = $conn->prepare("SELECT status, end_date FROM tbl_polls WHERE poll_id = ?");
    $check_poll->bind_param("i", $poll_id);
    $check_poll->execute();
    $poll_check = $check_poll->get_result()->fetch_assoc();

    if ($poll_check['status'] !== 'active' || ($poll_check['end_date'] && strtotime($poll_check['end_date']) <= time())) {
        $_SESSION['error_message'] = "This poll is no longer accepting votes.";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }

    $check_vote = $conn->prepare("SELECT vote_id FROM tbl_poll_votes WHERE poll_id = ? AND resident_id = ?");
    $check_vote->bind_param("ii", $poll_id, $current_resident_id);
    $check_vote->execute();

    if ($check_vote->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "You have already voted in this poll.";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO tbl_poll_votes (poll_id, resident_id, option_id) VALUES (?, ?, ?)");
        foreach ($selected_options as $option_id) {
            $option_id = intval($option_id);
            $stmt->bind_param("iii", $poll_id, $current_resident_id, $option_id);
            if (!$stmt->execute()) throw new Exception("Failed to record vote: " . $stmt->error);
        }
        $conn->commit();
        $_SESSION['success_message'] = "Your vote has been recorded. Thank you!";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error recording vote: " . $e->getMessage();
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }
}

$poll_query = "
    SELECT p.*,
           CONCAT(r.first_name, ' ', r.last_name) as creator_name,
           (SELECT COUNT(DISTINCT resident_id) FROM tbl_poll_votes WHERE poll_id = p.poll_id) as total_votes,
           (SELECT COUNT(DISTINCT resident_id) FROM tbl_poll_votes WHERE poll_id = p.poll_id AND resident_id = ?) as has_voted
    FROM tbl_polls p
    LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
    WHERE p.poll_id = ?
";

$stmt = $conn->prepare($poll_query);
$stmt->bind_param("ii", $current_resident_id, $poll_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error_message'] = "Poll not found.";
    header("Location: polls.php");
    exit();
}

$poll = $result->fetch_assoc();
$page_title = htmlspecialchars($poll['question']);

$options_query = "
    SELECT po.option_id, po.poll_id, po.option_text, po.option_order,
           (SELECT COUNT(*) FROM tbl_poll_votes WHERE option_id = po.option_id) as vote_count,
           (SELECT COUNT(*) FROM tbl_poll_votes WHERE option_id = po.option_id AND resident_id = ?) as user_voted
    FROM tbl_poll_options po
    WHERE po.poll_id = ?
    ORDER BY po.option_order ASC
";

$options_stmt = $conn->prepare($options_query);
$options_stmt->bind_param("ii", $current_resident_id, $poll_id);
$options_stmt->execute();
$options_result = $options_stmt->get_result();

$options_arr = [];
while ($row = $options_result->fetch_assoc()) {
    $options_arr[] = $row;
}
$options_stmt->close();

$is_closed = $poll['status'] === 'closed' || ($poll['end_date'] && strtotime($poll['end_date']) <= time());
$winners   = $is_closed ? getPollWinner($conn, $poll_id) : [];

$can_see_results = false;
if ($poll['show_results'] === 'always')                                       $can_see_results = true;
elseif ($poll['show_results'] === 'after_vote' && $poll['has_voted'] > 0)    $can_see_results = true;
elseif ($is_closed)                                                            $can_see_results = true;

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
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:flex-start;gap:16px;flex:1;min-width:0;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#1e4d8c,var(--db-sky));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(14,165,233,.4);flex-shrink:0;}
.rm-hero__title{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:4px;line-height:1.3;}
.rm-hero__sub{font-size:12.5px;color:rgba(255,255,255,.55);}
.rm-hero__meta{display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;}
.rm-hero__meta-item{display:flex;align-items:center;gap:6px;font-size:12px;color:rgba(255,255,255,.6);}
.rm-hero__meta-item i{color:rgba(255,255,255,.4);font-size:11px;}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--ghost{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);backdrop-filter:blur(4px);}
.db-btn--ghost:hover{background:rgba(255,255,255,.22);color:#fff;}
.db-btn--outline{background:var(--db-surf);color:var(--db-navy);border-color:var(--db-border);}
.db-btn--outline:hover{background:var(--db-navy);color:#fff;border-color:var(--db-navy);}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;border:none;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--lg{padding:11px 28px;font-size:14px;}

/* Badge */
.db-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10.5px;font-weight:600;letter-spacing:.2px;white-space:nowrap;}
.db-badge--active{background:var(--db-success-light);color:#065f46;}
.db-badge--closed{background:#f1f5f9;color:var(--db-muted);}
.db-badge--voted{background:var(--db-sky-light);color:#075985;}
.db-badge--dot{width:7px;height:7px;border-radius:50%;display:inline-block;}
.db-badge--active .db-badge--dot{background:#10b981;animation:pulse 1.8s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* Alert */
.db-alert{padding:14px 18px;border-radius:var(--db-radius-sm);margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;font-size:13px;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-left:4px solid var(--db-success);}
.db-alert--danger{background:var(--db-rose-light);color:#7f1d1d;border-left:4px solid var(--db-rose);}
.db-alert--info{background:var(--db-sky-light);color:#075985;border-left:4px solid var(--db-sky);}
.db-alert i{flex-shrink:0;margin-top:1px;}
.db-alert .btn-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;font-size:14px;padding:0;}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1.5px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__body{padding:22px;}

/* Winner banner */
.winner-banner{background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border-left:4px solid var(--db-amber);padding:14px 18px;border-radius:var(--db-radius-sm);display:flex;align-items:center;gap:12px;font-size:13px;color:#78350f;margin-bottom:18px;}
.winner-banner i{color:var(--db-amber);font-size:20px;flex-shrink:0;}
.winner-banner strong{color:#92400e;}

/* Vote options */
.vote-option{background:var(--db-surf2);border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);padding:13px 16px;margin-bottom:10px;cursor:pointer;transition:all .18s;display:flex;align-items:center;gap:14px;}
.vote-option:hover{border-color:var(--db-teal);background:#f0fdfa;}
.vote-option:has(input:checked){border-color:var(--db-teal);background:#f0fdfa;box-shadow:0 0 0 3px rgba(13,148,136,.1);}
.vote-option input[type="checkbox"],
.vote-option input[type="radio"]{width:18px;height:18px;accent-color:var(--db-teal);cursor:pointer;flex-shrink:0;}
.vote-option__text{font-size:13.5px;font-weight:500;color:var(--db-text);flex:1;}

/* Result bars */
.result-item{background:var(--db-surf2);border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);padding:14px 16px;margin-bottom:10px;transition:border-color .18s;}
.result-item.is-winner{border-color:var(--db-amber);background:linear-gradient(135deg,#fefce8,#fef9c3);}
.result-item__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:10px;flex-wrap:wrap;}
.result-item__label{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;color:var(--db-text);}
.result-item__label i{color:var(--db-amber);}
.result-item__stats{display:flex;align-items:center;gap:8px;}
.result-item__pct{font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--db-navy);}
.result-item__count{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);background:var(--db-border);padding:2px 8px;border-radius:10px;}
.result-item__your-vote{font-size:10.5px;font-weight:600;background:var(--db-sky-light);color:#075985;padding:2px 8px;border-radius:10px;font-family:'DM Mono',monospace;}
.result-track{height:10px;background:var(--db-border);border-radius:10px;overflow:hidden;}
.result-fill{height:100%;border-radius:10px;background:linear-gradient(90deg,var(--db-navy),var(--db-navy-light));transition:width .6s cubic-bezier(.4,0,.2,1);}
.result-fill.is-winner{background:linear-gradient(90deg,var(--db-amber-dark),var(--db-amber));}

/* Multi-select info */
.multi-info{display:flex;align-items:center;gap:10px;background:var(--db-sky-light);border-left:3px solid var(--db-sky);color:#075985;padding:10px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-bottom:16px;}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .vote-option,body.dark-mode .result-item{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .vote-option:hover,body.dark-mode .vote-option:has(input:checked){background:#0d2a27 !important;}
body.dark-mode .result-item.is-winner{background:linear-gradient(135deg,#1c1a05,#2a2307) !important;}
body.dark-mode .result-track{background:#334155 !important;}
body.dark-mode .db-btn--outline{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}

@media(max-width:640px){
    .rm-hero{padding:20px 18px;}
    .rm-hero__title{font-size:16px;}
    .rm-hero__meta{gap:10px;}
    .db-panel__body{padding:16px;}
    .db-btn--lg{width:100%;justify-content:center;}
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
            <div style="min-width:0;">
                <div style="margin-bottom:6px;">
                    <?php if ($poll['has_voted'] > 0): ?>
                        <span class="db-badge db-badge--voted"><i class="fas fa-check"></i> Voted</span>
                    <?php elseif ($is_closed): ?>
                        <span class="db-badge db-badge--closed"><i class="fas fa-lock"></i> Closed</span>
                    <?php else: ?>
                        <span class="db-badge db-badge--active"><span class="db-badge--dot"></span> Active</span>
                    <?php endif; ?>
                </div>
                <div class="rm-hero__title"><?php echo htmlspecialchars($poll['question']); ?></div>
                <div class="rm-hero__meta">
                    <span class="rm-hero__meta-item"><i class="fas fa-users"></i><?php echo $poll['total_votes']; ?> votes</span>
                    <span class="rm-hero__meta-item"><i class="fas fa-user"></i>By <?php echo htmlspecialchars($poll['creator_name']); ?></span>
                    <span class="rm-hero__meta-item"><i class="fas fa-clock"></i>Posted <?php echo date('M d, Y', strtotime($poll['created_at'])); ?></span>
                    <?php if ($poll['end_date']): ?>
                        <span class="rm-hero__meta-item">
                            <i class="fas fa-calendar"></i>
                            <?php echo $is_closed ? 'Ended' : 'Ends'; ?> <?php echo date('M d, Y · g:i A', strtotime($poll['end_date'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a href="polls.php" class="db-btn db-btn--ghost" style="flex-shrink:0;">
            <i class="fas fa-arrow-left"></i> Back to Polls
        </a>
    </div>
</div>

<div style="padding:0 24px 40px;max-width:860px;margin:0 auto;">

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

    <!-- Description panel (if any) -->
    <?php if (!empty($poll['description'])): ?>
        <div class="db-panel" style="animation-delay:0ms;">
            <div class="db-panel__body" style="padding:18px 22px;">
                <p style="margin:0;color:var(--db-muted);font-size:13px;line-height:1.7;">
                    <?php echo nl2br(htmlspecialchars($poll['description'])); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Winner banner -->
    <?php if ($is_closed && !empty($winners)): ?>
        <div class="winner-banner">
            <i class="fas fa-trophy"></i>
            <span>
                <?php if (count($winners) === 1): ?>
                    Poll Winner: <strong><?php echo htmlspecialchars($winners[0]['option_text']); ?></strong>
                    &mdash; <?php echo $winners[0]['vote_count']; ?> votes
                <?php else: ?>
                    Tie between:
                    <strong><?php echo implode(', ', array_map(fn($w) => htmlspecialchars($w['option_text']), $winners)); ?></strong>
                    &mdash; <?php echo $winners[0]['vote_count']; ?> votes each
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- Voting Section -->
    <?php if (!$is_closed && $poll['has_voted'] == 0): ?>
        <div class="db-panel" style="animation-delay:60ms;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-vote-yea"></i></div>
                    <h2>Cast Your Vote</h2>
                </div>
                <?php if ($poll['allow_multiple']): ?>
                    <span style="font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);">
                        <i class="fas fa-check-double me-1" style="color:var(--db-teal);"></i>Multi-select enabled
                    </span>
                <?php endif; ?>
            </div>
            <div class="db-panel__body">

                <?php if ($poll['allow_multiple']): ?>
                    <div class="multi-info">
                        <i class="fas fa-info-circle"></i>
                        You can select more than one option in this poll.
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="voteForm">
                    <?php foreach ($options_arr as $option): ?>
                        <label class="vote-option">
                            <input type="<?php echo $poll['allow_multiple'] ? 'checkbox' : 'radio'; ?>"
                                   name="options[]"
                                   value="<?php echo $option['option_id']; ?>">
                            <span class="vote-option__text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                        </label>
                    <?php endforeach; ?>

                    <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--db-border);display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" name="submit_vote" class="db-btn db-btn--success db-btn--lg">
                            <i class="fas fa-check"></i> Submit Vote
                        </button>
                        <a href="polls.php" class="db-btn db-btn--outline db-btn--lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Results Section -->
    <?php if ($can_see_results): ?>
        <div class="db-panel" style="animation-delay:120ms;">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-chart-bar"></i></div>
                    <h2><?php echo $is_closed ? 'Final Results' : 'Current Results'; ?></h2>
                </div>
                <span style="font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);">
                    <?php echo array_sum(array_column($options_arr, 'vote_count')); ?> total votes
                </span>
            </div>
            <div class="db-panel__body">
                <?php
                $total_vote_rows = max(1, array_sum(array_column($options_arr, 'vote_count')));
                $winner_ids      = array_column($winners, 'option_id');

                foreach ($options_arr as $option):
                    $percentage = ($option['vote_count'] / $total_vote_rows) * 100;
                    $is_winner  = in_array($option['option_id'], $winner_ids);
                ?>
                    <div class="result-item <?php echo $is_winner ? 'is-winner' : ''; ?>">
                        <div class="result-item__head">
                            <div class="result-item__label">
                                <?php if ($is_winner): ?><i class="fas fa-trophy"></i><?php endif; ?>
                                <?php echo htmlspecialchars($option['option_text']); ?>
                                <?php if ($option['user_voted'] > 0): ?>
                                    <span class="result-item__your-vote">Your vote</span>
                                <?php endif; ?>
                            </div>
                            <div class="result-item__stats">
                                <span class="result-item__pct"><?php echo number_format($percentage, 1); ?>%</span>
                                <span class="result-item__count"><?php echo $option['vote_count']; ?> votes</span>
                            </div>
                        </div>
                        <div class="result-track">
                            <div class="result-fill <?php echo $is_winner ? 'is-winner' : ''; ?>"
                                 style="width:<?php echo $percentage; ?>%"
                                 data-width="<?php echo $percentage; ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif ($poll['has_voted'] > 0): ?>
        <div class="db-alert db-alert--info" style="animation:dbFadeUp .35s ease both;">
            <i class="fas fa-lock"></i>
            <span>Results will be visible once the poll closes. Thanks for voting!</span>
        </div>
    <?php endif; ?>

    <!-- Back button -->
    <div style="text-align:center;margin-top:8px;">
        <a href="polls.php" class="db-btn db-btn--outline db-btn--lg">
            <i class="fas fa-arrow-left"></i> Back to All Polls
        </a>
    </div>

</div>

<script>
// Animate result bars on load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.result-fill').forEach(bar => {
        const target = bar.dataset.width;
        bar.style.width = '0%';
        requestAnimationFrame(() => {
            setTimeout(() => { bar.style.width = target + '%'; }, 80);
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>