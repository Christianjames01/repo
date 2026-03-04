<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id   = getCurrentUserId();
$user_role = getCurrentUserRole();

if ($user_role !== 'Resident') {
    header('Location: ../dashboard/index.php'); exit();
}

$resident_sql  = "SELECT r.resident_id, r.is_verified 
                  FROM tbl_residents r 
                  INNER JOIN tbl_users u ON r.resident_id = u.resident_id 
                  WHERE u.user_id = ?";
$resident_stmt = $conn->prepare($resident_sql);
$resident_stmt->bind_param("i", $user_id);
$resident_stmt->execute();
$resident_data = $resident_stmt->get_result()->fetch_assoc();
$resident_stmt->close();

if (!$resident_data) {
    $resident_sql2 = "SELECT r.resident_id, r.is_verified 
                      FROM tbl_residents r 
                      INNER JOIN tbl_users u ON r.email = u.email 
                      WHERE u.user_id = ?";
    $resident_stmt2 = $conn->prepare($resident_sql2);
    $resident_stmt2->bind_param("i", $user_id);
    $resident_stmt2->execute();
    $resident_data = $resident_stmt2->get_result()->fetch_assoc();
    $resident_stmt2->close();
}

if (!$resident_data) {
    $_SESSION['error_message'] = 'Resident profile not found.';
    header('Location: ../dashboard/index.php'); exit();
}
if ($resident_data['is_verified'] != 1) {
    header('Location: not-verified.php'); exit();
}

$resident_id = (int) $resident_data['resident_id'];
$page_title  = 'My Document Requests';

// Auto-create replies table
$conn->query("CREATE TABLE IF NOT EXISTS tbl_request_replies (
    reply_id     INT AUTO_INCREMENT PRIMARY KEY,
    request_id   INT NOT NULL,
    sender_type  ENUM('admin','resident') NOT NULL,
    sender_id    INT NOT NULL,
    message      TEXT NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request_id (request_id)
)");

