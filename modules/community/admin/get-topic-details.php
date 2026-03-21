<?php
// modules/community/admin/get-topic-details.php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

header('Content-Type: application/json');

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid topic ID']);
    exit();
}

$topic_id = intval($_GET['id']);

try {
    // Get topic details
    $topic_stmt = $conn->prepare("
        SELECT t.*,
               u.username,
               CONCAT(res.first_name, ' ', res.last_name) as author_name,
               res.profile_photo,
               c.category_name
        FROM tbl_forum_topics t
        LEFT JOIN tbl_users u ON t.user_id = u.user_id
        LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
        LEFT JOIN tbl_forum_categories c ON t.category_id = c.category_id
        WHERE t.topic_id = ?
    ");
    $topic_stmt->bind_param("i", $topic_id);
    $topic_stmt->execute();
    $topic_result = $topic_stmt->get_result();

    if ($topic_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Topic not found']);
        exit();
    }

    $topic = $topic_result->fetch_assoc();
    $topic_stmt->close();

    // Get replies
    $replies_stmt = $conn->prepare("
        SELECT r.*,
               u.username,
               CONCAT(res.first_name, ' ', res.last_name) as author_name,
               res.profile_photo
        FROM tbl_forum_replies r
        LEFT JOIN tbl_users u ON r.user_id = u.user_id
        LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
        WHERE r.topic_id = ?
        ORDER BY r.created_at ASC
    ");
    $replies_stmt->bind_param("i", $topic_id);
    $replies_stmt->execute();
    $replies_result = $replies_stmt->get_result();

    $replies = [];
    while ($row = $replies_result->fetch_assoc()) {
        $replies[] = $row;
    }
    $replies_stmt->close();

    echo json_encode([
        'success' => true,
        'topic'   => $topic,
        'replies' => $replies
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>