<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';
require_once __DIR__ . '/check-poll-expiry.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

// Auto-close expired polls
checkAndCloseExpiredPolls($conn);

$page_title = 'Manage Polls & Surveys';

// Handle AJAX actions for updates only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'edit') {
        $poll_id = intval($_POST['poll_id']);
        $question = trim($_POST['question']);
        $description = trim($_POST['description']);
        $status = $_POST['status'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
        
        if (empty($question)) {
            echo json_encode(['success' => false, 'message' => 'Question is required']);
            exit();
        }
        
        if ($end_date) {
            $stmt = $conn->prepare("UPDATE tbl_polls SET question = ?, description = ?, status = ?, end_date = ?, allow_multiple = ? WHERE poll_id = ?");
            $stmt->bind_param("ssssii", $question, $description, $status, $end_date, $allow_multiple, $poll_id);
        } else {
            $stmt = $conn->prepare("UPDATE tbl_polls SET question = ?, description = ?, status = ?, end_date = NULL, allow_multiple = ? WHERE poll_id = ?");
            $stmt->bind_param("sssii", $question, $description, $status, $allow_multiple, $poll_id);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Poll updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update poll']);
        }
        exit();
    }
    
    if ($_POST['action'] === 'close') {
        $poll_id = intval($_POST['poll_id']);
        $stmt = $conn->prepare("UPDATE tbl_polls SET status = 'closed' WHERE poll_id = ?");
        $stmt->bind_param("i", $poll_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Poll closed successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to close poll']);
        }
        exit();
    }
    
    if ($_POST['action'] === 'delete') {
        $poll_id = intval($_POST['poll_id']);
        
        // Delete votes first
        $conn->query("DELETE FROM tbl_poll_votes WHERE poll_id = $poll_id");
        
        // Delete options
        $conn->query("DELETE FROM tbl_poll_options WHERE poll_id = $poll_id");
        
        // Delete poll
        $stmt = $conn->prepare("DELETE FROM tbl_polls WHERE poll_id = ?");
        $stmt->bind_param("i", $poll_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Poll deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete poll']);
        }
        exit();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];
$types = '';

if ($status_filter !== 'all') {
    $where_conditions[] = "p.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($search)) {
    $where_conditions[] = "(p.question LIKE ? OR p.description LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get polls
$query = "
    SELECT p.*,
           CONCAT(r.first_name, ' ', r.last_name) as created_by_name,
           COUNT(DISTINCT v.vote_id) as total_votes
    FROM tbl_polls p
    LEFT JOIN tbl_residents r ON p.created_by = r.resident_id
    LEFT JOIN tbl_poll_votes v ON p.poll_id = v.poll_id
    $where_clause
    GROUP BY p.poll_id
    ORDER BY p.created_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$polls = $stmt->get_result();

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT p.poll_id) as total_polls,
        COUNT(DISTINCT v.vote_id) as total_votes,
        COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.poll_id END) as active_polls,
        COUNT(DISTINCT CASE WHEN p.status = 'closed' THEN p.poll_id END) as closed_polls
    FROM tbl_polls p
    LEFT JOIN tbl_poll_votes v ON p.poll_id = v.poll_id
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

