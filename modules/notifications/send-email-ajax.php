<?php
/**
 * AJAX Email Sender - modules/notifications/send-email-ajax.php
 */

@set_time_limit(300);
@ini_set('max_execution_time', 300);
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@error_reporting(0);

while (ob_get_level()) ob_end_clean();
ob_start();

$response = [
    'success'    => false,
    'message'    => '',
    'sent'       => 0,
    'failed'     => 0,
    'no_email'   => 0,
    'total'      => 0,
    'saved'      => 0,
    'history_id' => null,
];

// ── Map notification_type → reference_type that tbl_notifications expects ────
// These must match the values your notifications index.php filters on.
function resolveReferenceType(string $notification_type): string {
    $map = [
        'general'           => 'announcement',
        'announcement'      => 'announcement',
        'alert'             => 'announcement',
        'incident_reported' => 'incident',
        'status_update'     => 'announcement',
    ];
    return $map[$notification_type] ?? 'announcement';
}

// ── Map notification_type → type value that tbl_notifications expects ─────────
// Matches the badge/filter labels visible in the notifications page.
function resolveNotificationType(string $notification_type): string {
    $map = [
        'general'           => 'general',
        'announcement'      => 'announcement',
        'alert'             => 'alert',
        'incident_reported' => 'incident_reported',
        'status_update'     => 'status_update',
    ];
    return $map[$notification_type] ?? 'announcement';
}

function isValidEmailAddress(string $email): bool {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $parts       = explode('@', $email);
    if (count($parts) !== 2) return false;
    $domain      = strtolower($parts[1]);
    $domainParts = explode('.', $domain);
    if (!str_contains($domain, '.'))                                      return false;
    if (strlen(end($domainParts)) < 2)                                    return false;
    if (strlen(implode('.', array_slice($domainParts, 0, -1))) < 2)       return false;
    if (strlen($parts[0]) < 2)                                            return false;
    if (str_contains($email, '..'))                                       return false;
    return true;
}

/**
 * Insert one in-app notification row.
 * Detects which columns actually exist so it works with any schema variant.
 */
