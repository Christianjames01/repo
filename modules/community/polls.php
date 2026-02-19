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

// Auto-close expired polls
checkAndCloseExpiredPolls($conn);

$user_id = getCurrentUserId();
$page_title = "Polls & Surveys";

// Handle poll voting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote'])) {
    $poll_id = intval($_POST['poll_id']);
    $selected_options = isset($_POST['options']) ? $_POST['options'] : [];
    
    if (empty($selected_options)) {
        $_SESSION['error_message'] = "Please select at least one option.";
        header("Location: polls.php");
        exit();
    }
    
    // Get resident_id from user_id
    $resident_query = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
    $resident_query->bind_param("i", $user_id);
    $resident_query->execute();
    $resident_result = $resident_query->get_result();
    
    if ($resident_result->num_rows == 0) {
        $_SESSION['error_message'] = "User not found.";
        header("Location: polls.php");
        exit();
    }
    
    $resident_id = $resident_result->fetch_assoc()['resident_id'];
    
    // Check if resident already voted
    $check = $conn->prepare("SELECT vote_id FROM tbl_poll_votes WHERE poll_id = ? AND resident_id = ?");
    $check->bind_param("ii", $poll_id, $resident_id);
    $check->execute();
    
    if ($check->get_result()->num_rows == 0) {
        $conn->begin_transaction();
        
        try {
            // Insert votes for each selected option
            $stmt = $conn->prepare("INSERT INTO tbl_poll_votes (poll_id, resident_id, option_id) VALUES (?, ?, ?)");
            
            foreach ($selected_options as $option_id) {
                $option_id = intval($option_id);
                $stmt->bind_param("iii", $poll_id, $resident_id, $option_id);
                $stmt->execute();
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Your vote has been recorded. Thank you!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error recording vote: " . $e->getMessage();
        }
        
        header("Location: polls.php");
        exit();
    } else {
        $_SESSION['error_message'] = "You have already voted in this poll.";
        header("Location: polls.php");
        exit();
    }
}

// Get resident_id from user_id for queries
$resident_query = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");
$resident_query->bind_param("i", $user_id);
$resident_query->execute();
$resident_result = $resident_query->get_result();

if ($resident_result->num_rows > 0) {
    $current_resident_id = $resident_result->fetch_assoc()['resident_id'];
} else {
    $current_resident_id = 0;
}

// Get filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';

// Build query based on filter
$where_clause = "";
if ($status_filter === 'active') {
    $where_clause = "WHERE p.status = 'active' AND (p.end_date IS NULL OR p.end_date > NOW())";
} elseif ($status_filter === 'closed') {
    $where_clause = "WHERE p.status = 'closed' OR (p.end_date IS NOT NULL AND p.end_date <= NOW())";
} elseif ($status_filter === 'voted') {
    $where_clause = "WHERE EXISTS (SELECT 1 FROM tbl_poll_votes pv WHERE pv.poll_id = p.poll_id AND pv.resident_id = $current_resident_id)";
} else {
    $where_clause = "WHERE p.status IN ('active', 'closed')";
}

