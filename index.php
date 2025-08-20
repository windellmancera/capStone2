<?php
session_start();

// If user is already logged in, redirect to appropriate page
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: index.php");
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
    
    $sql = "SELECT * FROM users WHERE email = ?";
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
            
            // Redirect based on role
            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            } else {
                header("Location: member/homepage.php");
            }
            exit();
            if ($user['role'] === 'user') {
                header("Location: member/homepage.php");
            }
            exit();
        } else {
            $error = "Invalid password";
        }
    } else {
        $error = "Email not found";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Almo Fitness Gym</title>
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

        /* Optional: dark overlay for better readability */
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
            box-shadow: 0 8px 32px rgba(220, 53, 69, 0.6);
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
            background: rgba(255, 255, 255, 0.2);
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
            background: rgba(113, 82, 82, 0.45);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .login-box button:last-of-type {
            margin-bottom: 16px;
        }

        .login-box button:hover {
            background: rgba(113, 82, 82, 0.65);
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
        }

        .login-box a:hover {
            text-decoration: underline;
        }

        .login-box .error {
            background-color: rgba(255, 0, 0, 0.1);
            color: #ff6b6b;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }

        @media (max-width: 400px) {
            .login-box {
                width: 90%;
                padding: 30px;
            }
        }

        .login-type-toggle {
            text-align: center;
            margin-bottom: 16px;
        }

        .login-type-toggle button {
            background: rgba(113, 82, 82, 0.45);
            border: none;
            margin: 0 6px 12px;
            color: #dcdcdc;
            padding: 5px 15px;
            cursor: pointer;
            font-size: 14px;
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .login-type-toggle button.active {
            opacity: 1;
            border-bottom: 2px solid #5DADE2;
        }

        .admin-notice {
            font-size: 12px;
            color: #ff6b6b;
            text-align: center;
            margin-top: 10px;
            display: none;
        }

        .admin-notice.visible {
            display: block;
        }

        .role-button {
            width: 100%;
            padding: 12px;
            margin: 8px 0;
            background: rgba(113, 82, 82, 0.45);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .role-button:hover {
            background: rgba(113, 82, 82, 0.65);
            transform: translateY(-1px);
        }

        .role-button:active {
            transform: translateY(1px);
        }
    </style>
</head>
<body>
    <div class="login-box">
        <img src="image/almo.jpg" alt="Almo Logo">
        <h2>Login</h2>
        <div class="subtitle">Select your role</div>
        
        <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
        
        <button type="button" onclick="window.location.href='member/member_login.php'" class="role-btn">Member</button>
        <button type="button" onclick="window.location.href='admin/admin_login.php'" class="role-btn">Admin</button>
        
        <p><a href="signup.php">Sign Up</a> | <a href="forgot.php">Forgot Password?</a></p>
    </div>

    <script>
        function toggleLoginType(type) {
            const buttons = document.querySelectorAll('.login-type-toggle button');
            const adminNotice = document.getElementById('adminNotice');
            const signupLink = document.querySelector('a[href="signup.php"]').parentElement;
            
            buttons.forEach(button => button.classList.remove('active'));
            event.target.classList.add('active');
            
            if (type === 'admin') {
                adminNotice.classList.add('visible');
                signupLink.style.display = 'none';
            } else {
                adminNotice.classList.remove('visible');
                signupLink.style.display = 'block';
            }
        }
    </script>
</body>
</html>
