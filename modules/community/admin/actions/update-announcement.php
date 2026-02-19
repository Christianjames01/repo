<?php
require_once '../../../../config/config.php';
require_once '../../../../includes/auth_functions.php';
requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
$stmt = $conn->prepare("UPDATE tbl_announcements SET title = ?, content = ? WHERE id = ?");
$stmt->bind_param("ssi", $data['title'], $data['content'], $data['id']);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
}