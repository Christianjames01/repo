<?php
// modules/community/admin/check-poll-expiry.php
// Automatically check and update expired polls

/**
 * Check and update expired polls
 * This function is called when viewing polls to ensure status is current
 */
function checkAndUpdateExpiredPolls($conn) {
    $query = "
        UPDATE tbl_polls 
        SET status = 'closed' 
        WHERE status = 'active' 
        AND end_date IS NOT NULL 
        AND end_date <= NOW()
    ";
    $conn->query($query);
}

/**
 * Alias — polls-manage.php calls checkAndCloseExpiredPolls()
 * but the original function is named checkAndUpdateExpiredPolls().
 * This keeps both names working without changing anything else.
 */
function checkAndCloseExpiredPolls($conn) {
    return checkAndUpdateExpiredPolls($conn);
}

/**
 * Get poll winner(s)
 * Returns array of winning options
 */
function getPollWinner($conn, $poll_id) {
    // Get vote counts for all options
    $query = "
        SELECT po.option_id, 
               po.option_text, 
               COUNT(pv.vote_id) as vote_count
        FROM tbl_poll_options po
        LEFT JOIN tbl_poll_votes pv ON po.option_id = pv.option_id
        WHERE po.poll_id = ?
        GROUP BY po.option_id
        ORDER BY vote_count DESC, po.option_order ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $poll_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $winners = [];
    $max_votes = -1;
    
    while ($row = $result->fetch_assoc()) {
        if ($max_votes === -1) {
            $max_votes = $row['vote_count'];
            $winners[] = $row;
        } elseif ($row['vote_count'] === $max_votes) {
            $winners[] = $row;
        } else {
            break;
        }
    }
    
    $stmt->close();
    
    return $winners;
}

/**
 * Get poll statistics
 */
function getPollStats($conn, $poll_id) {
    $query = "
        SELECT 
            COUNT(DISTINCT pv.vote_id) as total_votes,
            COUNT(DISTINCT pv.resident_id) as total_voters,
            po.option_id,
            po.option_text,
            COUNT(pv.vote_id) as option_votes
        FROM tbl_poll_options po
        LEFT JOIN tbl_poll_votes pv ON po.option_id = pv.option_id
        WHERE po.poll_id = ?
        GROUP BY po.option_id
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $poll_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stats = ['total_votes' => 0, 'total_voters' => 0, 'options' => []];
    $first = true;
    
    while ($row = $result->fetch_assoc()) {
        if ($first) {
            $stats['total_votes']  = $row['total_votes'];
            $stats['total_voters'] = $row['total_voters'];
            $first = false;
        }
        $stats['options'][] = [
            'option_id'   => $row['option_id'],
            'option_text' => $row['option_text'],
            'votes'       => $row['option_votes'],
            'percentage'  => $stats['total_votes'] > 0
                ? round(($row['option_votes'] / $stats['total_votes']) * 100, 1)
                : 0
        ];
    }
    
    $stmt->close();
    return $stats;
}

/**
 * Check if user has voted in a poll
 */
function hasUserVoted($conn, $poll_id, $resident_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as vote_count FROM tbl_poll_votes WHERE poll_id = ? AND resident_id = ?");
    $stmt->bind_param("ii", $poll_id, $resident_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['vote_count'] > 0;
}

/**
 * Get user's vote(s) for a poll
 */
function getUserVotes($conn, $poll_id, $resident_id) {
    $stmt = $conn->prepare("
        SELECT pv.option_id, po.option_text
        FROM tbl_poll_votes pv
        INNER JOIN tbl_poll_options po ON pv.option_id = po.option_id
        WHERE pv.poll_id = ? AND pv.resident_id = ?
    ");
    $stmt->bind_param("ii", $poll_id, $resident_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $votes = [];
    while ($row = $result->fetch_assoc()) { $votes[] = $row; }
    $stmt->close();
    return $votes;
}

// Auto-check for expired polls when this file is included
if (isset($conn)) {
    checkAndUpdateExpiredPolls($conn);
}
?>