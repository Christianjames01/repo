<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/email_helper.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0 && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: /barangaylink1/modules/dashboard/index.php");
    exit();
}

$error = '';
$success = '';
$email = '';

// Get email from URL
if (isset($_GET['email'])) {
    $email = trim($_GET['email']);
} else {
    header("Location: /barangaylink1/modules/auth/forgot-password.php");
    exit();
}

// Handle resend code
if (isset($_POST['resend_code']) && $_POST['resend_code'] == '1') {
    $stmt = $conn->prepare("
        SELECT u.user_id, u.username, CONCAT(r.first_name, ' ', r.last_name) as full_name
        FROM tbl_users u
        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
        WHERE u.email = ? AND u.account_status = 'Active'
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $new_code = sprintf("%06d", mt_rand(0, 999999));
        $code_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        
        $update_stmt = $conn->prepare("
            UPDATE tbl_users 
            SET verification_code = ?, verification_code_expires = ? 
            WHERE user_id = ?
        ");
        $update_stmt->bind_param("ssi", $new_code, $code_expires, $user['user_id']);
        
        if ($update_stmt->execute()) {
            $full_name = $user['full_name'] ?: $user['username'];
            sendPasswordResetEmail($email, $full_name, $new_code);
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Error sending new code. Please try again.";
        }
        $update_stmt->close();
    } else {
        $error = "Email not found.";
    }
    $stmt->close();
}
// Handle verification
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verification_code'])) {
    $verification_code = trim($_POST['verification_code']);
    
    if (empty($verification_code)) {
        $error = "Please enter the verification code.";
    } elseif (!preg_match('/^[0-9]{6}$/', $verification_code)) {
        $error = "Verification code must be 6 digits.";
    } else {
        $stmt = $conn->prepare("
            SELECT user_id, verification_code, verification_code_expires
            FROM tbl_users
            WHERE email = ? AND account_status = 'Active'
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($user['verification_code'] !== $verification_code) {
                $error = "Invalid verification code.";
            } elseif (strtotime($user['verification_code_expires']) < time()) {
                $error = "Verification code has expired. Please request a new one.";
            } else {
                // Code is valid, redirect to reset password page
                $stmt->close();
                header("Location: /barangaylink1/modules/auth/reset-password.php?email=" . urlencode($email) . "&code=" . urlencode($verification_code));
                exit();
            }
        } else {
            $error = "Invalid request.";
        }
        
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Reset Code | Barangay Management System</title>
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

        .verify-header {
            text-align: center;
            margin-bottom: 2rem;
        }

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

        .verify-icon i {
            font-size: 2.5rem;
            color: white;
        }

        .verify-header h2 {
            color: #1e3a8a;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .verify-header p {
            color: #64748b;
            font-size: 1rem;
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

        .alert-danger {
            background: #fee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }

        .alert-success {
            background: #f0fdf4;
            color: #15803d;
            border-left: 4px solid #10b981;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            color: #1e3a8a;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

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

        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 58, 138, 0.3);
        }

        .btn-verify:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

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

        .btn-resend:hover {
            background: #f1f5f9;
        }

        .divider {
            text-align: center;
            margin: 1.5rem 0;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }

        .back-link a {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-header">
            <div class="verify-icon">
                <i class="fas fa-shield-alt"></i>
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

        <form method="POST">
            <div class="form-group">
                <label>Verification Code</label>
                <input type="text" name="verification_code" id="verification_code"
                       class="code-input" placeholder="000000" maxlength="6" 
                       pattern="[0-9]{6}" required autofocus autocomplete="off">
                <p class="helper-text">Code expires in 30 minutes</p>
            </div>
            <button type="submit" class="btn-verify" id="verifyBtn">
                <i class="fas fa-check-circle"></i> Verify Code
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
            <a href="/barangaylink1/modules/auth/forgot-password.php">
                <i class="fas fa-arrow-left"></i> Back to Forgot Password
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