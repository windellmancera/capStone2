<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member/member_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// Get user's recent approved payment
$sql = "SELECT ph.*, mp.id as plan_id, mp.name as plan_name 
        FROM payment_history ph
        LEFT JOIN membership_plans mp ON ph.plan_id = mp.id
        WHERE ph.user_id = ? 
        AND ph.payment_status = 'Approved'
        ORDER BY ph.payment_date DESC 
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$recent_payment = $result->fetch_assoc();

if ($recent_payment) {
    // Update user's selected plan
    $update_sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $recent_payment['plan_id'], $user_id);
    
    if ($update_stmt->execute()) {
        $message = "Success! Your selected plan has been updated to: " . $recent_payment['plan_name'];
    } else {
        $message = "Error updating plan: " . $conn->error;
    }
} else {
    // If no approved payment found, check for any recent payment
    $sql = "SELECT ph.*, mp.id as plan_id, mp.name as plan_name 
            FROM payment_history ph
            LEFT JOIN membership_plans mp ON ph.plan_id = mp.id
            WHERE ph.user_id = ? 
            ORDER BY ph.payment_date DESC 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_payment = $result->fetch_assoc();
    
    if ($recent_payment) {
        $message = "Found payment for: " . $recent_payment['plan_name'] . " (Status: " . $recent_payment['payment_status'] . ")";
    } else {
        $message = "No recent payments found.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix User Plan</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-md max-w-md w-full">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">Fix User Plan</h1>
        
        <div class="mb-4">
            <p class="text-gray-600"><?php echo $message; ?></p>
        </div>
        
        <div class="space-y-2">
            <a href="member/membership.php" class="block w-full bg-blue-500 text-white text-center py-2 px-4 rounded hover:bg-blue-600">
                Go to Membership Page
            </a>
            <a href="member/homepage.php" class="block w-full bg-gray-500 text-white text-center py-2 px-4 rounded hover:bg-gray-600">
                Go to Homepage
            </a>
        </div>
    </div>
</body>
</html> 