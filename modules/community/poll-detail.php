<?php
// modules/community/poll-detail.php - View and vote on a specific poll
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

// Validate poll ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error_message'] = "Invalid poll ID.";
    header("Location: polls.php");
    exit();
}

$poll_id = intval($_GET['id']);

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

$current_resident_id = $resident_result->fetch_assoc()['resident_id'];

// Handle vote submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote'])) {
    $selected_options = isset($_POST['options']) ? $_POST['options'] : [];
    
    if (empty($selected_options)) {
        $_SESSION['error_message'] = "Please select at least one option.";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }
    
    // Check if poll is still active
    $check_poll = $conn->prepare("SELECT status, end_date FROM tbl_polls WHERE poll_id = ?");
    $check_poll->bind_param("i", $poll_id);
    $check_poll->execute();
    $poll_check = $check_poll->get_result()->fetch_assoc();
    
    if ($poll_check['status'] !== 'active' || ($poll_check['end_date'] && strtotime($poll_check['end_date']) <= time())) {
        $_SESSION['error_message'] = "This poll is no longer accepting votes.";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }
    
    // Check if resident already voted
    $check_vote = $conn->prepare("SELECT vote_id FROM tbl_poll_votes WHERE poll_id = ? AND resident_id = ?");
    $check_vote->bind_param("ii", $poll_id, $current_resident_id);
    $check_vote->execute();
    
    if ($check_vote->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "You have already voted in this poll.";
        header("Location: poll-detail.php?id=" . $poll_id);
        exit();
    }
    
    // Insert votes
    $conn->begin_transaction();
    
    try {
        $stmt = $conn->prepare("INSERT INTO tbl_poll_votes (poll_id, resident_id, option_id) VALUES (?, ?, ?)");
        
        foreach ($selected_options as $option_id) {
            $option_id = intval($option_id);
            $stmt->bind_param("iii", $poll_id, $current_resident_id, $option_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to record vote: " . $stmt->error);
            }
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

// Get poll details
$poll_query = "
    SELECT p.*, 
           CONCAT(r.first_name, ' ', r.last_name) as creator_name,
           (SELECT COUNT(DISTINCT resident_id) FROM tbl_poll_votes WHERE poll_id = p.poll_id) as total_votes,
           (SELECT COUNT(*) FROM tbl_poll_votes WHERE poll_id = p.poll_id AND resident_id = ?) as has_voted
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

// Get poll options with vote counts
$options_query = "
    SELECT po.*, 
           COUNT(pv.vote_id) as vote_count,
           (SELECT COUNT(*) FROM tbl_poll_votes WHERE option_id = po.option_id AND resident_id = ?) as user_voted
    FROM tbl_poll_options po
    LEFT JOIN tbl_poll_votes pv ON po.option_id = pv.option_id
    WHERE po.poll_id = ?
    GROUP BY po.option_id
    ORDER BY po.option_order ASC
";

$options_stmt = $conn->prepare($options_query);
$options_stmt->bind_param("ii", $current_resident_id, $poll_id);
$options_stmt->execute();
$options = $options_stmt->get_result();

// Check if poll is closed
$is_closed = $poll['status'] === 'closed' || ($poll['end_date'] && strtotime($poll['end_date']) <= time());

// Get winner(s) if poll is closed
$winners = [];
if ($is_closed) {
    $winners = getPollWinner($conn, $poll_id);
}

// Determine if user can see results
$can_see_results = false;
if ($poll['show_results'] === 'always') {
    $can_see_results = true;
} elseif ($poll['show_results'] === 'after_vote' && $poll['has_voted'] > 0) {
    $can_see_results = true;
} elseif ($is_closed) {
    $can_see_results = true;
}

include '../../includes/header.php';
?>

<style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #fafafa;
        color: #2c3e50;
    }
    
    .poll-detail-container {
        max-width: 800px;
        margin: 0 auto;
    }
    
    .poll-header {
        background: white;
        border-radius: 8px;
        padding: 2rem;
        margin-bottom: 2rem;
        border: 1px solid #e0e0e0;
    }
    
    .poll-title {
        font-size: 1.75rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #2c3e50;
    }
    
    .poll-description {
        color: #7f8c8d;
        line-height: 1.6;
        margin-bottom: 1.5rem;
    }
    
    .poll-meta {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
        padding-top: 1rem;
        border-top: 1px solid #e0e0e0;
        font-size: 0.875rem;
        color: #7f8c8d;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .poll-badge {
        display: inline-block;
        padding: 0.35rem 0.85rem;
        border-radius: 12px;
        font-size: 0.813rem;
        font-weight: 600;
        margin-bottom: 1rem;
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
    
    .voting-section, .results-section {
        background: white;
        border-radius: 8px;
        padding: 2rem;
        border: 1px solid #e0e0e0;
        margin-bottom: 2rem;
    }
    
    .section-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 1.5rem;
        color: #2c3e50;
    }
    
    .option-item {
        background: #f8f9fa;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .option-item:hover {
        border-color: #34495e;
        background: #ffffff;
    }
    
    .option-item.selected {
        border-color: #34495e;
        background: #e8f4f8;
    }
    
    .option-item input[type="checkbox"],
    .option-item input[type="radio"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }
    
    .option-text {
        flex: 1;
        font-weight: 500;
        color: #2c3e50;
    }
    
    .result-bar {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        border: 2px solid #e0e0e0;
    }
    
    .result-bar.winner {
        border-color: #f59e0b;
        background: #fef3c7;
    }
    
    .result-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
    }
    
    .result-text {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .result-votes {
        color: #7f8c8d;
        font-size: 0.875rem;
    }
    
    .progress-bar-container {
        height: 12px;
        background: #e0e0e0;
        border-radius: 6px;
        overflow: hidden;
    }
    
    .progress-bar {
        height: 100%;
        background: linear-gradient(90deg, #34495e 0%, #2c3e50 100%);
        transition: width 0.3s ease;
        border-radius: 6px;
    }
    
    .progress-bar.winner {
        background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
    }
    
    .winner-badge {
        background: #f59e0b;
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .btn-primary {
        background: #34495e;
        color: white;
        border: none;
        padding: 0.875rem 2rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
        font-size: 1rem;
    }
    
    .btn-primary:hover {
        background: #2c3e50;
        color: white;
    }
    
    .btn-secondary {
        background: #7f8c8d;
        color: white;
        border: none;
        padding: 0.875rem 2rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-secondary:hover {
        background: #6b7778;
        color: white;
    }
    
    .btn-outline {
        background: white;
        color: #34495e;
        border: 2px solid #34495e;
        padding: 0.875rem 2rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
    }
    
    .btn-outline:hover {
        background: #34495e;
        color: white;
    }
    
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 6px;
        margin-bottom: 2rem;
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
    
    .winner-banner {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border-left: 4px solid #f59e0b;
        padding: 1rem 1.25rem;
        border-radius: 8px;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1rem;
        color: #78350f;
    }
    
    .winner-banner i {
        color: #f59e0b;
        font-size: 1.5rem;
    }
    
    .winner-banner strong {
        color: #92400e;
    }
    
    .back-link {
        color: #34495e;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }
    
    .back-link:hover {
        color: #2c3e50;
    }
</style>

<div class="container-fluid py-4">
    <div class="poll-detail-container">
        <a href="polls.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Polls
        </a>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <!-- Poll Header -->
        <div class="poll-header">
            <?php if ($poll['has_voted'] > 0): ?>
                <span class="poll-badge voted"><i class="fas fa-check me-1"></i>You Voted</span>
            <?php elseif ($is_closed): ?>
                <span class="poll-badge ended"><i class="fas fa-lock me-1"></i>Closed</span>
            <?php else: ?>
                <span class="poll-badge active"><i class="fas fa-circle me-1"></i>Active</span>
            <?php endif; ?>

            <h1 class="poll-title"><?php echo htmlspecialchars($poll['question']); ?></h1>
            
            <?php if (!empty($poll['description'])): ?>
                <p class="poll-description"><?php echo nl2br(htmlspecialchars($poll['description'])); ?></p>
            <?php endif; ?>

            <div class="poll-meta">
                <div class="meta-item">
                    <i class="fas fa-user"></i>
                    <span>By <?php echo htmlspecialchars($poll['creator_name']); ?></span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-users"></i>
                    <span><?php echo $poll['total_votes']; ?> total votes</span>
                </div>
                <div class="meta-item">
                    <i class="fas fa-clock"></i>
                    <span>Posted <?php echo date('M d, Y', strtotime($poll['created_at'])); ?></span>
                </div>
                <?php if ($poll['end_date']): ?>
                    <div class="meta-item">
                        <i class="fas fa-calendar"></i>
                        <span>
                            <?php echo $is_closed ? 'Ended' : 'Ends'; ?> 
                            <?php echo date('M d, Y g:i A', strtotime($poll['end_date'])); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_closed && !empty($winners)): ?>
            <div class="winner-banner">
                <i class="fas fa-trophy"></i>
                <span>
                    <?php if (count($winners) === 1): ?>
                        Poll Winner: <strong><?php echo htmlspecialchars($winners[0]['option_text']); ?></strong> 
                        with <?php echo $winners[0]['vote_count']; ?> votes
                    <?php else: ?>
                        This poll ended in a tie between: 
                        <strong>
                        <?php 
                        $winner_texts = array_map(function($w) { 
                            return htmlspecialchars($w['option_text']); 
                        }, $winners);
                        echo implode(', ', $winner_texts);
                        ?>
                        </strong>
                        with <?php echo $winners[0]['vote_count']; ?> votes each
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Voting Section -->
        <?php if (!$is_closed && $poll['has_voted'] == 0): ?>
            <div class="voting-section">
                <h2 class="section-title">Cast Your Vote</h2>
                <?php if ($poll['allow_multiple']): ?>
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        You can select multiple options in this poll.
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <?php 
                    $options->data_seek(0); // Reset pointer
                    while ($option = $options->fetch_assoc()): 
                    ?>
                        <label class="option-item">
                            <input type="<?php echo $poll['allow_multiple'] ? 'checkbox' : 'radio'; ?>" 
                                   name="options[]" 
                                   value="<?php echo $option['option_id']; ?>"
                                   onchange="this.closest('.option-item').classList.toggle('selected', this.checked)">
                            <span class="option-text"><?php echo htmlspecialchars($option['option_text']); ?></span>
                        </label>
                    <?php endwhile; ?>
                    
                    <div class="mt-4">
                        <button type="submit" name="submit_vote" class="btn-primary">
                            <i class="fas fa-check me-2"></i>Submit Vote
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Results Section -->
        <?php if ($can_see_results): ?>
            <div class="results-section">
                <h2 class="section-title">
                    <?php echo $is_closed ? 'Final Results' : 'Current Results'; ?>
                </h2>
                
                <?php 
                $options->data_seek(0); // Reset pointer
                $total_votes = max(1, $poll['total_votes']); // Avoid division by zero
                
                // Get winner IDs for highlighting
                $winner_ids = array_map(function($w) { return $w['option_id']; }, $winners);
                
                while ($option = $options->fetch_assoc()): 
                    $percentage = ($option['vote_count'] / $total_votes) * 100;
                    $is_winner = in_array($option['option_id'], $winner_ids);
                ?>
                    <div class="result-bar <?php echo $is_winner ? 'winner' : ''; ?>">
                        <div class="result-header">
                            <div class="result-text">
                                <?php if ($is_winner): ?>
                                    <i class="fas fa-trophy" style="color: #f59e0b;"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($option['option_text']); ?>
                                <?php if ($option['user_voted'] > 0): ?>
                                    <span class="badge" style="background: #d1ecf1; color: #0c5460; padding: 0.25rem 0.5rem; border-radius: 12px; font-size: 0.75rem;">
                                        Your vote
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="result-votes">
                                <strong><?php echo $option['vote_count']; ?></strong> votes 
                                (<?php echo number_format($percentage, 1); ?>%)
                            </div>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar <?php echo $is_winner ? 'winner' : ''; ?>" 
                                 style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php elseif ($poll['has_voted'] > 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-lock me-2"></i>
                Results will be visible when the poll closes.
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="text-center mt-4">
            <a href="polls.php" class="btn-outline">
                <i class="fas fa-arrow-left me-2"></i>Back to All Polls
            </a>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>