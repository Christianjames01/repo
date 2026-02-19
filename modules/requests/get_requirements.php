<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';

requireLogin();
header('Content-Type: application/json');

$request_type_id = isset($_GET['request_type_id']) ? intval($_GET['request_type_id']) : 0;

if ($request_type_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request type', 'requirements' => []]);
    exit();
}

$sql = "SELECT * FROM tbl_requirements WHERE request_type_id = ? AND is_active = 1 ORDER BY is_mandatory DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $request_type_id);
$stmt->execute();
$result = $stmt->get_result();

$requirements = [];
while ($row = $result->fetch_assoc()) {
    $requirements[] = [
        'requirement_id' => (int)$row['requirement_id'],
        'requirement_name' => $row['requirement_name'],
        'description' => $row['description'] ?? '',
        'is_mandatory' => (bool)$row['is_mandatory']
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'requirements' => $requirements, 'count' => count($requirements)]);
?>