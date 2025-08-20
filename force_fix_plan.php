<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member/member_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$result_message = '';

// Step 1: Find the most recent approved payment
$payment_sql = "SELECT * FROM payment_history 
                WHERE user_id = ? AND payment_status = 'Approved' 
                ORDER BY payment_date DESC LIMIT 1";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $user_id);
$payment_stmt->execute();
$approved_payment = $payment_stmt->get_result()->fetch_assoc();

if ($approved_payment) {
    // Step 2: Find the Monthly Plan (since user mentioned they paid for Monthly Plan)
    $plan_sql = "SELECT * FROM membership_plans WHERE name LIKE '%Monthly%' OR name LIKE '%monthly%' LIMIT 1";
    $plan_result = $conn->query($plan_sql);
    $monthly_plan = $plan_result->fetch_assoc();
    
    if ($monthly_plan) {
        // Step 3: Update user's selected plan
        $update_sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $monthly_plan['id'], $user_id);
        
        if ($update_stmt->execute()) {
            $result_message = "SUCCESS! Your plan has been set to: " . $monthly_plan['name'];
            
            // Step 4: Also update the payment record to link it to the plan
            $update_payment_sql = "UPDATE payment_history SET plan_id = ? WHERE id = ?";
            $update_payment_stmt = $conn->prepare($update_payment_sql);
            $update_payment_stmt->bind_param("ii", $monthly_plan['id'], $approved_payment['id']);
            $update_payment_stmt->execute();
            
        } else {
            $result_message = "ERROR: Could not update plan. Database error: " . $conn->error;
        }
    } else {
        $result_message = "ERROR: Monthly Plan not found in database";
    }
} else {
    // If no approved payment, check for any recent payment
    $any_payment_sql = "SELECT * FROM payment_history WHERE user_id = ? ORDER BY payment_date DESC LIMIT 1";
    $any_payment_stmt = $conn->prepare($any_payment_sql);
    $any_payment_stmt->bind_param("i", $user_id);
    $any_payment_stmt->execute();
    $any_payment = $any_payment_stmt->get_result()->fetch_assoc();
    
    if ($any_payment) {
        $result_message = "Found payment with status: " . $any_payment['payment_status'] . " - Amount: ₱" . $any_payment['amount'];
    } else {
        $result_message = "No payments found for this user";
    }
}

// Get current user status
$user_sql = "SELECT u.*, mp.name as plan_name FROM users u 
             LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
             WHERE u.id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$current_user = $user_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Force Fix Plan</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full">
        <h1 class="text-2xl font-bold text-gray-800 mb-6 text-center">Force Fix Plan</h1>
        
        <div class="mb-6">
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <strong>Result:</strong> <?php echo $result_message; ?>
            </div>
        </div>
        
        <div class="bg-blue-50 p-4 rounded mb-6">
            <h3 class="font-semibold text-gray-800 mb-2">Current Status:</h3>
            <p><strong>User ID:</strong> <?php echo $current_user['id']; ?></p>
            <p><strong>Username:</strong> <?php echo $current_user['username']; ?></p>
            <p><strong>Selected Plan ID:</strong> <?php echo $current_user['selected_plan_id'] ?? 'NULL'; ?></p>
            <p><strong>Plan Name:</strong> <?php echo $current_user['plan_name'] ?? 'No Plan'; ?></p>
        </div>
        
        <div class="space-y-3">
            <a href="member/membership.php" class="block w-full bg-blue-500 text-white text-center py-3 px-4 rounded-lg hover:bg-blue-600 font-semibold">
                Check Membership Page
            </a>
            <a href="member/homepage.php" class="block w-full bg-green-500 text-white text-center py-3 px-4 rounded-lg hover:bg-green-600 font-semibold">
                Go to Dashboard
            </a>
            <a href="check_user_payment.php" class="block w-full bg-gray-500 text-white text-center py-3 px-4 rounded-lg hover:bg-gray-600 font-semibold">
                Run Full Diagnostic
            </a>
        </div>
    </div>
</body>
</html> 