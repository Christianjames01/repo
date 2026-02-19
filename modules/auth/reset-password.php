<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';

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
$code = '';

// Get email and code from URL
if (isset($_GET['email']) && isset($_GET['code'])) {
    $email = trim($_GET['email']);
    $code = trim($_GET['code']);
} else {
    header("Location: /barangaylink1/modules/auth/forgot-password.php");
    exit();
}

// Verify the code is still valid
$stmt = $conn->prepare("
    SELECT user_id, verification_code, verification_code_expires
    FROM tbl_users
    WHERE email = ? AND account_status = 'Active'
");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $stmt->close();
    header("Location: /barangaylink1/modules/auth/forgot-password.php");
    exit();
}

$user = $result->fetch_assoc();

// Verify code matches and hasn't expired
if ($user['verification_code'] !== $code || strtotime($user['verification_code_expires']) < time()) {
    $stmt->close();
    header("Location: /barangaylink1/modules/auth/verify-reset-code.php?email=" . urlencode($email) . "&error=expired");
    exit();
}

$stmt->close();

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $error = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $error = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $error = "Password must contain at least one number.";
    } else {
        // Update password (storing as plain text as per your current system)
        $update_stmt = $conn->prepare("
            UPDATE tbl_users 
            SET password = ?, 
                verification_code = NULL, 
                verification_code_expires = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        $update_stmt->bind_param("si", $new_password, $user['user_id']);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            
            // Redirect to login with success message
            header("Location: /barangaylink1/modules/auth/login.php?reset=success");
            exit();
        } else {
            $error = "Failed to reset password. Please try again.";
        }
        
        $update_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | Barangay Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            padding: 2rem;
        }

        .reset-container {
            background: white;
            border-radius: 16px;
            padding: 3rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .reset-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .reset-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }

        .reset-icon i {
            font-size: 2.5rem;
            color: white;
        }

        .reset-header h2 {
            color: #1e3a8a;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .reset-header p {
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
            font-size: 0.9rem;
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

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
        }

        .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            cursor: pointer;
            transition: color 0.3s;
        }

        .toggle-password:hover {
            color: #3b82f6;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 2.75rem 0.875rem 2.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .password-requirements {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }

        .password-requirements h4 {
            color: #1e3a8a;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .password-requirements ul {
            list-style: none;
            padding: 0;
        }

        .password-requirements li {
            color: #64748b;
            margin-bottom: 0.25rem;
            padding-left: 1.5rem;
            position: relative;
        }

        .password-requirements li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #10b981;
            font-weight: bold;
        }

        .btn-reset {
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

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 58, 138, 0.3);
        }

        .btn-reset:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
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
    <div class="reset-container">
        <div class="reset-header">
            <div class="reset-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h2>Reset Password</h2>
            <p>Enter your new password below</p>
        </div>

        <div class="email-display">
            <i class="fas fa-user"></i>
            <?php echo htmlspecialchars($email); ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <div class="password-requirements">
            <h4><i class="fas fa-info-circle"></i> Password Requirements:</h4>
            <ul>
                <li>At least 8 characters long</li>
                <li>Contains uppercase letter (A-Z)</li>
                <li>Contains lowercase letter (a-z)</li>
                <li>Contains number (0-9)</li>
            </ul>
        </div>

        <form method="POST" id="resetForm">
            <div class="form-group">
                <label>New Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="new_password" id="new_password"
                           class="form-control" placeholder="Enter new password" required>
                    <i class="fas fa-eye toggle-password" id="toggleNew"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="form-control" placeholder="Confirm new password" required>
                    <i class="fas fa-eye toggle-password" id="toggleConfirm"></i>
                </div>
            </div>

            <button type="submit" class="btn-reset" id="submitBtn">
                <i class="fas fa-check-circle"></i> Reset Password
            </button>
        </form>

        <div class="back-link">
            <a href="/barangaylink1/modules/auth/login.php">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('toggleNew').addEventListener('click', function() {
            const passwordField = document.getElementById('new_password');
            const icon = this;
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        document.getElementById('toggleConfirm').addEventListener('click', function() {
            const passwordField = document.getElementById('confirm_password');
            const icon = this;
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Form submission
        document.getElementById('resetForm').addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
        });
    </script>
</body>
</html>