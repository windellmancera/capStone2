<?php
require 'db.php';

$message = "";
$message_type = "";
$token_valid = false;
$user_id = null;

// Check if token is provided
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Check if token exists and is not expired
    $sql = "SELECT prt.user_id, prt.expires_at, u.username, u.email 
            FROM password_reset_tokens prt 
            JOIN users u ON prt.user_id = u.id 
            WHERE prt.token = ? AND prt.expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $token_data = $result->fetch_assoc();
        $user_id = $token_data['user_id'];
        $token_valid = true;
    } else {
        $message = "Invalid or expired reset link. Please request a new password reset.";
        $message_type = "error";
    }
} else {
    $message = "No reset token provided.";
    $message_type = "error";
}

// Handle password reset form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && $token_valid) {
    $new_password = $_POST["new_password"];
    $confirm_password = $_POST["confirm_password"];
    
    if (strlen($new_password) < 6) {
        $message = "Password must be at least 6 characters long.";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update user password
        $update_sql = "UPDATE users SET password = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($update_stmt->execute()) {
            // Delete the used token
            $delete_sql = "DELETE FROM password_reset_tokens WHERE token = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("s", $token);
            $delete_stmt->execute();
            
            $message = "Password reset successfully! You can now login with your new password.";
            $message_type = "success";
            $token_valid = false; // Hide the form after successful reset
        } else {
            $message = "Error updating password. Please try again.";
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - Almo Fitness Gym</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url('https://www.pixelstalk.net/wp-content/uploads/2016/06/Black-And-Red-Background-HD.jpg') no-repeat center center fixed;
            background-size: cover;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            z-index: 0;
        }

        .reset-box {
            position: relative;
            z-index: 1;
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.37);
            padding: 40px;
            width: 400px;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            animation: fadeIn 1s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .reset-box h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #ffffff;
            text-shadow: 1px 1px 3px #000;
        }

        .reset-box input[type="password"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0 20px;
            border: none;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            font-size: 14px;
            outline: none;
        }

        .reset-box input::placeholder {
            color: #e0e0e0;
        }

        .reset-box button {
            width: 100%;
            padding: 12px;
            background: rgba(93, 173, 226, 0.7);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .reset-box button:hover {
            background: rgba(93, 173, 226, 1);
        }

        .reset-box p {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            color: #f0f0f0;
        }

        .reset-box .message {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 15px;
            border-radius: 8px;
            text-align: left;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.4;
        }

        .reset-box .message.success {
            background-color: rgba(76, 175, 80, 0.2);
            border-left: 4px solid #4CAF50;
        }

        .reset-box .message.error {
            background-color: rgba(244, 67, 54, 0.2);
            border-left: 4px solid #f44336;
        }

        .reset-box a {
            color: #dcdcdc;
            text-decoration: none;
        }

        .reset-box a:hover {
            text-decoration: underline;
        }

        .password-requirements {
            font-size: 12px;
            color: #ccc;
            margin-top: 5px;
        }

        @media (max-width: 400px) {
            .reset-box {
                width: 90%;
                padding: 30px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-box">
        <h2>Reset Password</h2>
        
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($token_valid): ?>
            <form method="post">
                <input type="password" name="new_password" placeholder="Enter new password" required>
                <div class="password-requirements">Password must be at least 6 characters long</div>
                
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
        
        <p><a href="index.php">Back to Login</a></p>
        <?php if (!$token_valid && empty($message)): ?>
            <p><a href="forgot.php">Request New Reset Link</a></p>
        <?php endif; ?>
    </div>
</body>
</html> 