// Handle resident reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resident_reply') {
    $reply_request_id = intval($_POST['request_id'] ?? 0);
    $reply_message    = trim($_POST['reply_message'] ?? '');
    $redirect_status  = isset($_POST['current_status']) ? '?status=' . urlencode($_POST['current_status']) : '';

    if ($reply_request_id > 0 && $reply_message !== '') {
        $check_stmt = $conn->prepare("SELECT status FROM tbl_requests WHERE request_id = ? AND resident_id = ?");
        $check_stmt->bind_param("ii", $reply_request_id, $resident_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if ($check_result && $check_result['status'] === 'Rejected') {
            $_SESSION['error_message'] = 'Cannot send reply to a rejected request';
            header('Location: my-requests.php' . $redirect_status); exit();
        }

        $verify_stmt = $conn->prepare("SELECT request_id FROM tbl_requests WHERE request_id = ? AND resident_id = ?");
        $verify_stmt->bind_param("ii", $reply_request_id, $resident_id);
        $verify_stmt->execute();
        $owns = $verify_stmt->get_result()->num_rows > 0;
        $verify_stmt->close();

        if ($owns) {
            $ins_stmt = $conn->prepare("INSERT INTO tbl_request_replies (request_id, sender_type, sender_id, message) VALUES (?, 'resident', ?, ?)");
            $ins_stmt->bind_param("iis", $reply_request_id, $user_id, $reply_message);
            if ($ins_stmt->execute()) {
                $_SESSION['success_message'] = 'Your reply has been sent.';
                $info_stmt = $conn->prepare(
                    "SELECT req.processed_by, req.request_id, rt.request_type_name,
                            res.first_name, res.last_name
                     FROM tbl_requests req
                     LEFT JOIN tbl_request_types rt ON req.request_type_id = rt.request_type_id
                     LEFT JOIN tbl_residents res    ON req.resident_id = res.resident_id
                     WHERE req.request_id = ?"
                );
                $info_stmt->bind_param("i", $reply_request_id);
                $info_stmt->execute();
                $req_info = $info_stmt->get_result()->fetch_assoc();
                $info_stmt->close();

                if ($req_info) {
                    $res_name    = trim(($req_info['first_name'] ?? '') . ' ' . ($req_info['last_name'] ?? ''));
                    $doc_type    = $req_info['request_type_name'] ?? 'Document Request';
                    $notif_title = "Resident Replied to Remarks";
                    $notif_msg   = "{$res_name} replied to your note on their {$doc_type} request.";
                    $ref_type    = "request";
                    $admin_ids   = [];
                    if (!empty($req_info['processed_by'])) $admin_ids[] = (int)$req_info['processed_by'];
                    $sa_result = $conn->query("SELECT user_id FROM tbl_users WHERE role IN ('Super Admin','Super Administrator','Admin')");
                    if ($sa_result) while ($sa_row = $sa_result->fetch_assoc()) $admin_ids[] = (int)$sa_row['user_id'];
                    $admin_ids  = array_unique($admin_ids);
                    $notif_stmt = $conn->prepare("INSERT INTO tbl_notifications (user_id, title, message, type, reference_type, reference_id, is_read, created_at) VALUES (?, ?, ?, 'request_reply', ?, ?, 0, NOW())");
                    foreach ($admin_ids as $aid) { $notif_stmt->bind_param("isssi", $aid, $notif_title, $notif_msg, $ref_type, $reply_request_id); $notif_stmt->execute(); }
                    $notif_stmt->close();
                }
            } else {
                $_SESSION['error_message'] = 'Failed to send reply. Please try again.';
            }
            $ins_stmt->close();
        } else {
            $_SESSION['error_message'] = 'Request not found.';
        }
    } else {
        $_SESSION['error_message'] = 'Reply cannot be empty.';
    }
    header('Location: my-requests.php' . $redirect_status); exit();
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Statistics
$stats_stmt = $conn->prepare("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'Pending'  THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) as released,
    SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN payment_status = 1  THEN 1 ELSE 0 END) as paid
    FROM tbl_requests WHERE resident_id = ?");
$stats_stmt->bind_param("i", $resident_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Requests query
$requests_sql = "SELECT r.request_id, r.resident_id, r.request_type_id,
                 r.purpose, r.status, r.payment_status,
                 r.request_date, r.processed_date, r.remarks, r.processed_by,
                 rt.request_type_name, rt.fee,
                 u.username AS processed_by_name
                 FROM tbl_requests r
                 LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
                 LEFT JOIN tbl_users u ON r.processed_by = u.user_id
                 WHERE r.resident_id = ?";
$params = [$resident_id]; $types = "i";
if ($status_filter && $status_filter !== 'Paid') { $requests_sql .= " AND r.status = ?"; $params[] = $status_filter; $types .= "s"; }
if ($status_filter === 'Paid') $requests_sql .= " AND r.payment_status = 1";
$requests_sql .= " ORDER BY r.request_date DESC";
$requests_stmt = $conn->prepare($requests_sql);
$requests_stmt->bind_param($types, ...$params);
$requests_stmt->execute();
$requests = $requests_stmt->get_result();

// Fetch all replies
$replies_by_request = [];
$all_replies_stmt = $conn->prepare(
    "SELECT rr.reply_id, rr.request_id, rr.sender_type, rr.sender_id, rr.message, rr.created_at,
            u.username,
            res.first_name, res.last_name
     FROM tbl_request_replies rr
     LEFT JOIN tbl_users u ON rr.sender_id = u.user_id AND rr.sender_type = 'admin'
     LEFT JOIN tbl_users ru ON rr.sender_id = ru.user_id AND rr.sender_type = 'resident'
     LEFT JOIN tbl_residents res ON ru.resident_id = res.resident_id
     WHERE rr.request_id IN (SELECT request_id FROM tbl_requests WHERE resident_id = ?)
     ORDER BY rr.created_at ASC"
);
$all_replies_stmt->bind_param("i", $resident_id);
$all_replies_stmt->execute();
$all_replies_result = $all_replies_stmt->get_result();
while ($reply = $all_replies_result->fetch_assoc()) $replies_by_request[$reply['request_id']][] = $reply;
$all_replies_stmt->close();

function getRequestStatusBadge($status) {
    $s   = trim($status);
    $map = [
        'Pending'  => ['amber',   'clock'],
        'Approved' => ['sky',     'check-circle'],
        'Released' => ['success', 'check-double'],
        'Rejected' => ['rose',    'times-circle'],
    ];
    [$color, $icon] = $map[$s] ?? ['muted', 'circle'];
    return "<span class='db-badge db-badge--$color'><i class='fas fa-$icon'></i> " . htmlspecialchars($s) . "</span>";
}

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-info:#3b82f6;--db-info-light:#dbeafe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a2e4a 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(99,102,241,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(16,185,129,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#6366f1,#4f46e5);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* ── Stat Cards ── */
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);transform:translateY(-3px);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--navy{background:linear-gradient(90deg,var(--db-navy),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--muted{background:linear-gradient(90deg,#94a3b8,transparent);}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--navy{background:#e8edf7;color:var(--db-navy);}
.db-badge--green{background:var(--db-success-light);color:#065f46;}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}

/* ── Request Card (grid) ── */
.db-req-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:16px;padding:20px;}
.db-req-card{background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius);transition:border-color .18s,box-shadow .18s,transform .18s;overflow:hidden;display:flex;flex-direction:column;}
.db-req-card:hover{border-color:#c5d0e0;box-shadow:0 4px 18px rgba(13,27,54,.09);transform:translateY(-2px);}
.db-req-card__top{padding:16px 18px;flex:1;cursor:pointer;}
.db-req-card__type{font-size:14px;font-weight:700;margin-bottom:3px;}
.db-req-card__date{font-size:11.5px;color:var(--db-muted);margin-bottom:14px;}
.db-req-card__meta{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;}
.db-req-card__meta-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:3px;}
.db-req-card__purpose-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:4px;}
.db-req-card__purpose{font-size:12.5px;color:var(--db-text);line-height:1.5;}
.db-req-card__footer{padding:10px 18px;border-top:1px solid var(--db-border);background:var(--db-surf);display:flex;align-items:center;justify-content:flex-end;}
.db-req-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-muted);}
.db-req-id-chip{display:inline-flex;align-items:center;gap:4px;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--db-indigo);background:var(--db-indigo-light);padding:2px 9px;border-radius:20px;letter-spacing:.3px;}

