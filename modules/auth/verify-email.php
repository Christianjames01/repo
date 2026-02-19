<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SESSION MUST START BEFORE ANY OUTPUT OR HEADER CALLS
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/email_helper.php';

error_log("=== VERIFY EMAIL PAGE ===");
error_log("Session ID: " . session_id());
error_log("GET params: " . print_r($_GET, true));
error_log("POST params: " . print_r($_POST, true));

// Check if already fully logged in — redirect away
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: /barangaylink1/modules/dashboard/index.php");
    exit();
}

$error   = '';
$success = '';

// ── Resolve email ─────────────────────────────────────────────────────────────
// Email can come from GET (first load) or POST (form submit).
// We ALWAYS keep it in the URL so it survives POST redirects.
if (!empty($_GET['email'])) {
    $email = trim($_GET['email']);
} elseif (!empty($_POST['email'])) {
    $email = trim($_POST['email']);
} else {
    // No email anywhere — send back to login
    header("Location: /barangaylink1/modules/auth/login.php");
    exit();
}

// ── Handle resend ─────────────────────────────────────────────────────────────
if (isset($_POST['resend_code']) && $_POST['resend_code'] == '1') {
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username,
               CONCAT(res.first_name, ' ', res.last_name) AS full_name
        FROM tbl_users u
        LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
        WHERE u.email = ? AND u.email_verified = 0
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user         = $result->fetch_assoc();
        $new_code     = generateVerificationCode();
        $code_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $upd = $conn->prepare("UPDATE tbl_users SET verification_code = ?, verification_code_expires = ? WHERE user_id = ?");
        $upd->bind_param("ssi", $new_code, $code_expires, $user['user_id']);

        if ($upd->execute()) {
            $full_name = trim($user['full_name']) ?: $user['username'];
            sendResendVerificationCodeEmail($email, $full_name, $new_code);
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Error sending new code. Please try again.";
        }
        $upd->close();
    } else {
        $error = "Email not found or already verified.";
    }
    $stmt->close();

