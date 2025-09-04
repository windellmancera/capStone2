<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

// Get user information
$user_id = $_SESSION['user_id'];
$sql = "SELECT username, email FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Set username from database or use email as fallback
$username = $user['username'] ?? $user['email'];

// Check if coming from recommendations
$from_recommendations = isset($_GET['from_recommendations']) && $_GET['from_recommendations'] == '1';
$recommended_schedule = null;

if ($from_recommendations && isset($_SESSION['recommended_schedule'])) {
    $recommended_schedule = $_SESSION['recommended_schedule'];
    // Clear the session data after using it
    unset($_SESSION['recommended_schedule']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule - Almo Fitness Gym</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            background-color: #f4f6f9;
        }

        .navbar {
            background-color: #333;
            padding: 1rem;
            color: white;
        }

        .navbar-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }

        .navbar-menu {
            display: flex;
            gap: 1rem;
        }

        .navbar-menu a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .navbar-menu a:hover {
            background-color: #555;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        h1 {
            color: #333;
            margin-bottom: 2rem;
            text-align: center;
        }

        .logout-btn {
            background-color: #dc3545;
            color: white !important;
        }

        .logout-btn:hover {
            background-color: #c82333 !important;
        }

        @media (max-width: 768px) {
            .navbar-container {
                flex-direction: column;
                gap: 1rem;
            }
            
            .navbar-menu {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-container">
            <a href="homepage.php" class="navbar-brand">Almo Fitness Gym</a>
            <div class="navbar-menu">
                <a href="homepage.php">Home</a>
                <a href="profile.php">Profile</a>
                <a href="manage_membership.php">Membership</a>
                <a href="payment.php">Payment</a>
                <a href="schedule.php">Schedule</a>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <h1>Class Schedule</h1>
        <div class="card">
            <p>Schedule feature coming soon!</p>
        </div>
    </div>
</body>
</html> 