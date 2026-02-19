<?php
/**
 * MINIMAL SESSION CHECK - No includes, just raw session data
 * Upload to: /modules/incidents/session-check.php
 * Access: http://yoursite.com/barangaylink1/modules/incidents/session-check.php
 */

// Start session
session_start();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Diagnostic</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 8px; max-width: 800px; margin: 0 auto; }
        h1 { color: #333; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        pre { background: #f0f0f0; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .status.good { background: #d4edda; border: 1px solid #c3e6cb; }
        .status.bad { background: #f8d7da; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Session Diagnostic Check</h1>
        <hr>
        
        <h2>Session Status:</h2>
        <?php if (session_status() === PHP_SESSION_ACTIVE): ?>
            <div class="status good">✅ Session is ACTIVE</div>
        <?php else: ?>
            <div class="status bad">❌ Session is NOT active</div>
        <?php endif; ?>
        
        <h2>Session ID:</h2>
        <pre><?php echo session_id() ? session_id() : 'No session ID'; ?></pre>
        
        <h2>Are You Logged In?</h2>
        <?php if (isset($_SESSION['user_id'])): ?>
            <div class="status good">
                ✅ YES - User ID: <strong><?php echo $_SESSION['user_id']; ?></strong>
            </div>
        <?php else: ?>
            <div class="status bad">
                ❌ NO - No user_id in session
            </div>
        <?php endif; ?>
        
        <h2>Full Session Data:</h2>
        <pre><?php 
        if (!empty($_SESSION)) {
            print_r($_SESSION); 
        } else {
            echo "Session is EMPTY!";
        }
        ?></pre>
        
        <h2>Session Configuration:</h2>
        <pre><?php
        echo "session.save_path: " . session_save_path() . "\n";
        echo "session.cookie_path: " . ini_get('session.cookie_path') . "\n";
        echo "session.cookie_domain: " . ini_get('session.cookie_domain') . "\n";
        echo "session.cookie_lifetime: " . ini_get('session.cookie_lifetime') . "\n";
        ?></pre>
        
        <h2>Cookies Received:</h2>
        <pre><?php print_r($_COOKIE); ?></pre>
        
        <hr>
        
        <h2>Diagnosis:</h2>
        <?php if (isset($_SESSION['user_id'])): ?>
            <p class="success">✅ You ARE logged in! Session is working.</p>
            <p>If other pages redirect to login, the issue is with the security functions, not the session itself.</p>
            
            <h3>Your Login Info:</h3>
            <ul>
                <li><strong>User ID:</strong> <?php echo $_SESSION['user_id']; ?></li>
                <li><strong>Username:</strong> <?php echo isset($_SESSION['username']) ? $_SESSION['username'] : 'Not set'; ?></li>
                <li><strong>Role:</strong> <?php echo isset($_SESSION['role']) ? $_SESSION['role'] : 'Not set'; ?></li>
                <li><strong>Role Name:</strong> <?php echo isset($_SESSION['role_name']) ? $_SESSION['role_name'] : 'Not set'; ?></li>
            </ul>
            
        <?php else: ?>
            <p class="error">❌ You are NOT logged in according to this session check.</p>
            <p><strong>Possible causes:</strong></p>
            <ul>
                <li>Session expired or was destroyed</li>
                <li>Session cookie not being sent to this page</li>
                <li>Session path configuration issue</li>
                <li>Different domain or subdomain</li>
            </ul>
            
            <p><strong>Try this:</strong></p>
            <ol>
                <li>Open your dashboard in another tab: <a href="../../dashboard/index.php">Go to Dashboard</a></li>
                <li>Then come back to this page and refresh</li>
                <li>If still empty, there's a session cookie issue</li>
            </ol>
        <?php endif; ?>
        
        <hr>
        <p><small>Session check complete. Close this tab when done.</small></p>
    </div>
</body>
</html>