include '../../../includes/header.php';
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; color: #1a1a1a; }
    .page-header { background: white; padding: 2rem; margin-bottom: 2rem; border-bottom: 1px solid #e5e7eb; }
    .page-header h1 { font-size: 1.75rem; font-weight: 600; margin-bottom: 0.5rem; color: #1a1a1a; }
    .breadcrumb { font-size: 0.875rem; color: #6b7280; }
    .breadcrumb a { color: #3b82f6; text-decoration: none; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .stat-card { background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; }
    .stat-label { font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem; }
    .stat-value { font-size: 2rem; font-weight: 600; color: #1a1a1a; }
    .filters-section { background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 2rem; }
    .filters-grid { display: grid; grid-template-columns: 2fr 1fr auto; gap: 1rem; margin-top: 1rem; }
    .form-group label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem; color: #374151; }
    .form-control { width: 100%; padding: 0.625rem 0.875rem; font-size: 0.875rem; border: 1px solid #d1d5db; border-radius: 6px; background: white; transition: all 0.15s; }
    .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
    .btn { padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; text-decoration: none; display: inline-block; }
    .btn-primary { background: #3b82f6; color: white; }
    .btn-primary:hover { background: #2563eb; }
    .btn-success { background: #10b981; color: white; }
    .btn-success:hover { background: #059669; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-secondary:hover { background: #4b5563; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-danger:hover { background: #dc2626; }
    .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
    .btn-outline { background: white; border: 1px solid #d1d5db; color: #374151; }
    .btn-outline:hover { background: #f9fafb; border-color: #9ca3af; }
    .action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .polls-list { background: white; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden; }
    .poll-item { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; }
    .poll-item:last-child { border-bottom: none; }
    .poll-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; }
    .poll-question { font-size: 1.125rem; font-weight: 600; color: #1a1a1a; margin-bottom: 0.5rem; }
    .poll-description { color: #6b7280; font-size: 0.875rem; line-height: 1.5; }
    .poll-meta { display: flex; gap: 1.5rem; margin: 1rem 0; font-size: 0.875rem; color: #6b7280; flex-wrap: wrap; }
    .options-preview { background: #f9fafb; padding: 1rem; border-radius: 6px; margin: 1rem 0; }
    .option-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; margin-bottom: 0.5rem; background: white; border-radius: 4px; font-size: 0.875rem; }
    .option-item:last-child { margin-bottom: 0; }
    .option-text { color: #1a1a1a; }
    .option-votes { color: #6b7280; font-weight: 500; }
    .poll-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; }
    .poll-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .badge { display: inline-block; padding: 0.25rem 0.75rem; font-size: 0.75rem; font-weight: 500; border-radius: 12px; }
    .badge-active { background: #d1fae5; color: #065f46; }
    .badge-closed { background: #fee2e2; color: #991b1b; }
    .badge-draft { background: #fef3c7; color: #92400e; }
    .badge-winner { background: #fde68a; color: #78350f; border: 2px solid #f59e0b; }
    .empty-state { text-align: center; padding: 3rem; color: #6b7280; }
    .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; }
    .winner-banner { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-left: 4px solid #f59e0b; padding: 0.75rem 1rem; border-radius: 6px; margin: 0.5rem 0; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 600; color: #78350f; }
    
    /* MODAL STYLES */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
    .modal-overlay.active { display: flex; }
    .modal-content { background: white; border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); animation: modalSlideIn 0.2s ease-out; }
    .modal-content.large { max-width: 800px; }
    @keyframes modalSlideIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    .modal-header { padding: 1.5rem; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; }
    .modal-header h3 { margin: 0; font-size: 1.25rem; font-weight: 600; color: #1a1a1a; }
    .modal-close { background: none; border: none; font-size: 1.5rem; color: #6b7280; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.15s; }
    .modal-close:hover { background: #f3f4f6; color: #1a1a1a; }
    .modal-body { padding: 1.5rem; max-height: 70vh; overflow-y: auto; }
    .modal-body p { color: #4b5563; line-height: 1.6; margin-bottom: 1rem; }
    .modal-footer { padding: 1.5rem; border-top: 1px solid #e5e7eb; display: flex; gap: 0.75rem; justify-content: flex-end; }
    
    .detail-section { margin-bottom: 1.5rem; }
    .detail-label { font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; }
    .detail-value { font-size: 0.875rem; color: #1a1a1a; line-height: 1.6; }
    .detail-value.large { font-size: 1.125rem; font-weight: 600; }
    .options-list { list-style: none; padding: 0; margin: 0; }
    .options-list li { padding: 0.75rem 1rem; background: #f9fafb; border-radius: 6px; margin-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center; }
    .edit-form .form-group { margin-bottom: 1.5rem; }
    .edit-form textarea { min-height: 100px; resize: vertical; }
    .row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1rem; }
    
    @media (max-width: 768px) {
        .row { grid-template-columns: 1fr; }
        .filters-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="page-header">
    <h1>Manage Polls & Surveys</h1>
    <div class="breadcrumb">
        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php">Dashboard</a> / 
        <span>Polls Management</span>
    </div>
</div>

<div class="container-fluid">
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Polls</div>
            <div class="stat-value"><?php echo number_format($stats['total_polls']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Votes</div>
            <div class="stat-value"><?php echo number_format($stats['total_votes']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active Polls</div>
            <div class="stat-value"><?php echo number_format($stats['active_polls']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Closed Polls</div>
            <div class="stat-value"><?php echo number_format($stats['closed_polls']); ?></div>
        </div>
    </div>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div></div>
        <a href="create-poll.php" class="btn btn-success">
            <i class="fas fa-plus"></i> Create New Poll
        </a>
    </div>
    
    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-grid">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search polls..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Polls List -->
    <div class="polls-list">
        <?php if ($polls->num_rows > 0): ?>
            <?php while ($poll = $polls->fetch_assoc()): 
                // Get poll options
                $options_query = "SELECT * FROM tbl_poll_options WHERE poll_id = ? ORDER BY option_order";
                $options_stmt = $conn->prepare($options_query);
                $options_stmt->bind_param("i", $poll['poll_id']);
                $options_stmt->execute();
                $options_result = $options_stmt->get_result();
                
                // Check if poll is closed (either manually or by expiry)
                $is_closed = $poll['status'] === 'closed' || ($poll['end_date'] && strtotime($poll['end_date']) <= time());
                
                // Get winner(s) if poll is closed
                $winners = [];
                if ($is_closed) {
                    $winners = getPollWinner($conn, $poll['poll_id']);
                }
            ?>
                <div class="poll-item">
                    <div class="poll-header">
                        <div style="flex: 1;">
                            <h3 class="poll-question"><?php echo htmlspecialchars($poll['question']); ?></h3>
                            <?php if ($poll['description']): ?>
                                <p class="poll-description"><?php echo htmlspecialchars($poll['description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="badge badge-<?php echo $poll['status']; ?>">
                            <?php echo ucfirst($poll['status']); ?>
                        </span>
                    </div>
                    
                    <div class="poll-meta">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($poll['created_by_name']); ?></span>
                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($poll['created_at'])); ?></span>
                        <span><i class="fas fa-vote-yea"></i> <?php echo $poll['total_votes']; ?> votes</span>
                        <?php if ($poll['end_date']): ?>
                            <span><i class="fas fa-clock"></i> 
                                <?php echo $is_closed ? 'Ended:' : 'Ends:'; ?> 
                                <?php echo date('M d, Y g:i A', strtotime($poll['end_date'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($is_closed && !empty($winners)): ?>
                        <div class="winner-banner">
                            <i class="fas fa-trophy"></i>
                            <span>
                                <?php if (count($winners) === 1): ?>
                                    Winner: <?php echo htmlspecialchars($winners[0]['option_text']); ?> 
                                    (<?php echo $winners[0]['vote_count']; ?> votes)
                                <?php else: ?>
                                    Tie between: 
                                    <?php 
                                    $winner_texts = array_map(function($w) { 
                                        return htmlspecialchars($w['option_text']); 
                                    }, $winners);
                                    echo implode(', ', $winner_texts);
                                    ?>
                                    (<?php echo $winners[0]['vote_count']; ?> votes each)
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="options-preview">
                        <?php while ($option = $options_result->fetch_assoc()): 
                            // Get vote count for this option
                            $vote_count_query = "SELECT COUNT(*) as count FROM tbl_poll_votes WHERE option_id = ?";
                            $vote_count_stmt = $conn->prepare($vote_count_query);
                            $vote_count_stmt->bind_param("i", $option['option_id']);
                            $vote_count_stmt->execute();
                            $vote_count_result = $vote_count_stmt->get_result();
                            $vote_count = $vote_count_result->fetch_assoc()['count'];
                            
                            // Check if this is a winning option
                            $is_winner = false;
                            foreach ($winners as $winner) {
                                if ($winner['option_id'] === $option['option_id']) {
                                    $is_winner = true;
                                    break;
                                }
                            }
                        ?>
                            <div class="option-item" <?php echo $is_winner ? 'style="background: #fef3c7; border: 1px solid #f59e0b;"' : ''; ?>>
                                <span class="option-text">
                                    <?php if ($is_winner): ?>
                                        <i class="fas fa-trophy" style="color: #f59e0b; margin-right: 0.5rem;"></i>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($option['option_text']); ?>
                                </span>
                                <span class="option-votes"><?php echo $vote_count; ?> votes</span>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    
                    <div class="poll-footer">
                        <div></div>
                        <div class="poll-actions">
                            <button onclick="showViewModal(<?php echo $poll['poll_id']; ?>)" class="btn btn-secondary btn-sm">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <button onclick="showEditModal(<?php echo $poll['poll_id']; ?>)" class="btn btn-primary btn-sm">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php if ($poll['status'] === 'active'): ?>
                                <button onclick="showCloseModal(<?php echo $poll['poll_id']; ?>, '<?php echo htmlspecialchars($poll['question'], ENT_QUOTES); ?>')" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-lock"></i> Close
                                </button>
                            <?php endif; ?>
                            <button onclick="showDeleteModal(<?php echo $poll['poll_id']; ?>, '<?php echo htmlspecialchars($poll['question'], ENT_QUOTES); ?>')" class="btn btn-danger btn-sm">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-poll"></i>
                <p>No polls found.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Poll Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3>Poll Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div class="detail-section">
                <div class="detail-label">Question</div>
                <div class="detail-value large" id="viewQuestion"></div>
            </div>
            
            <div class="detail-section">
                <div class="detail-label">Description</div>
                <div class="detail-value" id="viewDescription"></div>
            </div>
            
            <div class="detail-section">
                <div class="detail-label">Options & Votes</div>
                <ul class="options-list" id="viewOptions"></ul>
            </div>
            
            <div class="row">
                <div class="detail-section">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="viewStatus"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Total Votes</div>
                    <div class="detail-value" id="viewTotalVotes"></div>
                </div>
            </div>
            
            <div class="row">
                <div class="detail-section">
                    <div class="detail-label">Created By</div>
                    <div class="detail-value" id="viewCreatedBy"></div>
                </div>
                <div class="detail-section">
                    <div class="detail-label">Created At</div>
                    <div class="detail-value" id="viewCreatedAt"></div>
                </div>
            </div>
            
            <div class="detail-section" id="viewEndDateSection" style="display: none;">
                <div class="detail-label">End Date & Time</div>
                <div class="detail-value" id="viewEndDate"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Edit Poll Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content large">
        <div class="modal-header">
            <h3>Edit Poll</h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editPollForm" class="edit-form">
                <input type="hidden" id="editPollId" name="poll_id">
                
                <div class="form-group">
                    <label>Question *</label>
                    <input type="text" id="editQuestion" name="question" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="editDescription" name="description" class="form-control"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Status *</label>
                    <select id="editStatus" name="status" class="form-control" required>
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>End Date & Time</label>
                    <input type="datetime-local" id="editEndDate" name="end_date" class="form-control">
                    <div class="help-text" style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
                        Poll will automatically close at this date and time
                    </div>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" id="editAllowMultiple" name="allow_multiple" value="1">
                        <span>Allow multiple selections</span>
                    </label>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeEditModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveEditPoll()">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </div>
    </div>
</div>

<!-- Close Poll Modal -->
<div class="modal-overlay" id="closeModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Close Poll</h3>
            <button class="modal-close" onclick="closeCloseModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to close "<strong id="closePollTitle"></strong>"?</p>
            <p>Once closed, residents will no longer be able to vote on this poll.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeCloseModal()">Cancel</button>
            <button class="btn btn-secondary" onclick="confirmClosePoll()">
                <i class="fas fa-lock"></i> Close Poll
            </button>
        </div>
    </div>
</div>

<!-- Delete Poll Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Delete Poll</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to permanently delete "<strong id="deletePollTitle"></strong>"?</p>
            <p><strong>This action cannot be undone.</strong> All votes and data associated with this poll will be permanently removed.</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn btn-danger" onclick="confirmDeletePoll()">
                <i class="fas fa-trash"></i> Delete Poll
            </button>
        </div>
    </div>
</div>

<script>
let currentPollId = null;

// View Modal Functions
function showViewModal(pollId) {
    currentPollId = pollId;
    document.getElementById('viewModal').classList.add('active');
    loadPollDetails(pollId);
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}

async function loadPollDetails(pollId) {
    try {
        const response = await fetch('get-poll-details.php?id=' + pollId);
        const data = await response.json();
        
        if (data.success) {
            const poll = data.poll;
            
            document.getElementById('viewQuestion').textContent = poll.question;
            document.getElementById('viewDescription').textContent = poll.description || 'No description';
            document.getElementById('viewStatus').innerHTML = `<span class="badge badge-${poll.status}">${poll.status.charAt(0).toUpperCase() + poll.status.slice(1)}</span>`;
            document.getElementById('viewTotalVotes').textContent = poll.total_votes + ' votes';
            document.getElementById('viewCreatedBy').textContent = poll.created_by_name;
            document.getElementById('viewCreatedAt').textContent = new Date(poll.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            
            if (poll.end_date) {
                document.getElementById('viewEndDateSection').style.display = 'block';
                const endDate = new Date(poll.end_date);
                document.getElementById('viewEndDate').textContent = endDate.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit'
                });
            } else {
                document.getElementById('viewEndDateSection').style.display = 'none';
            }
            
            const optionsList = document.getElementById('viewOptions');
            optionsList.innerHTML = '';
            poll.options.forEach(opt => {
                const li = document.createElement('li');
                li.innerHTML = `<span>${escapeHtml(opt.option_text)}</span><span><strong>${opt.vote_count}</strong> votes</span>`;
                optionsList.appendChild(li);
            });
        } else {
            alert('Error: ' + data.message);
            closeViewModal();
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred while loading poll details');
        closeViewModal();
    }
}

// Edit Modal Functions
function showEditModal(pollId) {
    currentPollId = pollId;
    document.getElementById('editModal').classList.add('active');
    
    fetch('get-poll-details.php?id=' + pollId)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const poll = data.poll;
            
            document.getElementById('editPollId').value = poll.poll_id;
            document.getElementById('editQuestion').value = poll.question;
            document.getElementById('editDescription').value = poll.description || '';
            document.getElementById('editStatus').value = poll.status;
            
            // Format datetime for datetime-local input
            if (poll.end_date) {
                const endDate = new Date(poll.end_date);
                const year = endDate.getFullYear();
                const month = String(endDate.getMonth() + 1).padStart(2, '0');
                const day = String(endDate.getDate()).padStart(2, '0');
                const hours = String(endDate.getHours()).padStart(2, '0');
                const minutes = String(endDate.getMinutes()).padStart(2, '0');
                document.getElementById('editEndDate').value = `${year}-${month}-${day}T${hours}:${minutes}`;
            } else {
                document.getElementById('editEndDate').value = '';
            }
            
            document.getElementById('editAllowMultiple').checked = poll.allow_multiple == 1;
        } else {
            alert('Error: ' + data.message);
            closeEditModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while loading poll data');
        closeEditModal();
    });
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

function saveEditPoll() {
    const formData = new FormData(document.getElementById('editPollForm'));
    formData.append('action', 'edit');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving changes');
    });
}

// Close Poll Modal Functions
function showCloseModal(pollId, pollTitle) {
    currentPollId = pollId;
    document.getElementById('closePollTitle').textContent = pollTitle;
    document.getElementById('closeModal').classList.add('active');
}

function closeCloseModal() {
    document.getElementById('closeModal').classList.remove('active');
}

function confirmClosePoll() {
    if (!currentPollId) return;
    
    const formData = new FormData();
    formData.append('action', 'close');
    formData.append('poll_id', currentPollId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            closeCloseModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
        closeCloseModal();
    });
}

// Delete Poll Modal Functions
function showDeleteModal(pollId, pollTitle) {
    currentPollId = pollId;
    document.getElementById('deletePollTitle').textContent = pollTitle;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

function confirmDeletePoll() {
    if (!currentPollId) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('poll_id', currentPollId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            closeDeleteModal();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
        closeDeleteModal();
    });
}

// Utility function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on overlay click
document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

document.getElementById('closeModal').addEventListener('click', function(e) {
    if (e.target === this) closeCloseModal();
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeViewModal();
        closeEditModal();
        closeCloseModal();
        closeDeleteModal();
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>