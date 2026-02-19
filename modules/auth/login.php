<?php


// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/email_helper.php';

$error = '';

// Check if already logged in
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0 && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
    if ($role === 'Driver') {
        header("Location: /barangaylink1/vehicles/driver/index.php");
        exit();
    } else {
        header("Location: /barangaylink1/modules/dashboard/index.php");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("
            SELECT u.user_id, u.username, u.password, u.role_id, u.resident_id,
                   r.role_name, u.account_status, u.is_active, u.status,
                   res.is_verified, u.email, u.email_verified,
                   u.verification_code, u.verification_code_expires,
                   CONCAT(res.first_name, ' ', res.last_name) as full_name
            FROM tbl_users u
            LEFT JOIN tbl_roles r ON u.role_id = r.role_id
            LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
            WHERE LOWER(u.username) = LOWER(?)
        ");

        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                // ═══ TEMPORARY DEBUG — REMOVE AFTER FIXING ═══
                error_log("=== LOGIN DEBUG ===");
                error_log("Username entered: " . $username);
                error_log("Password entered (plain): " . $password);
                error_log("Password in DB: " . $user['password']);
                error_log("Password match: " . ($password === $user['password'] ? 'YES ✓' : 'NO ✗'));
                error_log("email_verified: " . var_export($user['email_verified'], true));
                error_log("status: " . $user['status']);
                error_log("account_status: " . $user['account_status']);
                error_log("is_active: " . var_export($user['is_active'], true));
                error_log("email: " . $user['email']);
                error_log("role_name: " . $user['role_name']);
                // ═══ END DEBUG ═══

                // ── Check if account is deactivated ───────────────────────
                $is_deactivated = false;
                if (isset($user['status']) && strtolower($user['status']) === 'inactive')               $is_deactivated = true;
                if (isset($user['account_status']) && strtolower($user['account_status']) === 'deactivated') $is_deactivated = true;
                if (isset($user['is_active']) && $user['is_active'] == 0)                              $is_deactivated = true;

                if ($is_deactivated) {
                    $error = "Your account has been deactivated. Please contact the administrator.";

                } elseif ($password === $user['password']) {

                    $is_admin = (
                        strtolower($username) === 'admin' ||
                        strtolower($username) === 'superadmin' ||
                        in_array(strtolower($user['role_name'] ?? ''), ['admin', 'super admin', 'administrator'])
                    );

                    // ── STEP 1: Account not yet email-verified ─────────────────
                    // The user registered but never completed email verification.
                    // Regenerate a fresh code (old one may have expired) and send it,
                    // then redirect to the verify-email page.
                    if (!$is_admin && isset($user['email_verified']) && $user['email_verified'] == 0 && !empty($user['email'])) {

                        // Check if existing code is still valid — only regenerate if expired
                        $code_still_valid = !empty($user['verification_code'])
                            && !empty($user['verification_code_expires'])
                            && strtotime($user['verification_code_expires']) > time();

                        if (!$code_still_valid) {
                            // Existing code expired — generate and send a fresh one
                            $verify_code  = generateVerificationCode();
                            $code_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                            $upd = $conn->prepare("UPDATE tbl_users SET verification_code = ?, verification_code_expires = ? WHERE user_id = ?");
                            $upd->bind_param("ssi", $verify_code, $code_expires, $user['user_id']);
                            $upd->execute();
                            $upd->close();

                            $full_name = trim($user['full_name'] ?: $user['username']);
                            sendVerificationCodeEmail($user['email'], $full_name, $verify_code);
                        }
                        // If code is still valid, don't resend — user already has it in their inbox

                        $stmt->close();
                        header("Location: /barangaylink1/modules/auth/verify-email.php?email=" . urlencode($user['email']));
                        exit();
                    }

                    // ── STEP 2: Daily login OTP for normal users with email ──
                    elseif (!$is_admin && !empty($user['email'])) {

                        $otp_code     = generateVerificationCode();
                        $code_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));

                        $update_stmt = $conn->prepare("
                            UPDATE tbl_users
                            SET verification_code = ?, verification_code_expires = ?
                            WHERE user_id = ?
                        ");
                        $update_stmt->bind_param("ssi", $otp_code, $code_expires, $user['user_id']);

                        if ($update_stmt->execute()) {
                            $full_name = $user['full_name'] ?: $user['username'];
                            sendDailyLoginOTP($user['email'], $full_name, $otp_code);

                            // Store temporary login data in session
                            $_SESSION['temp_user_id']      = $user['user_id'];
                            $_SESSION['temp_username']     = $user['username'];
                            $_SESSION['temp_role_id']      = $user['role_id'];
                            $_SESSION['temp_role_name']    = $user['role_name'];
                            $_SESSION['temp_resident_id']  = $user['resident_id'];
                            $_SESSION['temp_is_verified']  = $user['is_verified'] ?? 1;

                            $update_stmt->close();
                            $stmt->close();

                            header("Location: /barangaylink1/modules/auth/verify-daily-otp.php?email=" . urlencode($user['email']));
                            exit();
                        } else {
                            $error = "Error sending verification code. Please try again.";
                            $update_stmt->close();
                        }

                    // ── STEP 3: Admin / users without email — direct login ──
                    } else {
                        $_SESSION = [];

                        $_SESSION['user_id']       = (int) $user['user_id'];
                        $_SESSION['username']      = $user['username'];
                        $_SESSION['role_id']       = (int) $user['role_id'];
                        $_SESSION['role']          = $user['role_name'];
                        $_SESSION['role_name']     = $user['role_name'];
                        $_SESSION['resident_id']   = $user['resident_id'];
                        $_SESSION['is_verified']   = $user['is_verified'] ?? 1;
                        $_SESSION['LAST_ACTIVITY'] = time();
                        $_SESSION['logged_in']     = true;

                        error_log("=== ADMIN LOGIN SUCCESS (No OTP) ===");
                        error_log("User ID: "  . $_SESSION['user_id']);
                        error_log("Username: " . $_SESSION['username']);
                        error_log("Role: "     . $_SESSION['role_name']);

                        $stmt->close();

                        $role = trim($user['role_name']);
                        if ($role === 'Driver') {
                            header("Location: /barangaylink1/vehicles/driver/index.php");
                        } else {
                            header("Location: /barangaylink1/modules/dashboard/index.php");
                        }
                        exit();
                    }

                } else {
                    error_log("=== PASSWORD MISMATCH === Username: $username");
                    $error = "Invalid username or password.";
                }
            } else {
                error_log("User not found: $username");
                $error = "Invalid username or password.";
            }
            if (isset($stmt) && $stmt) $stmt->close();
        } else {
            error_log("Database prepare error: " . $conn->error);
            $error = "Database error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Barangay Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* Left Side */
        .left-side {
            flex: 1;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            display: flex; flex-direction: column;
            justify-content: center; align-items: center;
            color: white; padding: 3rem;
            position: relative; overflow: hidden;
        }
        .left-side::before {
            content: '';
            position: absolute; width: 200%; height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBackground 20s linear infinite;
        }
        @keyframes moveBackground {
            0%   { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }
        .logo-container { position: relative; z-index: 1; text-align: center; }
        .logo-img {
            width: 120px; height: 120px; background: white;
            border-radius: 50%; padding: 15px; margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .system-title { font-size: 2.5rem; font-weight: 700; margin-bottom: 1rem; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
        .system-subtitle { font-size: 1.2rem; opacity: 0.9; max-width: 400px; line-height: 1.6; }

        .features-list { margin-top: 3rem; position: relative; z-index: 1; }
        .feature-item { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; font-size: 1rem; opacity: 0.95; }
        .feature-icon {
            width: 40px; height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
        }

        /* Right Side */
        .right-side {
            flex: 1; background: white;
            display: flex; align-items: center; justify-content: center;
            padding: 2rem;
        }
        .login-form-container { width: 100%; max-width: 450px; }

        .form-header { text-align: center; margin-bottom: 2.5rem; }
        .form-header h2 { color: #1e3a8a; font-size: 2rem; margin-bottom: 0.5rem; }
        .form-header p  { color: #64748b; font-size: 1rem; }

        .security-notice {
            background: #f0f9ff; border-left: 4px solid #3b82f6;
            padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;
            display: flex; align-items: start; gap: 0.75rem;
        }
        .security-notice i { color: #3b82f6; margin-top: 0.2rem; }
        .security-notice-text { flex: 1; font-size: 0.9rem; color: #1e40af; }
        .security-notice-text strong { display: block; margin-bottom: 0.25rem; }

        .alert {
            padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .alert-danger  { background: #fee2e2; color: #c62828; border-left: 4px solid #f44336; }
        .alert-warning { background: #fff3e0; color: #e65100; border-left: 4px solid #ff9800; }
        .alert-success { background: #f0fdf4; color: #15803d;  border-left: 4px solid #10b981; }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; color: #1e3a8a; font-weight: 600; margin-bottom: 0.5rem; }

        .input-wrapper { position: relative; }
        .input-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #64748b; }

        .form-control {
            width: 100%; padding: 0.875rem 1rem 0.875rem 2.75rem;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: 1rem; transition: all 0.3s;
        }
        .form-control:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        .forgot-password-link { text-align: right; margin-top: 0.5rem; }
        .forgot-password-link a {
            color: #3b82f6; text-decoration: none;
            font-size: 0.9rem; font-weight: 500; transition: color 0.3s;
        }
        .forgot-password-link a:hover { color: #1e3a8a; text-decoration: underline; }

        .btn-login {
            width: 100%; padding: 1rem;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white; border: none; border-radius: 8px;
            font-size: 1.1rem; font-weight: 600; cursor: pointer;
            transition: all 0.3s; margin-top: 1rem;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(30,58,138,0.3); }
        .btn-login:active { transform: translateY(0); }

        .form-links {
            margin-top: 2rem; text-align: center;
            padding-top: 1.5rem; border-top: 1px solid #e2e8f0;
        }
        .form-links a { color: #3b82f6; text-decoration: none; font-weight: 500; margin: 0 0.5rem; }
        .form-links a:hover { text-decoration: underline; }

        @media (max-width: 968px) {
            body { flex-direction: column; }
            .left-side { min-height: 40vh; padding: 2rem; }
            .system-title { font-size: 1.8rem; }
            .features-list { display: none; }
            .right-side { min-height: 60vh; }
        }
    </style>
</head>
<body>

    <!-- Left Side -->
    <div class="left-side">
        <div class="logo-container">
            <img src="/barangaylink1/uploads/officials/brgy.png" alt="Barangay Logo" class="logo-img" onerror="this.style.display='none'">
            <h1 class="system-title">BARANGAY MANAGEMENT<br>SYSTEM</h1>
            <p class="system-subtitle">Efficiently manage your barangay operations</p>
        </div>
        <div class="features-list">
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-users"></i></div>
                <span>Manage barangay records, residents, services, and local governance</span>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-file-alt"></i></div>
                <span>Track documents and requests in real-time</span>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <span>Secure and centralized data management</span>
            </div>
        </div>
    </div>

    <!-- Right Side -->
    <div class="right-side">
        <div class="login-form-container">
            <div class="form-header">
                <h2>Welcome Back</h2>
                <p>Sign in to your account to continue</p>
            </div>

            <div class="security-notice">
                <i class="fas fa-shield-alt"></i>
                <div class="security-notice-text">
                    <strong>Enhanced Security</strong>
                    For your protection, you'll receive a verification code via email each time you log in.
                </div>
            </div>

            <?php if (isset($_GET['deactivated'])): ?>
                <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Your session has ended because your account has been deactivated.</div>
            <?php elseif (isset($_GET['deleted'])): ?>
                <div class="alert alert-danger"><i class="fas fa-times-circle"></i> Your account no longer exists. Please contact the administrator.</div>
            <?php elseif (isset($_GET['timeout'])): ?>
                <div class="alert alert-warning"><i class="fas fa-clock"></i> Your session has expired due to inactivity. Please log in again.</div>
            <?php elseif (isset($_GET['registered']) && isset($_GET['verified'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Registration successful! You can now log in to your account.</div>
            <?php elseif (isset($_GET['registered'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Registration successful! Please check your email for the verification code.</div>
            <?php elseif (isset($_GET['verified'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Email verified successfully! You can now log in to your account.</div>
            <?php elseif (isset($_GET['logout'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> You have been successfully logged out.</div>
            <?php elseif (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> Password reset successful! You can now log in with your new password.</div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label>Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" name="username" class="form-control"
                               placeholder="Enter your username" required autofocus
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" name="password" class="form-control"
                               placeholder="Enter your password" required>
                    </div>
                    <div class="forgot-password-link">
                        <a href="/barangaylink1/modules/auth/forgot-password.php">
                            <i class="fas fa-key"></i> Forgot Password?
                        </a>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="form-links">
                <a href="/barangaylink1/modules/auth/index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
                <span style="color:#cbd5e0;">|</span>
                <a href="/barangaylink1/modules/auth/register.php">Create Account <i class="fas fa-user-plus"></i></a>
            </div>
        </div>
    </div>
</body>
</html>