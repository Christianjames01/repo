<?php
// modules/community/get-topic-data.php
session_start();
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Topic ID is required']);
    exit();
}

$topic_id = intval($_GET['id']);

// Get topic details
$topic_query = "
    SELECT t.*, 
           u.username,
           CONCAT(COALESCE(res.first_name, ''), ' ', COALESCE(res.last_name, '')) as author_name,
           res.profile_photo,
           c.category_name
    FROM tbl_forum_topics t
    LEFT JOIN tbl_users u ON t.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
    WHERE t.topic_id = ?
";

$stmt = $conn->prepare($topic_query);
$stmt->bind_param("i", $topic_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Topic not found']);
    $stmt->close();
    exit();
}

$topic = $result->fetch_assoc();
$stmt->close();

// Clean up author name if empty
if (empty(trim($topic['author_name']))) {
    $topic['author_name'] = $topic['username'];
}

// Get replies for this topic
$replies_query = "
    SELECT r.*, 
           u.username,
           CONCAT(COALESCE(res.first_name, ''), ' ', COALESCE(res.last_name, '')) as author_name,
           res.profile_photo
    FROM tbl_forum_replies r
    LEFT JOIN tbl_users u ON r.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    WHERE r.topic_id = ?
    ORDER BY r.created_at ASC
";

$replies_stmt = $conn->prepare($replies_query);
$replies_stmt->bind_param("i", $topic_id);
$replies_stmt->execute();
$replies_result = $replies_stmt->get_result();

$replies = [];
while ($reply = $replies_result->fetch_assoc()) {
    // Clean up author name if empty
    if (empty(trim($reply['author_name']))) {
        $reply['author_name'] = $reply['username'];
    }
    $replies[] = $reply;
}

$replies_stmt->close();

echo json_encode([
    'success' => true,
    'topic' => $topic,
    'replies' => $replies
]);
?>