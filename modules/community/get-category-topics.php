<?php
// modules/community/get-category-topics.php
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
    echo json_encode(['success' => false, 'message' => 'Category ID is required']);
    exit();
}

$category_id = intval($_GET['id']);

// Get all topics in this category
$query = "
    SELECT t.*, 
           u.username,
           CONCAT(COALESCE(res.first_name, ''), ' ', COALESCE(res.last_name, '')) as author_name,
           res.profile_photo,
           c.category_name,
           (SELECT COUNT(*) FROM tbl_forum_replies WHERE topic_id = t.topic_id) as reply_count
    FROM tbl_forum_topics t
    LEFT JOIN tbl_users u ON t.user_id = u.user_id
    LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
    LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
    WHERE t.category_id = ?
    ORDER BY t.is_pinned DESC, t.updated_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $category_id);
$stmt->execute();
$result = $stmt->get_result();

$topics = [];
while ($topic = $result->fetch_assoc()) {
    // Clean up author name if empty
    if (empty(trim($topic['author_name']))) {
        $topic['author_name'] = $topic['username'];
    }
    $topics[] = $topic;
}

$stmt->close();

echo json_encode([
    'success' => true,
    'topics' => $topics
]);
?>