// Get polls
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
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #fafafa;
        color: #2c3e50;
    }
    
    .polls-header {
        background: white;
        color: #2c3e50;
        padding: 2rem;
        border-radius: 4px;
        margin-bottom: 2rem;
        border: 1px solid #e0e0e0;
    }
    
    .polls-header h1 {
        margin-bottom: 0.5rem;
        font-size: 1.75rem;
        font-weight: 600;
    }
    
    .polls-header p {
        margin-bottom: 0;
        color: #7f8c8d;
    }
    
    .filter-tabs {
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        padding: 1rem;
        margin-bottom: 2rem;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .filter-tab {
        padding: 0.5rem 1.25rem;
        border: 2px solid #e0e0e0;
        border-radius: 20px;
        background: white;
        color: #7f8c8d;
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .filter-tab:hover {
        border-color: #34495e;
        color: #34495e;
    }
    
    .filter-tab.active {
        background: #34495e;
        border-color: #34495e;
        color: white;
    }
    
    .poll-card {
        background: white;
        border-radius: 4px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        border: 1px solid #e0e0e0;
        transition: all 0.3s;
        cursor: pointer;
    }
    
    .poll-card:hover {
        box-shadow: 0 2px 12px rgba(0,0,0,0.1);
        border-color: #34495e;
    }
    
    .poll-card.clickable:hover .poll-title {
        color: #34495e;
    }
    
    .poll-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .poll-badge.active {
        background: #d4edda;
        color: #155724;
    }
    
    .poll-badge.ended {
        background: #e0e0e0;
        color: #5a6268;
    }
    
    .poll-badge.voted {
        background: #d1ecf1;
        color: #0c5460;
    }
    
    .poll-title {
        font-size: 1.125rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #2c3e50;
        transition: color 0.2s;
    }
    
    .poll-stats {
        display: flex;
        gap: 1.5rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e0e0e0;
        flex-wrap: wrap;
    }
    
    .stat-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #7f8c8d;
        font-size: 0.875rem;
    }
    
    .btn-primary {
        background: #34495e;
        color: white;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 4px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-primary:hover {
        background: #2c3e50;
        color: white;
    }
    
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 4px;
        margin-bottom: 1.5rem;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .alert-info {
        background: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }
    
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #7f8c8d;
    }
    
    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    
    .poll-preview {
        background: #f8f9fa;
        border-radius: 4px;
        padding: 1rem;
        margin-top: 1rem;
    }
    
    .option-preview {
        padding: 0.5rem;
        margin-bottom: 0.5rem;
        background: white;
        border-radius: 4px;
        font-size: 0.875rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .option-preview:last-child {
        margin-bottom: 0;
    }
    
    /* Winner Banner Styles */
    .winner-banner {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border-left: 4px solid #f59e0b;
        padding: 0.875rem 1rem;
        border-radius: 6px;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.875rem;
        color: #78350f;
    }
    
    .winner-banner i {
        color: #f59e0b;
        font-size: 1.25rem;
    }
    
    .winner-banner strong {
        color: #92400e;
    }
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="polls-header">
        <h1><i class="fas fa-poll-h me-2"></i>Polls & Surveys</h1>
        <p>Share your voice and help shape our community</p>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="?status=active" class="filter-tab <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle me-1"></i> Active Polls
        </a>
        <a href="?status=voted" class="filter-tab <?php echo $status_filter === 'voted' ? 'active' : ''; ?>">
            <i class="fas fa-vote-yea me-1"></i> My Votes
        </a>
        <a href="?status=closed" class="filter-tab <?php echo $status_filter === 'closed' ? 'active' : ''; ?>">
            <i class="fas fa-lock me-1"></i> Closed Polls
        </a>
        <a href="?status=all" class="filter-tab <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
            <i class="fas fa-list me-1"></i> All Polls
        </a>
    </div>

    <!-- Polls List -->
    <div class="row">
        <div class="col-12">
            <?php if ($polls->num_rows > 0): ?>
                <?php while ($poll = $polls->fetch_assoc()): 
                    // Get poll options preview
                    $options_query = "SELECT * FROM tbl_poll_options WHERE poll_id = ? ORDER BY option_order LIMIT 3";
                    $options_stmt = $conn->prepare($options_query);
                    $options_stmt->bind_param("i", $poll['poll_id']);
                    $options_stmt->execute();
                    $options = $options_stmt->get_result();
                    
                    // Check if poll is closed
                    $is_closed = $poll['status'] === 'closed' || ($poll['end_date'] && strtotime($poll['end_date']) <= time());
                    
                    // Get winner(s) if poll is closed
                    $winners = [];
                    if ($is_closed) {
                        $winners = getPollWinner($conn, $poll['poll_id']);
                    }
                ?>
                    <div class="poll-card clickable" onclick="window.location.href='poll-detail.php?id=<?php echo $poll['poll_id']; ?>'">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div style="flex: 1;">
                                <h5 class="poll-title"><?php echo htmlspecialchars($poll['question']); ?></h5>
                                <?php if (!empty($poll['description'])): ?>
                                    <p class="text-muted mb-0" style="font-size: 0.875rem;">
                                        <?php echo htmlspecialchars(substr($poll['description'], 0, 150)) . (strlen($poll['description']) > 150 ? '...' : ''); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div style="margin-left: 1rem;">
                                <?php if ($poll['has_voted'] > 0): ?>
                                    <span class="poll-badge voted"><i class="fas fa-check me-1"></i>Voted</span>
                                <?php elseif ($is_closed): ?>
                                    <span class="poll-badge ended"><i class="fas fa-lock me-1"></i>Closed</span>
                                <?php else: ?>
                                    <span class="poll-badge active"><i class="fas fa-circle me-1"></i>Active</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($is_closed && !empty($winners)): ?>
                            <div class="winner-banner">
                                <i class="fas fa-trophy"></i>
                                <span>
                                    <?php if (count($winners) === 1): ?>
                                        Winner: <strong><?php echo htmlspecialchars($winners[0]['option_text']); ?></strong> 
                                        (<?php echo $winners[0]['vote_count']; ?> votes)
                                    <?php else: ?>
                                        Tie between: 
                                        <strong>
                                        <?php 
                                        $winner_texts = array_map(function($w) { 
                                            return htmlspecialchars($w['option_text']); 
                                        }, $winners);
                                        echo implode(', ', $winner_texts);
                                        ?>
                                        </strong>
                                        (<?php echo $winners[0]['vote_count']; ?> votes each)
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <!-- Options Preview -->
                        <div class="poll-preview">
                            <?php 
                            $option_count = 0;
                            while ($option = $options->fetch_assoc()): 
                                $option_count++;
                                // Get vote count for preview
                                $vote_query = "SELECT COUNT(*) as count FROM tbl_poll_votes WHERE option_id = ?";
                                $vote_stmt = $conn->prepare($vote_query);
                                $vote_stmt->bind_param("i", $option['option_id']);
                                $vote_stmt->execute();
                                $vote_count = $vote_stmt->get_result()->fetch_assoc()['count'];
                            ?>
                                <div class="option-preview">
                                    <span><?php echo htmlspecialchars($option['option_text']); ?></span>
                                    <span class="text-muted"><?php echo $vote_count; ?> votes</span>
                                </div>
                            <?php endwhile; ?>
                            <?php if ($option_count >= 3): ?>
                                <div class="text-center mt-2">
                                    <small class="text-muted">Click to view all options and vote</small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="poll-stats">
                            <div class="stat-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo $poll['total_votes']; ?> total votes</span>
                            </div>
                            <?php if ($poll['end_date']): ?>
                                <div class="stat-item">
                                    <i class="fas fa-calendar"></i>
                                    <span>
                                        <?php echo $is_closed ? 'Ended' : 'Ends'; ?> 
                                        <?php echo date('M d, Y g:i A', strtotime($poll['end_date'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="stat-item">
                                <i class="fas fa-user"></i>
                                <span>By <?php echo htmlspecialchars($poll['creator_name']); ?></span>
                            </div>
                            <div class="stat-item">
                                <i class="fas fa-clock"></i>
                                <span>Posted <?php echo date('M d, Y', strtotime($poll['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php 
                    switch($status_filter) {
                        case 'active':
                            echo "No active polls at the moment. Check back later!";
                            break;
                        case 'voted':
                            echo "You haven't voted in any polls yet. Check out the active polls!";
                            break;
                        case 'closed':
                            echo "No closed polls yet.";
                            break;
                        default:
                            echo "No polls available at the moment.";
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>