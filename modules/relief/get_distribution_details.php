<?php
/**
 * Get Distribution Details
 * Path: barangaylink/disasters/get_distribution_details.php
 * AJAX endpoint for fetching detailed distribution information
 */

require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$distribution_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($distribution_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid distribution ID']);
    exit;
}

try {
    // Fetch distribution details
    $dist_sql = "SELECT rd.*, u.username as distributed_by_name
                 FROM tbl_relief_distributions rd
                 LEFT JOIN tbl_users u ON rd.distributed_by = u.user_id
                 WHERE rd.distribution_id = ?";
    
    $distribution = fetchOne($conn, $dist_sql, [$distribution_id], 'i');
    
    if (!$distribution) {
        echo json_encode(['success' => false, 'message' => 'Distribution not found']);
        exit;
    }
    
    // Fetch items for this distribution
    $items_sql = "SELECT rdi.*, ri.item_name, ri.item_category, ri.unit_of_measure
                  FROM tbl_relief_distribution_items rdi
                  JOIN tbl_relief_items ri ON rdi.item_id = ri.item_id
                  WHERE rdi.distribution_id = ?
                  ORDER BY ri.item_name";
    
    $items = fetchAll($conn, $items_sql, [$distribution_id], 'i');
    
    // Format the distribution date
    $distribution['distribution_date'] = formatDate($distribution['distribution_date']);
    
    echo json_encode([
        'success' => true,
        'distribution' => $distribution,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>