/* ── Remarks / Thread ── */
.db-req-thread{border-top:1px solid var(--db-border);padding:12px 18px 14px;background:var(--db-surf);}
.db-admin-note{background:var(--db-sky-light);border:1px solid #bae6fd;border-left:3px solid var(--db-sky);border-radius:0 var(--db-radius-sm) var(--db-radius-sm) 0;padding:10px 12px;font-size:12px;color:#0369a1;margin-bottom:10px;}
.db-admin-note__label{font-family:'DM Mono',monospace;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;opacity:.65;margin-bottom:4px;}
.db-thread{display:flex;flex-direction:column;gap:7px;margin-bottom:8px;}
.db-bubble{padding:8px 12px;border-radius:10px;font-size:12px;line-height:1.55;max-width:88%;}
.db-bubble--admin{background:var(--db-sky-light);border:1px solid #bae6fd;color:#0369a1;align-self:flex-start;border-bottom-left-radius:3px;}
.db-bubble--resident{background:var(--db-success-light);border:1px solid #a7f3d0;color:#065f46;align-self:flex-end;border-bottom-right-radius:3px;text-align:right;}
.db-bubble__meta{font-size:10px;opacity:.6;margin-top:3px;display:block;}
.db-reply-toggle{background:none;border:none;padding:0;color:var(--db-sky);font-size:12px;font-weight:600;cursor:pointer;text-decoration:underline;text-underline-offset:2px;font-family:'Sora',sans-serif;}
.db-reply-area{display:none;margin-top:8px;}
.db-reply-area.open{display:block;}
.db-reply-textarea{width:100%;border:2px solid var(--db-border);border-radius:var(--db-radius-sm);padding:8px 12px;font-family:'Sora',sans-serif;font-size:12.5px;resize:none;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s;}
.db-reply-textarea:focus{border-color:var(--db-sky);}
.db-reply-actions{display:flex;gap:8px;margin-top:6px;}
.db-btn-send{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;border:none;border-radius:var(--db-radius-sm);font-size:12px;font-weight:600;padding:6px 14px;cursor:pointer;font-family:'Sora',sans-serif;transition:all .18s;}
.db-btn-send:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(13,27,54,.2);}
.db-btn-cancel-reply{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);font-size:12px;font-weight:500;padding:6px 12px;cursor:pointer;font-family:'Sora',sans-serif;}
.db-chat-disabled{display:flex;align-items:center;gap:8px;background:var(--db-danger-light);border:1px solid #fca5a5;border-radius:var(--db-radius-sm);padding:8px 12px;font-size:12px;color:#7f1d1d;margin-top:8px;}

/* ── Empty ── */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* ── Fee badges ── */
.db-fee-paid{display:inline-flex;align-items:center;gap:4px;background:var(--db-success-light);color:#065f46;border:1px solid #a7f3d0;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;}
.db-fee-unpaid{display:inline-flex;align-items:center;gap:4px;background:var(--db-amber-light);color:#92400e;border:1px solid #fde68a;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;}
.db-fee-free{display:inline-flex;align-items:center;gap:4px;background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);padding:2px 8px;border-radius:6px;font-size:11px;}
.db-fee-na{display:inline-flex;align-items:center;gap:4px;background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);padding:2px 8px;border-radius:6px;font-size:11px;}

/* ── Details Modal ── */
.db-modal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(13,27,54,.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;}
.db-modal.open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);box-shadow:var(--db-shadow-lg);width:100%;max-width:780px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;animation:dbFadeUp .2s ease;}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));flex-shrink:0;}
.db-modal__header h3{font-size:15px;font-weight:700;color:#fff;margin:0;}
.db-modal__close{background:none;border:none;color:rgba(255,255,255,.7);font-size:20px;cursor:pointer;line-height:1;padding:0;}
.db-modal__body{padding:0;overflow-y:auto;flex:1;background:var(--db-bg);}
.db-modal__footer{padding:14px 22px;border-top:1px solid var(--db-border);display:flex;justify-content:flex-end;flex-shrink:0;}
.db-spinner{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 24px;gap:14px;color:var(--db-muted);font-size:13px;}

@media(max-width:768px){
    .rm-hero{padding:20px;border-radius:0;}
    .db-req-grid{grid-template-columns:1fr;padding:14px;}
}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">My Document Requests</div>
                <div class="rm-hero__sub">View and track your document request history</div>
            </div>
        </div>
        <a href="new-request.php" class="db-btn db-btn--primary"><i class="fas fa-plus"></i> New Request</a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if (isset($_SESSION['success_message'])): ?>
<div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['success_message']); endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div>
<?php unset($_SESSION['error_message']); endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <?php
    $stat_defs = [
        ['label'=>'Total',    'key'=>'total',    'icon_class'=>'navy',    'icon'=>'file-alt',        'filter'=>'',         'color'=>''],
        ['label'=>'Pending',  'key'=>'pending',  'icon_class'=>'amber',   'icon'=>'clock',           'filter'=>'Pending',  'color'=>'color:var(--db-amber-dark)'],
        ['label'=>'Approved', 'key'=>'approved', 'icon_class'=>'sky',     'icon'=>'check-circle',    'filter'=>'Approved', 'color'=>'color:var(--db-sky)'],
        ['label'=>'Released', 'key'=>'released', 'icon_class'=>'success', 'icon'=>'check-double',    'filter'=>'Released', 'color'=>'color:var(--db-success)'],
        ['label'=>'Rejected', 'key'=>'rejected', 'icon_class'=>'rose',    'icon'=>'times-circle',    'filter'=>'Rejected', 'color'=>'color:var(--db-rose)'],
        ['label'=>'Paid',     'key'=>'paid',     'icon_class'=>'muted',   'icon'=>'money-bill-wave', 'filter'=>'Paid',     'color'=>''],
    ];
    foreach ($stat_defs as $sd):
        $is_active = ($status_filter === $sd['filter']) || ($sd['filter'] === '' && $status_filter === '');
        $href      = $sd['filter'] === '' ? 'my-requests.php' : '?status=' . urlencode($sd['filter']);
    ?>
    <a href="<?= $href ?>" class="db-stat-card <?= $is_active ? 'active' : '' ?>">
        <div class="db-stat-card__icon db-stat-card__icon--<?= $sd['icon_class'] ?>"><i class="fas fa-<?= $sd['icon'] ?>"></i></div>
        <div>
            <div class="db-stat-card__num" style="<?= $sd['color'] ?>"><?= (int)($stats[$sd['key']] ?? 0) ?></div>
            <div class="db-stat-card__label"><?= $sd['label'] ?></div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--<?= $sd['icon_class'] ?>"></div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Requests Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-list"></i></div>
            <h2><?= $status_filter ? htmlspecialchars($status_filter) . ' Requests' : 'All Requests' ?></h2>
            <span class="db-badge db-badge--indigo"><?= $requests->num_rows ?></span>
        </div>
        <?php if ($status_filter): ?>
        <a href="my-requests.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a>
        <?php endif; ?>
    </div>

    <?php if ($requests->num_rows === 0): ?>
    <div class="db-empty">
        <i class="fas fa-inbox"></i>
        <p><?= $status_filter ? 'No ' . htmlspecialchars($status_filter) . ' requests found.' : "You haven't submitted any document requests yet." ?></p>
        <?php if ($status_filter): ?>
            <a href="my-requests.php" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a>
        <?php else: ?>
            <a href="new-request.php" class="db-btn db-btn--primary db-btn--sm"><i class="fas fa-plus"></i> Submit Your First Request</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="db-req-grid">
        <?php while ($request = $requests->fetch_assoc()):
            $rid         = intval($request['request_id']);
            $is_rejected = ($request['status'] === 'Rejected');
            $req_replies = $replies_by_request[$rid] ?? [];
            $has_remarks = !empty($request['remarks']);
            $has_thread  = $has_remarks || !empty($req_replies);
        ?>
        <div class="db-req-card">
            <!-- Top clickable area -->
            <div class="db-req-card__top" onclick="viewRequestDetails(<?= $rid ?>)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:10px;">
                    <div>
                        <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px;">
                            <span class="db-req-id-chip">#<?= str_pad($rid, 5, '0', STR_PAD_LEFT) ?></span>
                        </div>
                        <div class="db-req-card__type"><?= htmlspecialchars($request['request_type_name'] ?? 'N/A') ?></div>
                        <div class="db-req-card__date"><i class="fas fa-calendar-alt" style="margin-right:4px;"></i><?= date('F d, Y', strtotime($request['request_date'])) ?></div>
                    </div>
                    <?= getRequestStatusBadge($request['status']) ?>
                </div>

                <div class="db-req-card__meta">
                    <div>
                        <div class="db-req-card__meta-label">Fee</div>
                        <?php if ($request['fee'] > 0): ?>
                            <span style="font-weight:700;font-size:13px;">₱<?= number_format($request['fee'],2) ?></span>
                        <?php else: ?>
                            <span class="db-fee-free">Free</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="db-req-card__meta-label">Payment</div>
                        <?php if ($request['payment_status']==1): ?>
                            <span class="db-fee-paid"><i class="fas fa-check"></i> Paid</span>
                        <?php elseif ($request['fee']>0): ?>
                            <span class="db-fee-unpaid">Unpaid</span>
                        <?php else: ?>
                            <span class="db-fee-na">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="db-req-card__purpose-label">Purpose</div>
                    <div class="db-req-card__purpose">
                        <?php $p = $request['purpose'] ?? ''; $fl = strtok($p, "\n");
                        echo htmlspecialchars(strlen($fl) > 100 ? substr($fl,0,100).'…' : $fl); ?>
                    </div>
                </div>
            </div>

            <!-- Remarks / Thread -->
            <?php if ($has_thread): ?>
            <div class="db-req-thread">
                <?php if ($has_remarks): ?>
                <div class="db-admin-note">
                    <div class="db-admin-note__label"><i class="fas fa-comment-dots" style="margin-right:3px;"></i>Admin Note</div>
                    <?= htmlspecialchars(strlen($request['remarks']) > 130 ? substr($request['remarks'],0,130).'…' : $request['remarks']) ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($req_replies)): ?>
                <div class="db-thread">
                    <?php foreach ($req_replies as $reply): ?>
                    <div class="db-bubble db-bubble--<?= $reply['sender_type'] ?>">
                        <?= htmlspecialchars($reply['message']) ?>
                        <span class="db-bubble__meta">
                            <?= $reply['sender_type']==='admin' ? '<i class="fas fa-user-shield" style="margin-right:3px;"></i>Admin' : '<i class="fas fa-user" style="margin-right:3px;"></i>You' ?>
                            · <?= date('M j, g:i A', strtotime($reply['created_at'])) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($is_rejected): ?>
                <div class="db-chat-disabled"><i class="fas fa-ban"></i> Chat disabled for rejected requests.</div>
                <?php else: ?>
                <button class="db-reply-toggle" onclick="toggleReply(<?= $rid ?>)">
                    <i class="fas fa-reply" style="margin-right:4px;"></i><?= empty($req_replies) ? 'Reply to this note' : 'Add a reply' ?>
                </button>
                <div class="db-reply-area" id="reply-area-<?= $rid ?>">
                    <form method="POST" action="my-requests.php<?= $status_filter ? '?status='.urlencode($status_filter) : '' ?>">
                        <input type="hidden" name="action"         value="resident_reply">
                        <input type="hidden" name="request_id"     value="<?= $rid ?>">
                        <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                        <textarea name="reply_message" class="db-reply-textarea" rows="3" placeholder="Type your reply…" required></textarea>
                        <div class="db-reply-actions">
                            <button type="submit" class="db-btn-send"><i class="fas fa-paper-plane" style="margin-right:4px;"></i>Send</button>
                            <button type="button" class="db-btn-cancel-reply" onclick="toggleReply(<?= $rid ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="db-req-card__footer">
                <button class="db-btn db-btn--ghost db-btn--sm" onclick="viewRequestDetails(<?= $rid ?>)">
                    <i class="fas fa-eye"></i> View Details
                </button>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Details Modal -->
