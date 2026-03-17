<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';
requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Relief Inventory Management';
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $item_name = sanitizeInput($_POST['item_name']);
        $category = $_POST['item_category'];
        $unit = sanitizeInput($_POST['unit_of_measure']);
        $min_stock = intval($_POST['minimum_stock']);
        $check_existing = fetchOne($conn, "SELECT item_id FROM tbl_relief_items WHERE LOWER(item_name) = LOWER(?)", [$item_name], 's');
        if ($check_existing) {
            $error_message = "Item '{$item_name}' already exists in the inventory!";
        } else {
            $sql = "INSERT INTO tbl_relief_items (item_name, item_category, unit_of_measure, minimum_stock) VALUES (?, ?, ?, ?)";
            if (executeQuery($conn, $sql, [$item_name, $category, $unit, $min_stock], 'sssi')) {
                $item_id = getLastInsertId($conn);
                $check_inv = fetchOne($conn, "SELECT inventory_id FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
                if (!$check_inv) executeQuery($conn, "INSERT INTO tbl_relief_inventory (item_id, quantity) VALUES (?, 0)", [$item_id], 'i');
                $success_message = "Relief item added successfully!";
            } else { $error_message = "Failed to add item."; }
        }
    } elseif ($action === 'delete_item') {
        $item_id = intval($_POST['item_id']);
        $check_inventory = fetchOne($conn, "SELECT COALESCE(SUM(quantity), 0) as total_quantity FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
        $check_transactions = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_relief_transactions WHERE item_id = ?", [$item_id], 'i');
        if ($check_inventory && $check_inventory['total_quantity'] > 0) {
            $error_message = "Cannot delete item with existing stock. Please distribute or remove all stock first.";
        } elseif ($check_transactions && $check_transactions['count'] > 0) {
            $error_message = "Cannot delete item with transaction history.";
        } else {
            executeQuery($conn, "DELETE FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
            if (executeQuery($conn, "DELETE FROM tbl_relief_items WHERE item_id = ?", [$item_id], 'i')) {
                logActivity($conn, getCurrentUserId(), "Deleted relief item ID: $item_id");
                $success_message = "Relief item deleted successfully!";
            } else { $error_message = "Failed to delete item."; }
        }
    } elseif ($action === 'update_item') {
        $item_id = intval($_POST['item_id']);
        $item_name = sanitizeInput($_POST['item_name']);
        $category = $_POST['item_category'];
        $unit = sanitizeInput($_POST['unit_of_measure']);
        $min_stock = intval($_POST['minimum_stock']);
        $check_existing = fetchOne($conn, "SELECT item_id FROM tbl_relief_items WHERE LOWER(item_name) = LOWER(?) AND item_id != ?", [$item_name, $item_id], 'si');
        if ($check_existing) {
            $error_message = "Item name '{$item_name}' already exists!";
        } else {
            $sql = "UPDATE tbl_relief_items SET item_name = ?, item_category = ?, unit_of_measure = ?, minimum_stock = ? WHERE item_id = ?";
            if (executeQuery($conn, $sql, [$item_name, $category, $unit, $min_stock, $item_id], 'sssii')) {
                logActivity($conn, getCurrentUserId(), "Updated relief item ID: $item_id");
                $success_message = "Relief item updated successfully!";
            } else { $error_message = "Failed to update item."; }
        }
    } elseif ($action === 'add_stock') {
        $item_id = intval($_POST['item_id']);
        $quantity = floatval($_POST['quantity']);
        $batch_number = sanitizeInput($_POST['batch_number'] ?? '');
        $expiry_date = $_POST['expiry_date'] ?? null;
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        $user_id = $_SESSION['user_id'];
        $check_inv = fetchOne($conn, "SELECT inventory_id FROM tbl_relief_inventory WHERE item_id = ?", [$item_id], 'i');
        if (!$check_inv) executeQuery($conn, "INSERT INTO tbl_relief_inventory (item_id, quantity) VALUES (?, 0)", [$item_id], 'i');
        if (executeQuery($conn, "UPDATE tbl_relief_inventory SET quantity = quantity + ? WHERE item_id = ?", [$quantity, $item_id], 'di')) {
            executeQuery($conn, "INSERT INTO tbl_relief_transactions (item_id, transaction_type, quantity, reference_type, remarks, performed_by) VALUES (?, 'In', ?, 'Donation/Purchase', ?, ?)", [$item_id, $quantity, $remarks, $user_id], 'idsi');
            $success_message = "Stock added successfully!";
        } else { $error_message = "Failed to add stock."; }
    } elseif ($action === 'distribute_relief') {
        $distribution_date = $_POST['distribution_date'];
        $location = sanitizeInput($_POST['location']);
        $total_beneficiaries = intval($_POST['total_beneficiaries']);
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        $user_id = $_SESSION['user_id'];
        $items = $_POST['items'] ?? [];
        $stock_errors = [];
        foreach ($items as $item_data) {
            if (empty($item_data['item_id']) || empty($item_data['quantity'])) continue;
            $item_id = intval($item_data['item_id']);
            $quantity = floatval($item_data['quantity']);
            $stock_check = fetchOne($conn, "SELECT ri.item_name, COALESCE(SUM(inv.quantity), 0) as available_stock, ri.unit_of_measure FROM tbl_relief_items ri LEFT JOIN tbl_relief_inventory inv ON ri.item_id = inv.item_id WHERE ri.item_id = ? GROUP BY ri.item_id", [$item_id], 'i');
            if ($stock_check && floatval($stock_check['available_stock']) < $quantity)
                $stock_errors[] = "{$stock_check['item_name']}: Requested {$quantity} {$stock_check['unit_of_measure']}, only {$stock_check['available_stock']} available.";
        }
        if (!empty($stock_errors)) {
            $error_message = "Cannot complete distribution due to insufficient stock:\n" . implode("\n", $stock_errors);
        } else {
            $conn->begin_transaction();
            try {
                executeQuery($conn, "INSERT INTO tbl_relief_distributions (distribution_date, location, total_beneficiaries, distributed_by, status, remarks) VALUES (?, ?, ?, ?, 'Completed', ?)", [$distribution_date, $location, $total_beneficiaries, $user_id, $remarks], 'ssiis');
                $distribution_id = getLastInsertId($conn);
                foreach ($items as $item_data) {
                    if (empty($item_data['item_id']) || empty($item_data['quantity'])) continue;
                    $item_id = intval($item_data['item_id']);
                    $quantity = floatval($item_data['quantity']);
                    executeQuery($conn, "UPDATE tbl_relief_inventory SET quantity = quantity - ? WHERE item_id = ?", [$quantity, $item_id], 'di');
                    executeQuery($conn, "INSERT INTO tbl_relief_distribution_items (distribution_id, item_id, quantity_distributed) VALUES (?, ?, ?)", [$distribution_id, $item_id, $quantity], 'iid');
                    executeQuery($conn, "INSERT INTO tbl_relief_transactions (item_id, transaction_type, quantity, reference_type, reference_id, performed_by) VALUES (?, 'Out', ?, 'Distribution', ?, ?)", [$item_id, $quantity, $distribution_id, $user_id], 'idii');
                }
                $conn->commit();
                $success_message = "Relief distribution completed successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Failed to process distribution: " . $e->getMessage();
            }
        }
    }

    if ($success_message || $error_message) {
        $_SESSION['temp_success'] = $success_message;
        $_SESSION['temp_error'] = $error_message;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

if (isset($_SESSION['temp_success'])) { $success_message = $_SESSION['temp_success']; unset($_SESSION['temp_success']); }
if (isset($_SESSION['temp_error']))   { $error_message   = $_SESSION['temp_error'];   unset($_SESSION['temp_error']);   }

$centers_sql = "SELECT center_id, center_name, location, status FROM tbl_evacuation_centers WHERE status = 'Active' ORDER BY center_name";
$evacuation_centers = fetchAll($conn, $centers_sql);

$inventory_sql = "SELECT ri.*, COALESCE(SUM(inv.quantity), 0) as quantity, MAX(inv.location) as location, MAX(inv.expiry_date) as expiry_date,
                  CASE WHEN COALESCE(SUM(inv.quantity), 0) <= ri.minimum_stock THEN 1 ELSE 0 END as is_low_stock
                  FROM tbl_relief_items ri LEFT JOIN tbl_relief_inventory inv ON ri.item_id = inv.item_id
                  GROUP BY ri.item_id ORDER BY is_low_stock DESC, ri.item_category, ri.item_name";
$inventory = fetchAll($conn, $inventory_sql);

$transactions_sql = "SELECT rt.*, ri.item_name, ri.unit_of_measure, u.username
                     FROM tbl_relief_transactions rt
                     JOIN tbl_relief_items ri ON rt.item_id = ri.item_id
                     JOIN tbl_users u ON rt.performed_by = u.user_id
                     ORDER BY rt.transaction_date DESC LIMIT 10";
$recent_transactions = fetchAll($conn, $transactions_sql);

$stats = ['total_items' => count($inventory), 'distributions_this_month' => 0, 'total_beneficiaries_this_month' => 0];
$column_check = $conn->query("SHOW COLUMNS FROM tbl_relief_distributions LIKE 'total_beneficiaries'");
$has_beneficiaries_column = $column_check && $column_check->num_rows > 0;
if ($has_beneficiaries_column) {
    $dist_result = fetchOne($conn, "SELECT COUNT(*) as count, COALESCE(SUM(total_beneficiaries), 0) as beneficiaries FROM tbl_relief_distributions WHERE MONTH(distribution_date) = MONTH(CURRENT_DATE()) AND YEAR(distribution_date) = YEAR(CURRENT_DATE())");
    $stats['distributions_this_month'] = $dist_result['count'] ?? 0;
    $stats['total_beneficiaries_this_month'] = $dist_result['beneficiaries'] ?? 0;
} else {
    $dist_result = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_relief_distributions WHERE MONTH(distribution_date) = MONTH(CURRENT_DATE()) AND YEAR(distribution_date) = YEAR(CURRENT_DATE())");
    $stats['distributions_this_month'] = $dist_result['count'] ?? 0;
}

$low_stock_count = count(array_filter($inventory, fn($i) => $i['is_low_stock']));

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-warning:#f59e0b;--db-warning-light:#fef3c7;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
/* Hero */
.jb-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.jb-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.jb-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.jb-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.jb-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.jb-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.jb-hero__left{display:flex;align-items:center;gap:16px;}
.jb-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-teal),#0f766e);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.jb-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.jb-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.jb-hero__actions{display:flex;gap:10px;flex-wrap:wrap;}
/* Alerts */
.db-alert{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:#fee2e2;color:#7f1d1d;border-color:#ef4444;}
.db-alert ul{margin:6px 0 0 0;padding-left:18px;font-weight:400;}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;flex-shrink:0;}
/* Stats */
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 130px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-panel__body{padding:20px 22px;}
/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--xs{padding:4px 9px;font-size:11px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));color:#fff;}
.db-btn--teal:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.3);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--sky{background:linear-gradient(135deg,#0369a1,var(--db-sky));color:#fff;}
.db-btn--sky:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,165,233,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--ghost-white{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.25);}
.db-btn--ghost-white:hover{background:rgba(255,255,255,.22);color:#fff;}
.db-btn:disabled{opacity:.55;cursor:not-allowed;transform:none !important;box-shadow:none !important;}
/* Badges */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
/* Table */
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody tr.low-stock{background:#fffbeb;}
.db-table tbody tr.low-stock:hover{background:#fef3c7;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box--lg{max-width:720px;}
.db-modal__box--sm{max-width:440px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));}
.db-modal__header--sky{background:linear-gradient(135deg,#0369a1,var(--db-sky));}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--db-border);}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
/* Form */
.db-form-group{margin-bottom:14px;}
.db-form-group label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.db-input,.db-select,.db-textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-input:read-only{background:var(--db-surf2);color:var(--db-muted);}
.db-textarea{resize:vertical;}
.db-form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
/* Notice */
.db-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-bottom:10px;}
.db-notice--rose{background:var(--db-rose-light);color:#9f1239;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;}
.db-notice--teal{background:var(--db-teal-light);color:#0f766e;}
/* Stock warning */
.stock-warning{color:var(--db-rose);font-size:11px;margin-top:4px;display:none;font-weight:600;}
.stock-warning.show{display:block;}
.db-input.stock-error{border-color:var(--db-rose) !important;background:#fff5f5 !important;}
/* Items repeater */
.item-row{display:grid;grid-template-columns:2fr 1fr auto;gap:10px;margin-bottom:8px;align-items:flex-start;}
/* Location toggle */
.loc-toggle{display:flex;gap:6px;margin-bottom:12px;}
.loc-btn{padding:7px 14px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);background:var(--db-surf2);font-family:'Sora',sans-serif;font-size:12.5px;font-weight:600;color:var(--db-muted);cursor:pointer;transition:all .18s;}
.loc-btn.active{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;border-color:transparent;}
/* Empty */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
/* Delete info box */
.db-delete-info-box{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:14px;margin:12px 0;}
.db-delete-info-name{font-size:13px;font-weight:700;color:var(--db-text);margin-bottom:4px;}
/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-panel__title h2{color:#f1f5f9 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-stat-card__label{color:#64748b !important;}
body.dark-mode .db-table thead tr{background:linear-gradient(135deg,#0f172a,#1e293b) !important;}
body.dark-mode .db-table thead th{color:rgba(148,163,184,.9) !important;}
body.dark-mode .db-table tbody tr{border-bottom-color:#334155 !important;}
body.dark-mode .db-table tbody tr:hover{background:#1e293b !important;}
body.dark-mode .db-table tbody tr.low-stock{background:#27211a !important;}
body.dark-mode .db-table tbody td{color:#e2e8f0 !important;}
body.dark-mode .db-text-sm{color:#94a3b8 !important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-input:read-only{background:#1e293b !important;}
body.dark-mode .db-form-group label{color:#94a3b8 !important;}
body.dark-mode .db-modal__box{background:#1e293b !important;}
body.dark-mode .db-modal__body{background:#1e293b !important;}
body.dark-mode .db-modal__footer{border-top-color:#334155 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-btn--ghost:hover{background:#334155 !important;}
body.dark-mode .db-empty i{color:#334155 !important;}
body.dark-mode .db-empty p{color:#64748b !important;}
body.dark-mode .loc-btn{background:#1e293b !important;border-color:#475569 !important;color:#94a3b8 !important;}
body.dark-mode .db-delete-info-box{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-delete-info-name{color:#f1f5f9 !important;}
body.dark-mode .db-alert--success{background:#052e16 !important;color:#86efac !important;border-color:#4ade80 !important;}
body.dark-mode .db-alert--error{background:#2d1c1c !important;color:#fca5a5 !important;border-color:#ef4444 !important;}
body.dark-mode .db-notice--rose{background:#2d1c1c !important;color:#fca5a5 !important;}
body.dark-mode .db-notice--amber{background:#27211a !important;color:#fbbf24 !important;}
body.dark-mode .db-notice--teal{background:#0d2e2a !important;color:#2dd4bf !important;}
</style>

<!-- Hero -->
<div class="jb-hero">
    <div class="jb-hero__ring jb-hero__ring--1"></div>
    <div class="jb-hero__ring jb-hero__ring--2"></div>
    <div class="jb-hero__ring jb-hero__ring--3"></div>
    <div class="jb-hero__inner">
        <div class="jb-hero__left">
            <div class="jb-hero__icon"><i class="fas fa-boxes"></i></div>
            <div>
                <div class="jb-hero__title">Relief Inventory Management</div>
                <div class="jb-hero__sub">Track and manage disaster relief supplies</div>
            </div>
        </div>
        <div class="jb-hero__actions">
            <?php if ($user_role === 'Super Admin'): ?>
            <button class="db-btn db-btn--teal" onclick="openModal('addItemModal')"><i class="fas fa-plus"></i> Add Item</button>
            <?php endif; ?>
            <button class="db-btn db-btn--amber" onclick="openModal('distributeModal')"><i class="fas fa-hand-holding-heart"></i> Distribute</button>
            <a href="distribution-report.php" class="db-btn db-btn--ghost-white"><i class="fas fa-chart-bar"></i> Reports</a>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($success_message): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle" style="flex-shrink:0;margin-top:1px;"></i><span><?php echo htmlspecialchars($success_message); ?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:1px;"></i>
<span><?php
    if (strpos($error_message, "\n") !== false) {
        $errs = explode("\n", $error_message);
        echo '<strong>'.htmlspecialchars($errs[0]).'</strong>';
        if (count($errs) > 1) {
            echo '<ul>';
            for ($i=1;$i<count($errs);$i++) if (trim($errs[$i])) echo '<li>'.htmlspecialchars($errs[$i]).'</li>';
            echo '</ul>';
        }
    } else echo htmlspecialchars($error_message);
?></span><button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-box"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total_items']; ?></div><div class="db-stat-card__label">Total Items</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-hands-helping"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['distributions_this_month']; ?></div><div class="db-stat-card__label">Distributions (Month)</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($stats['total_beneficiaries_this_month']); ?></div><div class="db-stat-card__label">Beneficiaries (Month)</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <?php if ($low_stock_count > 0): ?>
    <div class="db-stat-card" style="border-color:var(--db-amber);cursor:default;">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $low_stock_count; ?></div><div class="db-stat-card__label">Low Stock Alerts</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <?php endif; ?>
</div>

<!-- Inventory Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-warehouse"></i></div>
            <h2>Current Inventory</h2>
            <span class="db-badge db-badge--teal"><?php echo count($inventory); ?> items</span>
            <?php if ($low_stock_count > 0): ?>
            <span class="db-badge db-badge--amber"><i class="fas fa-exclamation-triangle"></i> <?php echo $low_stock_count; ?> low stock</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>Item Name</th><th>Category</th><th>Current Stock</th><th>Unit</th><th>Min Stock</th><th>Location</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($inventory)): ?>
                <tr><td colspan="7"><div class="db-empty"><i class="fas fa-boxes"></i><p>No inventory items found</p></div></td></tr>
            <?php else: foreach ($inventory as $item):
                $cat_map = ['Food'=>'success','Water'=>'sky','Medicine'=>'rose','Clothing'=>'indigo','Hygiene'=>'teal','Other'=>'muted'];
                $cat_cls = $cat_map[$item['item_category']] ?? 'muted';
            ?>
            <tr class="<?php echo $item['is_low_stock'] ? 'low-stock' : ''; ?>">
                <td>
                    <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                    <?php if ($item['is_low_stock']): ?>
                    <span class="db-badge db-badge--amber" style="margin-left:6px;"><i class="fas fa-exclamation-triangle"></i> Low</span>
                    <?php endif; ?>
                </td>
                <td><span class="db-badge db-badge--<?php echo $cat_cls; ?>"><?php echo $item['item_category']; ?></span></td>
                <td>
                    <strong style="font-family:'DM Mono',monospace;<?php echo $item['is_low_stock']?'color:var(--db-amber-dark)':''; ?>">
                        <?php echo number_format($item['quantity'] ?? 0, 2); ?>
                    </strong>
                </td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($item['unit_of_measure']); ?></span></td>
                <td><span class="db-text-sm"><?php echo number_format($item['minimum_stock']); ?></span></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($item['location'] ?? 'Main Warehouse'); ?></span></td>
                <td>
                    <?php if (in_array($user_role, ['Super Admin','Staff'])): ?>
                    <div style="display:flex;gap:5px;">
                        <button class="db-btn db-btn--success db-btn--xs" onclick='addStockToItem(<?php echo json_encode($item); ?>)'><i class="fas fa-plus"></i> Stock</button>
                        <button class="db-btn db-btn--amber db-btn--xs" onclick='editItem(<?php echo json_encode($item); ?>)'><i class="fas fa-edit"></i></button>
                        <button class="db-btn db-btn--rose db-btn--xs" onclick='deleteItem(<?php echo json_encode($item); ?>)'><i class="fas fa-trash"></i></button>
                    </div>
                    <?php else: ?>
                    <span class="db-text-sm">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Transactions -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--sky"><i class="fas fa-history"></i></div>
            <h2>Recent Transactions</h2>
            <span class="db-badge db-badge--sky">Last 10</span>
        </div>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead>
                <tr><th>Date</th><th>Item</th><th>Type</th><th>Quantity</th><th>Performed By</th><th>Remarks</th></tr>
            </thead>
            <tbody>
            <?php if (empty($recent_transactions)): ?>
                <tr><td colspan="6"><div class="db-empty"><i class="fas fa-history"></i><p>No transactions yet</p></div></td></tr>
            <?php else: foreach ($recent_transactions as $trans): ?>
            <tr>
                <td><span class="db-text-sm"><?php echo formatDateTime($trans['transaction_date']); ?></span></td>
                <td><strong><?php echo htmlspecialchars($trans['item_name']); ?></strong></td>
                <td>
                    <span class="db-badge db-badge--<?php echo $trans['transaction_type']==='In'?'success':'rose'; ?>">
                        <i class="fas fa-<?php echo $trans['transaction_type']==='In'?'arrow-down':'arrow-up'; ?>"></i>
                        <?php echo $trans['transaction_type']; ?>
                    </span>
                </td>
                <td><span style="font-family:'DM Mono',monospace;font-size:12px;"><?php echo number_format($trans['quantity'],2).' '.$trans['unit_of_measure']; ?></span></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($trans['username']); ?></span></td>
                <td><span class="db-text-sm"><?php echo htmlspecialchars($trans['remarks'] ?? '—'); ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /padding -->

<!-- ═══ ADD ITEM MODAL ═══ -->
<div id="addItemModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-plus-circle"></i> Add New Relief Item</h3>
            <button class="db-modal__close" onclick="closeModal('addItemModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="add_item">
                <div class="db-form-group"><label>Item Name *</label><input type="text" name="item_name" class="db-input" required></div>
                <div class="db-form-row-2">
                    <div class="db-form-group">
                        <label>Category *</label>
                        <select name="item_category" class="db-select" required>
                            <?php foreach(['Food','Water','Medicine','Clothing','Hygiene','Other'] as $c): ?>
                            <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="db-form-group"><label>Unit of Measure *</label><input type="text" name="unit_of_measure" class="db-input" placeholder="kg, liters, pieces…" required></div>
                </div>
                <div class="db-form-group"><label>Minimum Stock Level *</label><input type="number" name="minimum_stock" class="db-input" value="0" required></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addItemModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--teal"><i class="fas fa-check"></i> Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ ADD STOCK MODAL ═══ -->
<div id="addStockModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--success">
            <h3><i class="fas fa-plus-circle"></i> Add Stock</h3>
            <button class="db-modal__close" onclick="closeModal('addStockModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="item_id" id="stock_item_id">
                <div class="db-form-row-2">
                    <div class="db-form-group"><label>Item Name</label><input type="text" id="stock_item_name" class="db-input" readonly></div>
                    <div class="db-form-group"><label>Current Stock</label><input type="text" id="stock_current" class="db-input" readonly></div>
                </div>
                <div class="db-form-row-2">
                    <div class="db-form-group"><label>Quantity to Add *</label><input type="number" step="0.01" name="quantity" class="db-input" required></div>
                    <div class="db-form-group"><label>Batch Number</label><input type="text" name="batch_number" class="db-input"></div>
                </div>
                <div class="db-form-group" id="expiryDateGroup" style="display:none;">
                    <label>Expiry Date</label>
                    <input type="date" name="expiry_date" id="expiryDateInput" class="db-input">
                    <div class="db-text-sm" style="margin-top:4px;">Applicable for Food, Water, Medicine</div>
                </div>
                <div class="db-form-group"><label>Remarks</label><textarea name="remarks" class="db-input db-textarea" rows="2"></textarea></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addStockModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--success"><i class="fas fa-check"></i> Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ EDIT ITEM MODAL ═══ -->
<div id="editItemModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-edit"></i> Edit Relief Item</h3>
            <button class="db-modal__close" onclick="closeModal('editItemModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="db-form-group"><label>Item Name *</label><input type="text" name="item_name" id="edit_item_name" class="db-input" required></div>
                <div class="db-form-row-2">
                    <div class="db-form-group">
                        <label>Category *</label>
                        <select name="item_category" id="edit_item_category" class="db-select" required>
                            <?php foreach(['Food','Water','Medicine','Clothing','Hygiene','Other'] as $c): ?>
                            <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="db-form-group"><label>Unit of Measure *</label><input type="text" name="unit_of_measure" id="edit_unit_of_measure" class="db-input" required></div>
                </div>
                <div class="db-form-group"><label>Minimum Stock Level *</label><input type="number" name="minimum_stock" id="edit_minimum_stock" class="db-input" required></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editItemModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--amber"><i class="fas fa-check"></i> Update Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ DELETE ITEM MODAL ═══ -->
<div id="deleteItemModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-trash"></i> Delete Relief Item</h3>
            <button class="db-modal__close" onclick="closeModal('deleteItemModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" id="delete_item_id">
                <div class="db-notice db-notice--rose"><i class="fas fa-exclamation-triangle" style="flex-shrink:0;"></i><span>This action <strong>cannot be undone.</strong></span></div>
                <div class="db-delete-info-box">
                    <div class="db-delete-info-name" id="delete_item_name"></div>
                    <div class="db-text-sm">Category: <span id="delete_item_category"></span></div>
                    <div class="db-text-sm">Current Stock: <span id="delete_item_stock"></span></div>
                </div>
                <div class="db-text-sm" style="color:var(--db-muted);margin-bottom:14px;"><i class="fas fa-info-circle"></i> You can only delete items with zero stock and no transaction history.</div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteItemModal')">Cancel</button>
                    <button type="submit" class="db-btn db-btn--rose" id="confirmDeleteItemBtn"><i class="fas fa-trash"></i> Delete Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ DISTRIBUTE MODAL ═══ -->
<div id="distributeModal" class="db-modal">
    <div class="db-modal__box db-modal__box--lg">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-hand-holding-heart"></i> Distribute Relief Goods</h3>
            <button class="db-modal__close" onclick="closeModal('distributeModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST">
                <input type="hidden" name="action" value="distribute_relief">
                <div class="db-form-row-2">
                    <div class="db-form-group"><label>Distribution Date *</label><input type="date" name="distribution_date" class="db-input" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="db-form-group"><label>Total Beneficiaries *</label><input type="number" name="total_beneficiaries" class="db-input" required></div>
                </div>
                <div class="db-form-group">
                    <label>Location Type *</label>
                    <div class="loc-toggle">
                        <button type="button" class="loc-btn active" onclick="toggleLocationType('custom',this)"><i class="fas fa-map-marker-alt"></i> Custom Location</button>
                        <button type="button" class="loc-btn" onclick="toggleLocationType('center',this)"><i class="fas fa-home"></i> Evacuation Center</button>
                    </div>
                    <input type="text" name="location" id="locationInput" class="db-input" placeholder="e.g., Barangay Hall, Community Center" required>
                    <select name="location" id="centerSelect" class="db-select" style="display:none;">
                        <option value="">— Select Evacuation Center —</option>
                        <?php foreach ($evacuation_centers as $center): ?>
                        <option value="<?php echo htmlspecialchars($center['center_name'].' - '.$center['location']); ?>"><?php echo htmlspecialchars($center['center_name'].' - '.$center['location']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="db-form-group">
                    <label>Items to Distribute *</label>
                    <div id="itemsContainer">
                        <div class="item-row">
                            <div>
                                <select name="items[0][item_id]" class="db-select item-select" required onchange="updateStockInfo(this,0)">
                                    <option value="">— Select Item —</option>
                                    <?php foreach ($inventory as $item): ?>
                                    <option value="<?php echo $item['item_id']; ?>" data-stock="<?php echo $item['quantity']??0; ?>" data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                                        <?php echo htmlspecialchars($item['item_name']).' (Stock: '.number_format($item['quantity']??0,2).' '.$item['unit_of_measure'].')'; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <input type="number" step="0.01" name="items[0][quantity]" class="db-input quantity-input" placeholder="Qty" required oninput="validateQuantity(this,0)">
                                <div class="stock-warning" id="warning-0"></div>
                            </div>
                            <button type="button" class="db-btn db-btn--ghost" style="margin-top:0;" onclick="removeItemRow(this)"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                    <button type="button" class="db-btn db-btn--teal db-btn--sm" style="margin-top:8px;" onclick="addItemRow()"><i class="fas fa-plus"></i> Add Item</button>
                </div>
                <div class="db-form-group"><label>Remarks</label><textarea name="remarks" class="db-input db-textarea" rows="2"></textarea></div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('distributeModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--amber" onclick="return validateDistributionForm()"><i class="fas fa-check"></i> Complete Distribution</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ CANNOT REMOVE LAST ITEM MODAL ═══ -->
<div id="cannotRemoveItemModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-ban"></i> Cannot Remove Item</h3>
            <button class="db-modal__close" onclick="closeModal('cannotRemoveItemModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-notice db-notice--rose"><i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i><span><strong>At least one item is required</strong> for a distribution. Add more items before removing this one.</span></div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--primary" onclick="closeModal('cannotRemoveItemModal')"><i class="fas fa-check"></i> I Understand</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ CONFIRM REMOVE DIST ITEM MODAL ═══ -->
<div id="deleteDistributionItemModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-exclamation-triangle"></i> Remove Item</h3>
            <button class="db-modal__close" onclick="closeModal('deleteDistributionItemModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-notice db-notice--amber"><i class="fas fa-info-circle" style="flex-shrink:0;"></i><span>Remove this item from the distribution form? No inventory changes will be made.</span></div>
            <div style="background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);padding:12px;margin:12px 0;">
                <div style="font-size:13px;margin-bottom:3px;"><strong id="delete_dist_item_name"></strong></div>
                <div class="db-text-sm">Quantity: <span id="delete_dist_item_quantity"></span></div>
            </div>
            <div class="db-modal__footer">
                <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteDistributionItemModal')">Cancel</button>
                <button type="button" class="db-btn db-btn--rose" onclick="confirmRemoveDistItem()"><i class="fas fa-trash"></i> Remove</button>
            </div>
        </div>
    </div>
</div>

<script>
let itemRowToRemove = null;
let itemRowCount = 1;
const inventoryData = <?php echo json_encode($inventory); ?>;
const stockLimits = {};
inventoryData.forEach(item => {
    stockLimits[item.item_id] = { stock: parseFloat(item.quantity||0), unit: item.unit_of_measure, name: item.item_name };
});

function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
window.addEventListener('click',e=>{ if(e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function addStockToItem(item) {
    document.getElementById('stock_item_id').value = item.item_id;
    document.getElementById('stock_item_name').value = item.item_name;
    document.getElementById('stock_current').value = parseFloat(item.quantity||0).toFixed(2)+' '+item.unit_of_measure;
    const eg = document.getElementById('expiryDateGroup');
    const ei = document.getElementById('expiryDateInput');
    if (['Food','Water','Medicine'].includes(item.item_category)) {
        eg.style.display='block'; ei.removeAttribute('disabled');
    } else { eg.style.display='none'; ei.setAttribute('disabled','disabled'); ei.value=''; }
    openModal('addStockModal');
}
function editItem(item) {
    document.getElementById('edit_item_id').value = item.item_id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_item_category').value = item.item_category;
    document.getElementById('edit_unit_of_measure').value = item.unit_of_measure;
    document.getElementById('edit_minimum_stock').value = item.minimum_stock;
    openModal('editItemModal');
}
function deleteItem(item) {
    document.getElementById('delete_item_id').value = item.item_id;
    document.getElementById('delete_item_name').textContent = item.item_name;
    document.getElementById('delete_item_category').textContent = item.item_category;
    document.getElementById('delete_item_stock').textContent = parseFloat(item.quantity||0).toFixed(2)+' '+item.unit_of_measure;
    const btn = document.getElementById('confirmDeleteItemBtn');
    const hasStock = parseFloat(item.quantity||0) > 0;
    btn.disabled = hasStock;
    btn.innerHTML = hasStock ? '<i class="fas fa-ban"></i> Cannot Delete (Has Stock)' : '<i class="fas fa-trash"></i> Delete Item';
    openModal('deleteItemModal');
}
function toggleLocationType(type, btn) {
    document.querySelectorAll('.loc-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const li = document.getElementById('locationInput');
    const cs = document.getElementById('centerSelect');
    if (type==='custom') {
        li.style.display='block'; cs.style.display='none';
        li.name='location'; cs.name=''; li.required=true; cs.required=false;
    } else {
        li.style.display='none'; cs.style.display='block';
        li.name=''; cs.name='location'; li.required=false; cs.required=true;
    }
}
function updateStockInfo(sel, idx) {
    const qi = sel.closest('.item-row').querySelector('.quantity-input');
    qi.value=''; qi.classList.remove('stock-error');
    if (sel.value && stockLimits[sel.value]) {
        qi.setAttribute('data-stock', stockLimits[sel.value].stock);
        qi.setAttribute('data-unit', stockLimits[sel.value].unit);
    }
    const w = document.getElementById('warning-'+idx);
    if (w) w.classList.remove('show');
}
function validateQuantity(input, idx) {
    const qty = parseFloat(input.value);
    const max = parseFloat(input.getAttribute('data-stock')||0);
    const unit = input.getAttribute('data-unit')||'';
    const w = document.getElementById('warning-'+idx);
    if (w && qty > max) {
        w.textContent='⚠ Only '+max.toFixed(2)+' '+unit+' available.';
        w.classList.add('show'); input.classList.add('stock-error');
    } else if (w) { w.classList.remove('show'); input.classList.remove('stock-error'); }
}
function validateDistributionForm() {
    let valid = true;
    document.querySelectorAll('.quantity-input').forEach((input,idx) => {
        if (parseFloat(input.value||0) > parseFloat(input.getAttribute('data-stock')||0)) {
            valid=false; validateQuantity(input,idx);
        }
    });
    if (!valid) { alert('Cannot distribute: one or more items exceed available stock.'); return false; }
    return true;
}
function addItemRow() {
    const container = document.getElementById('itemsContainer');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
        <div>
            <select name="items[${itemRowCount}][item_id]" class="db-select item-select" required onchange="updateStockInfo(this,${itemRowCount})">
                <option value="">— Select Item —</option>
                <?php foreach ($inventory as $item): ?>
                <option value="<?php echo $item['item_id']; ?>" data-stock="<?php echo $item['quantity']??0; ?>" data-unit="<?php echo htmlspecialchars($item['unit_of_measure']); ?>">
                    <?php echo htmlspecialchars($item['item_name']).' (Stock: '.number_format($item['quantity']??0,2).' '.$item['unit_of_measure'].')'; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <input type="number" step="0.01" name="items[${itemRowCount}][quantity]" class="db-input quantity-input" placeholder="Qty" required oninput="validateQuantity(this,${itemRowCount})">
            <div class="stock-warning" id="warning-${itemRowCount}"></div>
        </div>
        <button type="button" class="db-btn db-btn--ghost" onclick="removeItemRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(row);
    itemRowCount++;
}
function removeItemRow(btn) {
    const container = document.getElementById('itemsContainer');
    if (container.children.length <= 1) { openModal('cannotRemoveItemModal'); return; }
    const row = btn.closest('.item-row');
    const sel = row.querySelector('.item-select');
    const qi = row.querySelector('.quantity-input');
    const selOpt = sel.options[sel.selectedIndex];
    document.getElementById('delete_dist_item_name').textContent = selOpt.text || '— Not selected —';
    document.getElementById('delete_dist_item_quantity').textContent = (qi.value||'0') + (qi.getAttribute('data-unit')?' '+qi.getAttribute('data-unit'):'');
    itemRowToRemove = btn;
    openModal('deleteDistributionItemModal');
}
function confirmRemoveDistItem() {
    if (itemRowToRemove) {
        const container = document.getElementById('itemsContainer');
        if (container.children.length > 1) itemRowToRemove.closest('.item-row').remove();
        itemRowToRemove = null;
    }
    closeModal('deleteDistributionItemModal');
}
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.transition='opacity .4s';a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>

<?php include '../../includes/footer.php'; ?>