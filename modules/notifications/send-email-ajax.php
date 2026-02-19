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
    'history_id' => null
];

/**
 * Validate email more strictly than filter_var alone.
 */
function isValidEmailAddress(string $email): bool {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $parts = explode('@', $email);
    if (count($parts) !== 2) return false;
    $local  = $parts[0];
    $domain = strtolower($parts[1]);
    if (!str_contains($domain, '.')) return false;
    $domainParts = explode('.', $domain);
    $tld = end($domainParts);
    if (strlen($tld) < 2) return false;
    $hostPart = implode('.', array_slice($domainParts, 0, -1));
    if (strlen($hostPart) < 2) return false;
    if (strlen($local) < 2) return false;
    if (str_contains($email, '..')) return false;
    return true;
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

    $user_id = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1");
    if (!$stmt) throw new Exception('DB error: ' . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user)                                  throw new Exception('User not found');
    if ($user['role'] !== 'Super Administrator') throw new Exception('Access denied.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')   throw new Exception('Invalid request method');

    $title             = trim($_POST['email_title']    ?? '');
    $message           = trim($_POST['email_message']  ?? '');
    $notification_type = $_POST['notification_type']   ?? 'general';
    $recipient_type    = $_POST['recipient_type']      ?? '';
    $include_link      = isset($_POST['include_link']) && $_POST['include_link'] === '1';
    $action_url_val    = $include_link ? trim($_POST['action_url'] ?? '') : '';

    if (empty($title) || empty($message)) throw new Exception('Title and message are required');
    if (empty($recipient_type))           throw new Exception('Please select a recipient type');

    // Fetch residents
    $all_residents     = [];
    $recipient_details = '';

    if ($recipient_type === 'all') {
        $result = $conn->query(
            "SELECT resident_id, CONCAT(first_name,' ',last_name) AS name, email
             FROM tbl_residents ORDER BY first_name, last_name"
        );
        while ($row = $result->fetch_assoc()) $all_residents[] = $row;
        $recipient_details = 'All Residents';

    } elseif ($recipient_type === 'selected') {
        if (empty($_POST['selected_residents']) || !is_array($_POST['selected_residents']))
            throw new Exception('No residents selected');
        $ids  = array_map('intval', $_POST['selected_residents']);
        $ph   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "SELECT resident_id, CONCAT(first_name,' ',last_name) AS name, email
             FROM tbl_residents WHERE resident_id IN ($ph) ORDER BY first_name, last_name"
        );
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $all_residents[] = $row;
        $stmt->close();
        $recipient_details = count($all_residents) . ' Selected Residents';

    } elseif ($recipient_type === 'purok') {
        $purok = trim($_POST['purok'] ?? '');
        if (empty($purok)) throw new Exception('Please select a purok');
        $stmt = $conn->prepare(
            "SELECT resident_id, CONCAT(first_name,' ',last_name) AS name, email
             FROM tbl_residents WHERE purok = ? ORDER BY first_name, last_name"
        );
        $stmt->bind_param('s', $purok);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $all_residents[] = $row;
        $stmt->close();
        $recipient_details = 'Purok: ' . $purok;
    } else {
        throw new Exception('Invalid recipient type');
    }

    if (empty($all_residents)) throw new Exception('No recipients found');

    $total_residents = count($all_residents);

    // ---------------------------------------------------------------
    // Save email history
    // All bind_param values must be non-null to avoid MySQLi binding
    // corruption that causes insert_id to return 0.
    // ---------------------------------------------------------------
    $history_stmt = $conn->prepare(
        "INSERT INTO tbl_email_history
         (sender_id, recipient_type, recipient_details, email_title, email_message,
          notification_type, action_url, total_recipients, sent_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$history_stmt) throw new Exception('Prepare failed: ' . $conn->error);

    $history_stmt->bind_param('issssssi',
        $user_id,
        $recipient_type,
        $recipient_details,
        $title,
        $message,
        $notification_type,
        $action_url_val,
        $total_residents
    );

    $exec_result = $history_stmt->execute();

    // ---------------------------------------------------------------
    // DEBUG LOG — check modules/notifications/email_debug.log
    // after triggering the error. Remove once fixed.
    // ---------------------------------------------------------------
    $debug = [
        'time'           => date('Y-m-d H:i:s'),
        'exec_result'    => $exec_result,
        'insert_id'      => $conn->insert_id,
        'affected_rows'  => $conn->affected_rows,
        'stmt_error'     => $history_stmt->error,
        'conn_error'     => $conn->error,
        'user_id'        => $user_id,
        'recipient_type' => $recipient_type,
        'total'          => $total_residents,
        'action_url_val' => $action_url_val,
        'title'          => $title,
    ];
    file_put_contents(
        __DIR__ . '/email_debug.log',
        json_encode($debug) . "\n",
        FILE_APPEND
    );

    if (!$exec_result) throw new Exception('History insert failed: ' . $history_stmt->error);

    $email_history_id = $conn->insert_id;
    $history_stmt->close();

    if (!$email_history_id) throw new Exception(
        'Failed to get history ID — insert_id=0. ' .
        'stmt_error: [' . $history_stmt->error . '] ' .
        'conn_error: [' . $conn->error . '] ' .
        'affected_rows: ' . $conn->affected_rows
    );

    $sent_count     = 0;
    $failed_count   = 0;
    $no_email_count = 0;
    $saved_count    = 0;

    foreach ($all_residents as $resident) {
        $res_email   = trim($resident['email'] ?? '');
        $name        = $resident['name'];
        $resident_id = (int)$resident['resident_id'];

        $has_email_field = !empty($res_email);
        $email_is_valid  = $has_email_field && isValidEmailAddress($res_email);

        $has_email_int  = $email_is_valid ? 1 : 0;
        $email_sent_int = 0;
        $error_msg      = '';
        $sent_time      = '';

        if (!$has_email_field) {
            $no_email_count++;
            $error_msg = 'No email address on record';

        } elseif (!$email_is_valid) {
            $failed_count++;
            $has_email_int = 0;
            $error_msg = 'Invalid email format: ' . $res_email;

        } else {
            // Valid email — attempt to send
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

                    // Save in-app notification (best-effort)
                    try {
                        $u_stmt = $conn->prepare(
                            "SELECT user_id FROM tbl_users WHERE resident_id = ? LIMIT 1"
                        );
                        $u_stmt->bind_param('i', $resident_id);
                        $u_stmt->execute();
                        $u_data = $u_stmt->get_result()->fetch_assoc();
                        $u_stmt->close();
                        if ($u_data) {
                            $n_stmt = $conn->prepare(
                                "INSERT INTO tbl_notifications
                                 (user_id, title, message, type, created_at)
                                 VALUES (?, ?, ?, ?, NOW())"
                            );
                            $n_stmt->bind_param('isss',
                                $u_data['user_id'], $title, $message, $notification_type
                            );
                            $n_stmt->execute();
                            $n_stmt->close();
                            $saved_count++;
                        }
                    } catch (Exception $e) { /* ignore */ }

                } else {
                    $failed_count++;
                    $error_msg = 'SMTP rejected the message';
                }

            } catch (Exception $e) {
                if (ob_get_level()) ob_end_clean();
                $failed_count++;
                $error_msg = substr($e->getMessage(), 0, 200);
            }

            usleep(50000); // 50ms between sends
        }

        // Insert recipient record — no null values passed to bind_param
        $r_stmt = $conn->prepare(
            "INSERT INTO tbl_email_recipients
             (email_history_id, resident_id, resident_name, resident_email,
              has_email, email_sent, sent_at, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if ($r_stmt) {
            $r_stmt->bind_param('iissiiss',
                $email_history_id, $resident_id, $name, $res_email,
                $has_email_int, $email_sent_int, $sent_time, $error_msg
            );
            $r_stmt->execute();
            $r_stmt->close();
        }
    }

    // Update history totals
    $total_failed = $failed_count + $no_email_count;
    $u_stmt = $conn->prepare(
        "UPDATE tbl_email_history SET successful_sends = ?, failed_sends = ? WHERE id = ?"
    );
    $u_stmt->bind_param('iii', $sent_count, $total_failed, $email_history_id);
    $u_stmt->execute();
    $u_stmt->close();

    // Build response
    $response['success']    = $sent_count > 0 || ($failed_count === 0);
    $response['sent']       = $sent_count;
    $response['failed']     = $failed_count;
    $response['no_email']   = $no_email_count;
    $response['total']      = $total_residents;
    $response['saved']      = $saved_count;
    $response['history_id'] = $email_history_id;

    if ($sent_count > 0) {
        $msg = "Successfully sent to {$sent_count} recipient(s)";
        if ($no_email_count > 0) $msg .= ". {$no_email_count} had no email address";
        if ($failed_count > 0)   $msg .= ". {$failed_count} failed (invalid/SMTP error)";
        $response['message'] = $msg . ".";
    } elseif ($no_email_count === $total_residents) {
        $response['success'] = true;
        $response['message'] = "No residents have email addresses on record.";
    } else {
        $response['message'] = "No emails sent. Check SMTP config or resident email addresses.";
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