<div class="db-modal" id="detailsModal">
    <div class="db-modal__box">
        <div class="db-modal__header">
            <h3><i class="fas fa-file-alt" style="margin-right:8px;opacity:.7;"></i>Request Details</h3>
            <button class="db-modal__close" onclick="closeModal()">×</button>
        </div>
        <div class="db-modal__body" id="modalBody">
            <div class="db-spinner">
                <div class="spinner-border" style="color:var(--db-sky);width:1.75rem;height:1.75rem;border-width:2px;" role="status"></div>
                <span>Loading…</span>
            </div>
        </div>
        <div class="db-modal__footer">
            <button class="db-btn db-btn--ghost" onclick="closeModal()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<script>
function toggleReply(rid) {
    const area = document.getElementById('reply-area-' + rid);
    if (!area) return;
    area.classList.toggle('open');
    if (area.classList.contains('open')) area.querySelector('textarea').focus();
}

function viewRequestDetails(requestId) {
    if (!requestId || isNaN(requestId)) return;
    const modal = document.getElementById('detailsModal');
    document.getElementById('modalBody').innerHTML = `<div class="db-spinner"><div class="spinner-border" style="color:var(--db-sky);width:1.75rem;height:1.75rem;border-width:2px;" role="status"></div><span>Loading…</span></div>`;
    modal.classList.add('open');
    fetch('get_request_details.php?id=' + requestId)
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(text => {
            try {
                const d = JSON.parse(text);
                document.getElementById('modalBody').innerHTML = d.success ? d.html
                    : `<div style="padding:24px;"><div class="db-alert db-alert--error"><i class="fas fa-exclamation-triangle"></i> ${d.message || 'Failed to load details.'}</div></div>`;
            } catch(e) {
                document.getElementById('modalBody').innerHTML = `<div style="padding:24px;"><div class="db-alert db-alert--error"><i class="fas fa-exclamation-triangle"></i> Invalid server response.</div></div>`;
            }
        })
        .catch(err => {
            document.getElementById('modalBody').innerHTML = `<div style="padding:24px;"><div class="db-alert db-alert--error"><i class="fas fa-exclamation-triangle"></i> Network error: ${err.message}</div></div>`;
        });
}

function closeModal() {
    document.getElementById('detailsModal').classList.remove('open');
}

document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

setTimeout(() => document.querySelectorAll('.db-alert').forEach(a => { a.style.opacity='0'; setTimeout(()=>a.remove(),400); }), 5000);
</script>

<?php $requests_stmt->close(); include '../../includes/footer.php'; ?>