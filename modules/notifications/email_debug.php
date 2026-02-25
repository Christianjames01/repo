<?php
/**
 * Email Debug Tool
 * Path: /modules/notifications/email_debug.php
 * 
 * DROP THIS FILE in /modules/notifications/ temporarily.
 * Visit it in your browser while logged in as Super Admin.
 * DELETE IT after you're done — it exposes config details.
 */

require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();
$user_role = getCurrentUserRole();
if ($user_role !== 'Super Admin' && $user_role !== 'Super Administrator') {
    die('Access denied.');
}

$results = [];

// ── TEST 1: Check email_helper.php exists and loads ──────────────────────────
$helper_path = '../../includes/email_helper.php';
if (!file_exists($helper_path)) {
    $results[] = ['status' => 'FAIL', 'test' => 'email_helper.php exists', 
                  'detail' => "File not found at: " . realpath('../../includes/') . "/email_helper.php"];
} else {
    $results[] = ['status' => 'OK', 'test' => 'email_helper.php exists', 
                  'detail' => realpath($helper_path)];
    require_once $helper_path;
}

// ── TEST 2: Check sendEmail() function exists ─────────────────────────────────
if (!function_exists('sendEmail')) {
    $results[] = ['status' => 'FAIL', 'test' => 'sendEmail() function exists',
                  'detail' => 'Function sendEmail() not found in email_helper.php'];
} else {
    $results[] = ['status' => 'OK', 'test' => 'sendEmail() function exists', 'detail' => ''];
}

// ── TEST 3: Check getEmailTemplate() function exists ─────────────────────────
if (!function_exists('getEmailTemplate')) {
    $results[] = ['status' => 'WARN', 'test' => 'getEmailTemplate() function exists',
                  'detail' => 'Function not found — notification-detail.php calls this. May cause fatal error.'];
} else {
    $results[] = ['status' => 'OK', 'test' => 'getEmailTemplate() function exists', 'detail' => ''];
}

// ── TEST 4: Check mail-related constants/config ───────────────────────────────
$mail_constants = ['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME', 'MAIL_PORT'];
foreach ($mail_constants as $const) {
    if (defined($const)) {
        // Mask password
        $val = ($const === 'MAIL_PASSWORD') ? str_repeat('*', strlen(constant($const))) : constant($const);
        $results[] = ['status' => 'OK', 'test' => "Constant $const defined", 'detail' => $val];
    } else {
        $results[] = ['status' => 'WARN', 'test' => "Constant $const defined", 
                      'detail' => 'Not defined — check config.php or email_helper.php'];
    }
}

// ── TEST 5: Check residents exist with emails ─────────────────────────────────
$rq = $conn->query("SELECT COUNT(*) as cnt FROM tbl_users WHERE role = 'Resident' AND email IS NOT NULL AND email != ''");
$row = $rq->fetch_assoc();
if ($row['cnt'] > 0) {
    $results[] = ['status' => 'OK', 'test' => 'Residents with emails exist', 
                  'detail' => $row['cnt'] . ' residents found'];
} else {
    $results[] = ['status' => 'FAIL', 'test' => 'Residents with emails exist',
                  'detail' => 'No residents with emails — query returns empty list'];
}

// ── TEST 6: Try sending a real test email ─────────────────────────────────────
$send_test = isset($_POST['send_test']);
$test_result = null;

if ($send_test && function_exists('sendEmail')) {
    $test_to   = trim($_POST['test_email'] ?? '');
    $test_subj = 'Barangay Email Debug Test — ' . date('Y-m-d H:i:s');
    $test_body = '<p>This is a test email from the Barangay notification system.</p><p>Sent at: ' . date('Y-m-d H:i:s') . '</p>';
    
    // Capture any errors/output
    ob_start();
    try {
        $ok = sendEmail($test_to, $test_subj, $test_body, 'Test Recipient');
        $output = ob_get_clean();
        $test_result = [
            'success' => $ok,
            'to'      => $test_to,
            'output'  => $output,
            'return'  => $ok ? 'true' : 'false',
        ];
    } catch (Throwable $e) {
        $output = ob_get_clean();
        $test_result = [
            'success'   => false,
            'to'        => $test_to,
            'output'    => $output,
            'exception' => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
            'return'    => 'exception thrown',
        ];
    }
}