function insertInAppNotification(
    $conn,
    int    $target_user_id,
    string $title,
    string $message,
    string $notification_type,
    int    $history_id
): bool {
    $type     = resolveNotificationType($notification_type);
    $ref_type = resolveReferenceType($notification_type);

    // Try the full schema first (most common)
    $sql = "INSERT INTO tbl_notifications
            (user_id, title, message, type, reference_type, reference_id, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";

    $s = $conn->prepare($sql);
    if ($s) {
        $s->bind_param('issssi', $target_user_id, $title, $message, $type, $ref_type, $history_id);
        $ok = $s->execute();
        $s->close();
        return $ok;
    }

    // Fallback: minimal schema without reference columns
    $sql2 = "INSERT INTO tbl_notifications
             (user_id, title, message, type, is_read, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())";
    $s2 = $conn->prepare($sql2);
    if ($s2) {
        $s2->bind_param('isss', $target_user_id, $title, $message, $type);
        $ok = $s2->execute();
        $s2->close();
        return $ok;
    }

    return false;
}

try {
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    ob_clean();

    ob_start();
    require_once __DIR__ . '/../../includes/phpmailer/mailer.php';
    ob_end_clean();

    if (session_status() === PHP_SESSION_NONE) @session_start();

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']))
        throw new Exception('Session expired. Please log in again.');
    if (!isset($conn) || !$conn)
        throw new Exception('Database connection failed');

    // ── Auth ──────────────────────────────────────────────────────────────────
    $sender_id = (int)$_SESSION['user_id'];
    $s = $conn->prepare("SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1");
    if (!$s) throw new Exception('DB error: ' . $conn->error);
    $s->bind_param('i', $sender_id);
    $s->execute();
    $user = $s->get_result()->fetch_assoc();
    $s->close();

    if (!$user)                                  throw new Exception('User not found');
    if ($user['role'] !== 'Super Administrator') throw new Exception('Access denied.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')   throw new Exception('Invalid request method');

    // ── Inputs ────────────────────────────────────────────────────────────────
    $title             = trim($_POST['email_title']    ?? '');
    $message           = trim($_POST['email_message']  ?? '');
    $notification_type = $_POST['notification_type']   ?? 'general';
    $recipient_type    = $_POST['recipient_type']      ?? '';
    $include_link      = isset($_POST['include_link']) && $_POST['include_link'] === '1';
    $action_url_val    = $include_link ? trim($_POST['action_url'] ?? '') : '';

    if (empty($title) || empty($message)) throw new Exception('Title and message are required');
    if (empty($recipient_type))           throw new Exception('Please select a recipient type');

    // ── Fetch residents ───────────────────────────────────────────────────────
    $all_residents     = [];
    $recipient_details = '';

    if ($recipient_type === 'all') {
        $r = $conn->query(
            "SELECT r.resident_id,
                    CONCAT(r.first_name,' ',r.last_name) AS name,
                    r.email,
                    u.user_id AS linked_user_id
             FROM tbl_residents r
             LEFT JOIN tbl_users u ON u.resident_id = r.resident_id
             ORDER BY r.first_name, r.last_name"
        );
        while ($row = $r->fetch_assoc()) $all_residents[] = $row;
        $recipient_details = 'All Residents';

    } elseif ($recipient_type === 'selected') {
        if (empty($_POST['selected_residents']) || !is_array($_POST['selected_residents']))
            throw new Exception('No residents selected');
        $ids = array_map('intval', $_POST['selected_residents']);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $s   = $conn->prepare(
            "SELECT r.resident_id,
                    CONCAT(r.first_name,' ',r.last_name) AS name,
                    r.email,
                    u.user_id AS linked_user_id
             FROM tbl_residents r
             LEFT JOIN tbl_users u ON u.resident_id = r.resident_id
             WHERE r.resident_id IN ($ph)
             ORDER BY r.first_name, r.last_name"
        );
        $s->bind_param(str_repeat('i', count($ids)), ...$ids);
        $s->execute();
        $r = $s->get_result();
        while ($row = $r->fetch_assoc()) $all_residents[] = $row;
        $s->close();
        $recipient_details = count($all_residents) . ' Selected Residents';

    } elseif ($recipient_type === 'purok') {
        $purok = trim($_POST['purok'] ?? '');
        if (empty($purok)) throw new Exception('Please select a purok');
        $s = $conn->prepare(
            "SELECT r.resident_id,
                    CONCAT(r.first_name,' ',r.last_name) AS name,
                    r.email,
                    u.user_id AS linked_user_id
             FROM tbl_residents r
             LEFT JOIN tbl_users u ON u.resident_id = r.resident_id
             WHERE r.purok = ?
             ORDER BY r.first_name, r.last_name"
        );
        $s->bind_param('s', $purok);
        $s->execute();
        $r = $s->get_result();
        while ($row = $r->fetch_assoc()) $all_residents[] = $row;
        $s->close();
        $recipient_details = 'Purok: ' . $purok;
    } else {
        throw new Exception('Invalid recipient type');
    }

    if (empty($all_residents)) throw new Exception('No recipients found');
    $total_residents = count($all_residents);

    // ── Snapshot MAX id before insert (handles missing AUTO_INCREMENT) ────────
    $pre_max = 0;
    $pmr = $conn->query("SELECT COALESCE(MAX(id), 0) AS mid FROM tbl_email_history");
    if ($pmr) $pre_max = (int)($pmr->fetch_assoc()['mid'] ?? 0);

    // ── Insert email history ──────────────────────────────────────────────────
    $hs = $conn->prepare(
        "INSERT INTO tbl_email_history
             (sender_id, recipient_type, recipient_details, email_title, email_message,
              notification_type, action_url, total_recipients, successful_sends, failed_sends, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())"
    );
    if (!$hs) throw new Exception('Prepare failed: ' . $conn->error);

    $hs->bind_param('issssssi',
        $sender_id, $recipient_type, $recipient_details,
        $title, $message, $notification_type, $action_url_val, $total_residents
    );

    if (!$hs->execute())
        throw new Exception('History INSERT failed: ' . $hs->error . ' (errno ' . $hs->errno . ')');

    // ── Retrieve new row ID (4-layer fallback) ────────────────────────────────
    $email_history_id = (int)$hs->insert_id;
    $hs->close();

    if ($email_history_id <= 0) {
        $r = $conn->query("SELECT LAST_INSERT_ID() AS lid");
        if ($r) $email_history_id = (int)($r->fetch_assoc()['lid'] ?? 0);
    }
    if ($email_history_id <= 0) {
        $r = $conn->query("SELECT COALESCE(MAX(id), 0) AS mid FROM tbl_email_history");
        if ($r) {
            $max_now = (int)($r->fetch_assoc()['mid'] ?? 0);
            if ($max_now > $pre_max) $email_history_id = $max_now;
        }
    }
    if ($email_history_id <= 0) {
        $fs = $conn->prepare(
            "SELECT id FROM tbl_email_history
             WHERE sender_id = ? AND recipient_type = ? AND email_title = ? AND total_recipients = ?
             ORDER BY id DESC LIMIT 1"
        );
        if ($fs) {
            $fs->bind_param('issi', $sender_id, $recipient_type, $title, $total_residents);
            $fs->execute();
            $fr = $fs->get_result()->fetch_assoc();
            $fs->close();
            if ($fr) $email_history_id = (int)$fr['id'];
        }
    }

    if ($email_history_id <= 0)
        throw new Exception(
            'Email was recorded but its ID could not be retrieved. ' .
            'Please ensure the "id" column in tbl_email_history is set to AUTO_INCREMENT PRIMARY KEY.'
        );

    // ── Process each resident ─────────────────────────────────────────────────
    $sent_count        = 0;
    $failed_count      = 0;
    $no_email_count    = 0;
    $saved_count       = 0;
    $notified_user_ids = [];

    foreach ($all_residents as $resident) {
        $res_email      = trim($resident['email'] ?? '');
        $name           = $resident['name'];
        $resident_id    = (int)$resident['resident_id'];
        $linked_uid     = isset($resident['linked_user_id']) ? (int)$resident['linked_user_id'] : 0;

        $has_email_field = !empty($res_email);
        $email_is_valid  = $has_email_field && isValidEmailAddress($res_email);
        $has_email_int   = $email_is_valid ? 1 : 0;
        $email_sent_int  = 0;
        $error_msg       = '';
        $sent_time       = '';

        if (!$has_email_field) {
            $no_email_count++;
            $error_msg = 'No email address on record';

        } elseif (!$email_is_valid) {
            $failed_count++;
            $has_email_int = 0;
            $error_msg = 'Invalid email format: ' . $res_email;

        } else {
            try {
                ob_start();
                $send_result = sendNotificationEmail(
                    $res_email, $name, $title, $message, $notification_type,
                    !empty($action_url_val) ? $action_url_val : null
                );
                ob_end_clean();

                if ($send_result === true) {
                    $email_sent_int = 1;
                    $sent_count++;
                    $sent_time = date('Y-m-d H:i:s');

                    if ($linked_uid && !in_array($linked_uid, $notified_user_ids)) {
                        if (insertInAppNotification(
                            $conn, $linked_uid, $title, $message,
                            $notification_type, $email_history_id
                        )) {
                            $saved_count++;
                            $notified_user_ids[] = $linked_uid;
                        }
                    }
                } else {
                    $failed_count++;
                    $error_msg = 'SMTP rejected the message';
                }

            } catch (Exception $e) {
                if (ob_get_level()) ob_end_clean();
                $failed_count++;
                $error_msg = substr($e->getMessage(), 0, 200);
            }

            usleep(50000);
        }

        // ── Recipient log ─────────────────────────────────────────────────────
        $rs = $conn->prepare(
            "INSERT INTO tbl_email_recipients
             (email_history_id, resident_id, resident_name, resident_email,
              has_email, email_sent, sent_at, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($rs) {
            $rs->bind_param('iissiiss',
                $email_history_id, $resident_id, $name, $res_email,
                $has_email_int, $email_sent_int, $sent_time, $error_msg
            );
            $rs->execute();
            $rs->close();
        }
    }

    // ── In-app notification for the sender (Super Admin) ──────────────────────
    insertInAppNotification(
        $conn, $sender_id, $title, $message,
        $notification_type, $email_history_id
    );

    // ── Update history totals ─────────────────────────────────────────────────
    $total_failed = $failed_count + $no_email_count;
    $us = $conn->prepare(
        "UPDATE tbl_email_history SET successful_sends = ?, failed_sends = ? WHERE id = ?"
    );
    if ($us) {
        $us->bind_param('iii', $sent_count, $total_failed, $email_history_id);
        $us->execute();
        $us->close();
    }

    // ── Response ──────────────────────────────────────────────────────────────
    $response['success']    = $sent_count > 0 || ($failed_count === 0 && $no_email_count < $total_residents);
    $response['sent']       = $sent_count;
    $response['failed']     = $failed_count;
    $response['no_email']   = $no_email_count;
    $response['total']      = $total_residents;
    $response['saved']      = $saved_count;
    $response['history_id'] = $email_history_id;

    if ($sent_count > 0) {
        $msg = "Successfully sent to {$sent_count} recipient(s)";
        if ($no_email_count > 0) $msg .= ". {$no_email_count} had no email address";
        if ($failed_count   > 0) $msg .= ". {$failed_count} failed (invalid/SMTP error)";
        $response['message'] = $msg . '.';
    } elseif ($no_email_count === $total_residents) {
        $response['success'] = true;
        $response['message'] = 'No residents have email addresses on record.';
    } else {
        $response['message'] = 'No emails sent. Check SMTP config or resident email addresses.';
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;