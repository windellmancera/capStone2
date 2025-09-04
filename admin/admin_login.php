<?php
require_once '../db.php';
session_start();

// If user is already logged in, redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: dashboard.php");
    } else {
        header("Location: ../member/homepage.php");
    }
    exit();
}

// Database connection
$conn = mysqli_connect("localhost", "root", "", "almo_fitness_db");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$error = "";

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];
    
    $sql = "SELECT * FROM users WHERE email = ? AND role = 'admin'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        if (password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid password";
        }
    } else {
        $error = "Email not found or you don't have administrative access";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - Almo Fitness Gym</title>
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

        .login-box {
            position: relative;
            z-index: 1;
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.12);
            border-radius: 20px;
            box-shadow: 0 0 32px 4px #b91c1c;
            padding: 40px;
            width: 340px;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.25);
            animation: fadeIn 1s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-box img {
            display: block;
            margin: 0 auto 20px;
            width: 90px;
            border-radius: 50%;
            border: 2px solid white;
        }

        .login-box h2 {
            text-align: center;
            margin-bottom: 8px;
            color: #ffffff;
            text-shadow: 1px 1px 3px #000;
        }

        .login-box .subtitle {
            text-align: center;
            margin-bottom: 20px;
            color: #e0e0e0;
            font-size: 14px;
            opacity: 0.8;
        }

        .login-box input[type="email"],
        .login-box input[type="password"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0 16px;
            border: none;
            border-radius: 10px;
            background: hsla(0, 0.00%, 100.00%, 0.20);
            color: #fff;
            font-size: 14px;
            outline: none;
        }

        .login-box input::placeholder {
            color: #e0e0e0;
        }

        .login-box button {
            width: 100%;
            padding: 12px;
            margin-bottom: 14px;
            background: #b91c1c; /* red-700 */
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .login-box button:hover {
            background: #991b1b; /* red-800 */
            transform: translateY(-1px);
        }

        .login-box button:active {
            transform: translateY(1px);
        }

        .login-box p {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            color: #f0f0f0;
        }

        .login-box a {
            color: #dcdcdc;
            text-decoration: none;
            margin: 0 10px;
            transition: color 0.3s ease;
        }

        .login-box a:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .error {
            background-color: rgba(255, 0, 0, 0.1);
            color: #ff6b6b;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease;
        }

        .admin-notice {
            text-align: center;
            margin-top: 15px;
            font-size: 12px;
            color: #ff6b6b;
        }

        @media (max-width: 400px) {
            .login-box {
                width: 90%;
                padding: 30px;
            }
        }

        .back-button {
            position: absolute;
            top: 20px;
            left: 20px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            z-index: 2;
            display: flex;
            align-items: center;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .back-button:hover {
            opacity: 1;
        }

        .back-button::before {
            content: "←";
            margin-right: 5px;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <a href="../index.php" class="back-button">Back to Role Selection</a>
    
    <div class="login-box">
        <img src="../image/almo.jpg" alt="Almo Logo">
        <h2>Admin Login</h2>
        <div class="subtitle">Administrator Access</div>

        <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
        
        <form method="post">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
        <div class="admin-notice">
            This login is restricted to authorized administrators only.
        </div>
        <p>
            <a href="../forgot.php">Forgot Password?</a>
        </p>
    </div>
</body>
</html> 