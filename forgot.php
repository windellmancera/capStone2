<?php
require 'db.php';

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    
    // Check if email exists
    $sql = "SELECT id, username, email FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Generate unique token
        $token = bin2hex(random_bytes(32));
        
        // Delete any existing tokens for this user
        $delete_sql = "DELETE FROM password_reset_tokens WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $user['id']);
        $delete_stmt->execute();
        
        // Insert new token; compute expiry using DB time to avoid timezone mismatch
        $insert_sql = "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("is", $user['id'], $token);
        
        if ($insert_stmt->execute()) {
            // Create reset link
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
            
            // For now, we'll show the link directly (in production, send via email)
            $message = "Password reset link generated successfully!<br><br>";
            $message .= "<strong>Reset Link:</strong><br>";
            $message .= "<a href='$reset_link' style='color: #4CAF50; text-decoration: underline;'>$reset_link</a><br><br>";
            $message .= "<small>This link will expire in 1 hour.</small>";
            $message_type = "success";
        } else {
            $message = "Error generating reset link. Please try again.";
            $message_type = "error";
        }
    } else {
        $message = "Email not found in our system.";
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - Almo Fitness Gym</title>
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

        .forgot-box {
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

        .forgot-box h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #ffffff;
            text-shadow: 1px 1px 3px #000;
        }

        .forgot-box input[type="email"] {
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

        .forgot-box input::placeholder {
            color: #e0e0e0;
        }

        .forgot-box button {
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

        .forgot-box button:hover {
            background: rgba(93, 173, 226, 1);
        }

        .forgot-box p {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            color: #f0f0f0;
        }

        .forgot-box .message {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 15px;
            border-radius: 8px;
            text-align: left;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.4;
        }

        .forgot-box .message.success {
            background-color: rgba(76, 175, 80, 0.2);
            border-left: 4px solid #4CAF50;
        }

        .forgot-box .message.error {
            background-color: rgba(244, 67, 54, 0.2);
            border-left: 4px solid #f44336;
        }

        .forgot-box a {
            color: #dcdcdc;
            text-decoration: none;
        }

        .forgot-box a:hover {
            text-decoration: underline;
        }

        .reset-link {
            word-break: break-all;
            background: rgba(255, 255, 255, 0.1);
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-size: 12px;
        }

        @media (max-width: 400px) {
            .forgot-box {
                width: 90%;
                padding: 30px;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-box">
        <h2>Forgot Password</h2>
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($message) || $message_type === "error"): ?>
            <form method="post">
                <input type="email" name="email" placeholder="Enter your email" required>
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
        
        <p><a href="index.php">Back to Login</a></p>
    </div>
</body>
</html>
