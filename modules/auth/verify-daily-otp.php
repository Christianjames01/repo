<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/email_helper.php';

// START SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_log("=== DAILY OTP VERIFICATION PAGE ===");
error_log("Session ID: " . session_id());
error_log("Session data: " . print_r($_SESSION, true));

// Check if user is already fully logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $role = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
    if ($role === 'Driver') {
        header("Location: /barangaylink1/vehicles/driver/index.php");
        exit();
    } else {
        header("Location: /barangaylink1/modules/dashboard/index.php");
        exit();
    }
}

// Check if temporary session data exists
if (!isset($_SESSION['temp_user_id']) || empty($_SESSION['temp_user_id'])) {
    header("Location: /barangaylink1/modules/auth/login.php");
    exit();
}

$error = '';
$success = '';
$email = '';

// Get email from URL
if (isset($_GET['email'])) {
    $email = trim($_GET['email']);
} else {
    header("Location: /barangaylink1/modules/auth/login.php");
    exit();
}

// Handle resend
if (isset($_POST['resend_code']) && $_POST['resend_code'] == '1') {
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, CONCAT(r.first_name, ' ', r.last_name) as full_name
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.email = ? AND u.user_id = ?
    ");
    $stmt->bind_param("si", $email, $_SESSION['temp_user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $new_code = generateVerificationCode();
        $code_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        $update_stmt = $conn->prepare("UPDATE tbl_users SET verification_code = ?, verification_code_expires = ? WHERE user_id = ?");
        $update_stmt->bind_param("ssi", $new_code, $code_expires, $user['user_id']);
        
        if ($update_stmt->execute()) {
            $full_name = $user['full_name'] ?: $user['username'];
            sendDailyLoginOTP($email, $full_name, $new_code);
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Error sending new code. Please try again.";
        }
        $update_stmt->close();
    } else {
        $error = "Invalid request. Please login again.";
    }
    $stmt->close();
}
// Handle verification
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verification_code'])) {
    $verification_code = trim($_POST['verification_code']);
    
    error_log("=== DAILY OTP VERIFICATION ATTEMPT ===");
    error_log("Code: " . $verification_code);
    error_log("Temp User ID: " . $_SESSION['temp_user_id']);
    
    if (empty($verification_code)) {
        $error = "Please enter the verification code.";
    } elseif (!preg_match('/^[0-9]{6}$/', $verification_code)) {
        $error = "Code must be 6 digits.";
    } else {
        $stmt = $conn->prepare("
            SELECT u.user_id, u.username, u.role_id, u.resident_id, 
                   r.role_name, u.verification_code, u.verification_code_expires,
                   res.is_verified
            FROM tbl_users u
            LEFT JOIN tbl_roles r ON u.role_id = r.role_id
            LEFT JOIN tbl_residents res ON u.resident_id = res.resident_id
            WHERE u.email = ? AND u.user_id = ?
        ");
        $stmt->bind_param("si", $email, $_SESSION['temp_user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($user['verification_code'] !== $verification_code) {
                $error = "Invalid verification code.";
            } elseif (strtotime($user['verification_code_expires']) < time()) {
                $error = "Verification code has expired. Please request a new one.";
            } else {
                // Clear the verification code after successful verification
                $update_stmt = $conn->prepare("
                    UPDATE tbl_users 
                    SET verification_code = NULL, verification_code_expires = NULL 
                    WHERE user_id = ?
                ");
                $update_stmt->bind_param("i", $user['user_id']);
                
                if ($update_stmt->execute()) {
                    $update_stmt->close();
                    $stmt->close();
                    
                    // Clear temporary session data
                    unset($_SESSION['temp_user_id']);
                    unset($_SESSION['temp_username']);
                    unset($_SESSION['temp_role_id']);
                    unset($_SESSION['temp_role_name']);
                    unset($_SESSION['temp_resident_id']);
                    unset($_SESSION['temp_is_verified']);
                    
                    // SET ACTUAL SESSION VARIABLES
                    $_SESSION['user_id'] = (int)$user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role_id'] = (int)$user['role_id'];
                    $_SESSION['role'] = $user['role_name'];
                    $_SESSION['role_name'] = $user['role_name'];
                    $_SESSION['resident_id'] = $user['resident_id'];
                    $_SESSION['is_verified'] = $user['is_verified'] ?? 1;
                    $_SESSION['LAST_ACTIVITY'] = time();
                    $_SESSION['logged_in'] = true;
                    
                    // Regenerate session ID for security
                    session_regenerate_id(true);
                    
                    error_log("=== DAILY OTP VERIFIED - SESSION CREATED ===");
                    error_log("User ID: " . $_SESSION['user_id']);
                    error_log("Username: " . $_SESSION['username']);
                    error_log("Role: " . $_SESSION['role_name']);
                    error_log("Logged In: " . ($_SESSION['logged_in'] ? 'YES' : 'NO'));
                    error_log("Session ID: " . session_id());
                    
                    // Close connection
                    $conn->close();
                    
                    // Role-based redirect
                    $role = trim($user['role_name']);
                    if ($role === 'Driver') {
                        header("Location: /barangaylink1/vehicles/driver/index.php");
                        exit();
                    } else {
                        header("Location: /barangaylink1/modules/dashboard/index.php");
                        exit();
                    }
                    
                } else {
                    $error = "Error completing verification. Please try again.";
                    $update_stmt->close();
                }
            }
        } else {
            $error = "Invalid request. Please login again.";
        }
        
        if (isset($stmt)) {
            $stmt->close();
        }
    }
}

// Get remaining time
$remaining_time = '';
if (!empty($email) && isset($_SESSION['temp_user_id'])) {
    $stmt = $conn->prepare("SELECT verification_code_expires FROM tbl_users WHERE email = ? AND user_id = ?");
    $stmt->bind_param("si", $email, $_SESSION['temp_user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $expires = strtotime($row['verification_code_expires']);
        $now = time();
        if ($expires > $now) {
            $remaining_minutes = ceil(($expires - $now) / 60);
            $remaining_time = $remaining_minutes . ' minute' . ($remaining_minutes != 1 ? 's' : '');
        }
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Login Verification | Barangay Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            padding: 2rem;
        }
        .verify-container {
            background: white;
            border-radius: 16px;
            padding: 3rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .verify-header { text-align: center; margin-bottom: 2rem; }
        .verify-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        .verify-icon i { font-size: 2.5rem; color: white; }
        .verify-header h2 { color: #1e3a8a; font-size: 2rem; margin-bottom: 0.5rem; }
        .verify-header p { color: #64748b; font-size: 1rem; line-height: 1.6; }
        .security-badge {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: start;
            gap: 0.75rem;
        }
        .security-badge i { color: #3b82f6; margin-top: 0.2rem; }
        .security-badge-text {
            flex: 1;
            font-size: 0.9rem;
            color: #1e40af;
        }
        .security-badge-text strong {
            display: block;
            margin-bottom: 0.25rem;
        }
        .email-display {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 600;
            color: #1e3a8a;
            word-break: break-all;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .alert-danger { background: #fee; color: #c62828; border-left: 4px solid #f44336; }
        .alert-success { background: #f0fdf4; color: #15803d; border-left: 4px solid #10b981; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; color: #1e3a8a; font-weight: 600; margin-bottom: 0.5rem; }
        .code-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5rem;
            font-weight: 600;
            font-family: monospace;
            transition: all 0.3s;
        }
        .code-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .helper-text { 
            font-size: 0.85rem; 
            color: #64748b; 
            margin-top: 0.5rem; 
            text-align: center;
        }
        .helper-text.remaining {
            color: #15803d;
            font-weight: 600;
        }
        .btn-verify {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-verify:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(30, 58, 138, 0.3); }
        .btn-verify:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-resend {
            width: 100%;
            padding: 0.875rem;
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
        }
        .btn-resend:hover { background: #f1f5f9; }
        .divider { text-align: center; margin: 1.5rem 0; color: #94a3b8; font-size: 0.9rem; }
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        .back-link a { color: #3b82f6; text-decoration: none; font-weight: 500; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-header">
            <div class="verify-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <h2>Daily Login Verification</h2>
            <p>For your security, please verify your identity with the code we sent to your email.</p>
        </div>

        <div class="security-badge">
            <i class="fas fa-info-circle"></i>
            <div class="security-badge-text">
                <strong>Why am I seeing this?</strong>
                Daily email verification helps protect your account from unauthorized access.
            </div>
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

        <form method="POST">
            <div class="form-group">
                <label>Verification Code</label>
                <input type="text" name="verification_code" id="verification_code"
                       class="code-input" placeholder="000000" maxlength="6" 
                       pattern="[0-9]{6}" required autofocus autocomplete="off">
                <?php if ($remaining_time): ?>
                    <p class="helper-text remaining">
                        <i class="fas fa-clock"></i> Code expires in <?php echo $remaining_time; ?>
                    </p>
                <?php else: ?>
                    <p class="helper-text">Code expires in 30 minutes</p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-verify" id="verifyBtn">
                <i class="fas fa-check-circle"></i> Verify & Continue
            </button>
        </form>

        <div class="divider">Didn't receive the code?</div>

        <form method="POST">
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
        document.getElementById('verification_code').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        document.querySelector('form').addEventListener('submit', function(e) {
            const verifyBtn = document.getElementById('verifyBtn');
            if (verifyBtn) {
                verifyBtn.disabled = true;
                verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
            }
        });
    </script>
</body>
</html>