// ── Handle code verification ──────────────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verification_code'])) {
    $verification_code = trim($_POST['verification_code']);

    error_log("=== VERIFICATION ATTEMPT === Code: $verification_code | Email: $email");

    if (empty($verification_code)) {
        $error = "Please enter the verification code.";
    } elseif (!preg_match('/^[0-9]{6}$/', $verification_code)) {
        $error = "Code must be exactly 6 digits.";
    } else {
        $stmt = $conn->prepare("
            SELECT u.user_id, u.username, u.role_id, u.resident_id,
                   r.role_name, u.verification_code, u.verification_code_expires,
                   res.is_verified
            FROM tbl_users u
            LEFT JOIN tbl_roles r ON u.role_id = r.role_id
            LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
            WHERE u.email = ? AND u.email_verified = 0
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['verification_code'] !== $verification_code) {
                $error = "Invalid verification code. Please try again.";
            } elseif (strtotime($user['verification_code_expires']) < time()) {
                $error = "This code has expired. Please request a new one.";
            } else {
                // ── Mark email as verified ──────────────────────────────────
                $upd = $conn->prepare("
                    UPDATE tbl_users
                    SET email_verified = 1,
                        verification_code = NULL,
                        verification_code_expires = NULL
                    WHERE user_id = ?
                ");
                $upd->bind_param("i", $user['user_id']);

                if ($upd->execute()) {
                    $upd->close();
                    $stmt->close();

                    // ── Set full session ────────────────────────────────────
                    $_SESSION['user_id']       = (int) $user['user_id'];
                    $_SESSION['username']      = $user['username'];
                    $_SESSION['role_id']       = (int) $user['role_id'];
                    $_SESSION['role']          = $user['role_name'];
                    $_SESSION['role_name']     = $user['role_name'];
                    $_SESSION['resident_id']   = $user['resident_id'];
                    $_SESSION['is_verified']   = $user['is_verified'] ?? 0;
                    $_SESSION['LAST_ACTIVITY'] = time();
                    $_SESSION['logged_in']     = true;

                    session_regenerate_id(true);

                    error_log("=== EMAIL VERIFIED & LOGGED IN ===");
                    error_log("User ID: "   . $_SESSION['user_id']);
                    error_log("Username: "  . $_SESSION['username']);
                    error_log("Role: "      . $_SESSION['role_name']);

                    header("Location: /barangaylink1/modules/dashboard/index.php");
                    exit();

                } else {
                    $error = "Error verifying email. Please try again.";
                    $upd->close();
                }
            }
        } else {
            // Could mean email_verified is already 1 (already verified)
            $chk = $conn->prepare("SELECT email_verified FROM tbl_users WHERE email = ? LIMIT 1");
            $chk->bind_param("s", $email);
            $chk->execute();
            $chk_res = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($chk_res && $chk_res['email_verified'] == 1) {
                $error = "This email is already verified. Please log in normally.";
            } else {
                $error = "Invalid request. Email not found.";
            }
        }

        if (isset($stmt) && $stmt) $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Email | Barangay Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            padding: 2rem;
        }
        .verify-container {
            background: white; border-radius: 16px; padding: 3rem;
            max-width: 500px; width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .verify-header { text-align: center; margin-bottom: 2rem; }
        .verify-icon {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .verify-icon i { font-size: 2.5rem; color: white; }
        .verify-header h2 { color: #1e3a8a; font-size: 2rem; margin-bottom: 0.5rem; }
        .verify-header p  { color: #64748b; font-size: 1rem; }

        .email-display {
            background: #f1f5f9; padding: 1rem; border-radius: 8px;
            text-align: center; margin-bottom: 2rem;
            font-weight: 600; color: #1e3a8a; word-break: break-all;
        }

        .alert {
            padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .alert-danger  { background: #fee2e2; color: #c62828; border-left: 4px solid #f44336; }
        .alert-success { background: #f0fdf4; color: #15803d;  border-left: 4px solid #10b981; }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; color: #1e3a8a; font-weight: 600; margin-bottom: 0.5rem; }

        .code-input {
            width: 100%; padding: 1rem;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: 1.5rem; text-align: center;
            letter-spacing: 0.5rem; font-weight: 600; font-family: monospace;
            transition: all 0.3s;
        }
        .code-input:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .helper-text { font-size: 0.85rem; color: #64748b; margin-top: 0.5rem; text-align: center; }

        .btn-verify {
            width: 100%; padding: 1rem;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white; border: none; border-radius: 8px;
            font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: all 0.3s;
        }
        .btn-verify:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(30,58,138,0.3); }
        .btn-verify:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .btn-resend {
            width: 100%; padding: 0.875rem;
            background: white; color: #3b82f6;
            border: 2px solid #3b82f6; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s;
            margin-top: 1rem;
        }
        .btn-resend:hover { background: #f1f5f9; }

        .divider { text-align: center; margin: 1.5rem 0; color: #94a3b8; font-size: 0.9rem; }

        .back-link {
            text-align: center; margin-top: 1.5rem;
            padding-top: 1.5rem; border-top: 1px solid #e2e8f0;
        }
        .back-link a { color: #3b82f6; text-decoration: none; font-weight: 500; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-header">
            <div class="verify-icon">
                <i class="fas fa-envelope-open-text"></i>
            </div>
            <h2>Verify Your Email</h2>
            <p>Enter the 6-digit code we sent to:</p>
        </div>

        <div class="email-display">
            <i class="fas fa-envelope"></i>
            <?php echo htmlspecialchars($email); ?>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
        </div>
        <?php endif; ?>

        <!-- ── Verification form — email stays in the URL so GET survives POST ── -->
        <form method="POST" action="?email=<?php echo urlencode($email); ?>" id="verifyForm">
            <div class="form-group">
                <label>Verification Code</label>
                <input type="text" name="verification_code" id="verification_code"
                       class="code-input" placeholder="000000"
                       maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off">
                <p class="helper-text">Code expires in 30 minutes</p>
            </div>
            <button type="submit" class="btn-verify" id="verifyBtn">
                <i class="fas fa-check-circle"></i> Verify &amp; Continue
            </button>
        </form>

        <div class="divider">Didn't receive the code?</div>

        <!-- ── Resend form — also keeps email in URL ── -->
        <form method="POST" action="?email=<?php echo urlencode($email); ?>">
            <input type="hidden" name="resend_code" value="1">
            <button type="submit" class="btn-resend">
                <i class="fas fa-redo"></i> Resend Code
            </button>
        </form>

        <div class="back-link">
            <a href="/barangaylink1/modules/auth/login.php">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
        // Numbers only
        document.getElementById('verification_code').addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Disable button on submit to prevent double-submit
        document.getElementById('verifyForm').addEventListener('submit', function() {
            const btn = document.getElementById('verifyBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        });
    </script>
</body>
</html>