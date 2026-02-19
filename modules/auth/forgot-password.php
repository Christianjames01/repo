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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if email exists in database
        $stmt = $conn->prepare("
            SELECT u.user_id, u.username, u.email, CONCAT(r.first_name, ' ', r.last_name) as full_name
            FROM tbl_users u
            LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
            WHERE u.email = ? AND u.account_status = 'Active' AND u.is_active = 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Generate 6-digit verification code
            $reset_code = sprintf("%06d", mt_rand(0, 999999));
            $code_expires = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            
            // Store reset code in database
            $update_stmt = $conn->prepare("
                UPDATE tbl_users 
                SET verification_code = ?, 
                    verification_code_expires = ? 
                WHERE user_id = ?
            ");
            $update_stmt->bind_param("ssi", $reset_code, $code_expires, $user['user_id']);
            
            if ($update_stmt->execute()) {
                // Send reset code via email
                $full_name = $user['full_name'] ?: $user['username'];
                
                if (sendPasswordResetEmail($email, $full_name, $reset_code)) {
                    $stmt->close();
                    $update_stmt->close();
                    
                    // Redirect to verification page
                    header("Location: /barangaylink1/modules/auth/verify-reset-code.php?email=" . urlencode($email));
                    exit();
                } else {
                    $error = "Failed to send verification email. Please try again.";
                }
            } else {
                $error = "An error occurred. Please try again later.";
            }
            
            $update_stmt->close();
        } else {
            // For security, show success message even if email doesn't exist
            // This prevents email enumeration attacks
            $success = "If an account exists with this email, you will receive a password reset code shortly.";
        }
        
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password | Barangay Management System</title>
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

        .forgot-container {
            background: white;
            border-radius: 16px;
            padding: 3rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .forgot-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .forgot-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }

        .forgot-icon i {
            font-size: 2.5rem;
            color: white;
        }

        .forgot-header h2 {
            color: #1e3a8a;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .forgot-header p {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.6;
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

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 2.75rem;
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

        .info-box {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #475569;
        }

        .info-box i {
            color: #3b82f6;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-header">
            <div class="forgot-icon">
                <i class="fas fa-key"></i>
            </div>
            <h2>Forgot Password?</h2>
            <p>Enter your email address and we'll send you a verification code to reset your password.</p>
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

        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            You will receive a 6-digit verification code that expires in 30 minutes.
        </div>

        <form method="POST" id="forgotForm">
            <div class="form-group">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" name="email" class="form-control" 
                           placeholder="Enter your email address" required autofocus
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
            </div>

            <button type="submit" class="btn-reset" id="submitBtn">
                <i class="fas fa-paper-plane"></i> Send Verification Code
            </button>
        </form>

        <div class="back-link">
            <a href="/barangaylink1/modules/auth/login.php">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
        document.getElementById('forgotForm').addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        });
    </script>
</body>
</html>