// ── TEST 7: Dump email_helper.php contents so you can see SMTP config ─────────
$helper_contents = '';
if (file_exists($helper_path)) {
    $helper_contents = file_get_contents($helper_path);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Email Debug Tool</title>
<style>
body { font-family: 'Segoe UI', sans-serif; background: #f5f6f8; padding: 30px; color: #2d3748; }
h1 { color: #1a202c; margin-bottom: 4px; }
.subtitle { color: #718096; font-size: 13px; margin-bottom: 30px; }
.card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 24px; margin-bottom: 24px; }
h2 { font-size: 15px; font-weight: 700; margin: 0 0 16px; color: #2d3748; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 8px 12px; background: #f7f9fb; color: #718096; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
td { padding: 9px 12px; border-bottom: 1px solid #f0f4f8; vertical-align: top; word-break: break-all; }
tr:last-child td { border-bottom: none; }
.ok   { color: #2d8a4e; font-weight: 700; }
.fail { color: #c53030; font-weight: 700; }
.warn { color: #d69e2e; font-weight: 700; }
.alert { padding: 14px 18px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.alert-success { background: #f0fff4; border: 1px solid #c6f6d5; color: #276749; }
.alert-fail    { background: #fff5f5; border: 1px solid #fed7d7; color: #c53030; }
input[type=email] { width: 300px; padding: 7px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; }
button { background: #3182ce; color: #fff; border: none; border-radius: 6px; padding: 8px 20px; font-size: 13px; font-weight: 600; cursor: pointer; }
button:hover { background: #2b6cb0; }
pre { background: #1a202c; color: #e2e8f0; padding: 16px; border-radius: 8px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow-y: auto; }
.badge-delete { display: inline-block; background: #fed7d7; color: #c53030; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-left: 8px; }
</style>
</head>
<body>

<h1>📧 Email Debug Tool <span class="badge-delete">DELETE AFTER USE</span></h1>
<p class="subtitle">Run this on your server to find exactly why emails aren't sending. File: <code>/modules/notifications/email_debug.php</code></p>

<!-- ── Results table ── -->
<div class="card">
    <h2>System Checks</h2>
    <table>
        <thead><tr><th>Status</th><th>Test</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td class="<?= strtolower($r['status']) ?>"><?= $r['status'] ?></td>
            <td><?= htmlspecialchars($r['test']) ?></td>
            <td><?= htmlspecialchars($r['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Send test email ── -->
<div class="card">
    <h2>Send Test Email</h2>
    <p style="font-size:13px;color:#718096;margin-bottom:14px;">This calls <code>sendEmail()</code> directly and captures any errors it throws.</p>

    <?php if ($test_result): ?>
        <?php if ($test_result['success']): ?>
        <div class="alert alert-success">
            ✅ <strong>Email sent successfully</strong> to <?= htmlspecialchars($test_result['to']) ?><br>
            <small>sendEmail() returned: <?= $test_result['return'] ?></small>
        </div>
        <?php else: ?>
        <div class="alert alert-fail">
            ❌ <strong>Email FAILED</strong> to <?= htmlspecialchars($test_result['to']) ?><br>
            sendEmail() returned: <strong><?= htmlspecialchars($test_result['return']) ?></strong>
            <?php if (!empty($test_result['exception'])): ?>
            <br><br><strong>Exception:</strong><br>
            <code><?= htmlspecialchars($test_result['exception']) ?></code>
            <?php endif; ?>
            <?php if (!empty($test_result['output'])): ?>
            <br><br><strong>Raw output from sendEmail():</strong><br>
            <code><?= htmlspecialchars($test_result['output']) ?></code>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST">
        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Send test to:</label>
        <input type="email" name="test_email" 
               value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>" 
               placeholder="your@email.com" required>
        <button type="submit" name="send_test" style="margin-left:8px;">Send Test Email</button>
    </form>
</div>

<!-- ── email_helper.php source ── -->
<div class="card">
    <h2>email_helper.php Contents <small style="font-weight:400;color:#718096;">(so you can see the SMTP config)</small></h2>
    <?php if ($helper_contents): ?>
    <pre><?= htmlspecialchars($helper_contents) ?></pre>
    <?php else: ?>
    <p style="color:#c53030;">Could not read file.</p>
    <?php endif; ?>
</div>

<!-- ── Fix guide ── -->
<div class="card">
    <h2>Common Fixes</h2>
    <table>
        <thead><tr><th>Symptom</th><th>Fix</th></tr></thead>
        <tbody>
        <tr>
            <td>sendEmail() returns false, no exception</td>
            <td>SMTP credentials wrong. Check MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD in config.php</td>
        </tr>
        <tr>
            <td>Exception: "Could not connect to SMTP host"</td>
            <td>MAIL_HOST or MAIL_PORT is wrong. For Gmail use smtp.gmail.com port 587. Make sure your server allows outbound port 587.</td>
        </tr>
        <tr>
            <td>Exception: "SMTP AUTH failed"</td>
            <td>For Gmail: enable 2FA and use an App Password (not your real password). Go to myaccount.google.com → Security → App Passwords.</td>
        </tr>
        <tr>
            <td>Exception: "getEmailTemplate not found"</td>
            <td>Add getEmailTemplate() to email_helper.php — see below.</td>
        </tr>
        <tr>
            <td>$sent stays 0, no exception, output is empty</td>
            <td>sendEmail() is silently catching and swallowing its own exceptions. Add error_reporting inside it or check its catch block.</td>
        </tr>
        </tbody>
    </table>

    <h2 style="margin-top:24px;">If getEmailTemplate() is missing — add this to email_helper.php</h2>
    <pre>
if (!function_exists('getEmailTemplate')) {
    function getEmailTemplate(array $data): string {
        $title    = $data['title']       ?? '';
        $greeting = $data['greeting']    ?? 'Dear Resident,';
        $message  = $data['message']     ?? '';
        $footer   = $data['footer_text'] ?? 'Barangay System';
        return "
        &lt;html&gt;&lt;body style='font-family:Arial,sans-serif;background:#f5f6f8;padding:30px;'&gt;
        &lt;div style='max-width:600px;margin:0 auto;background:#fff;border-radius:10px;
                    padding:30px;box-shadow:0 2px 8px rgba(0,0,0,.08);'&gt;
            &lt;h2 style='color:#2d3748;'&gt;{$title}&lt;/h2&gt;
            &lt;p&gt;{$greeting}&lt;/p&gt;
            &lt;div style='background:#f7fafc;padding:16px;border-radius:6px;
                        border-left:4px solid #3182ce;margin:16px 0;'&gt;
                {$message}
            &lt;/div&gt;
            &lt;p style='color:#718096;font-size:12px;margin-top:24px;'&gt;{$footer}&lt;/p&gt;
        &lt;/div&gt;
        &lt;/body&gt;&lt;/html&gt;";
    }
}</pre>
</div>

</body>
</html>