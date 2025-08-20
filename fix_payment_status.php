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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_payment_status'])) {
    // Get the most recent approved payment
    $payment_sql = "SELECT * FROM payment_history 
                    WHERE user_id = ? AND payment_status = 'Approved' 
                    ORDER BY payment_date DESC LIMIT 1";
    $payment_stmt = $conn->prepare($payment_sql);
    $payment_stmt->bind_param("i", $user_id);
    $payment_stmt->execute();
    $approved_payment = $payment_stmt->get_result()->fetch_assoc();
    
    if ($approved_payment) {
        // Update the helper function to return the correct payment status
        // This is a temporary fix - we need to update the helper function
        $message = "✅ Found approved payment. The issue is in the helper function.";
        
        // Let's also check if the user has the correct selected_plan_id
        $user_sql = "SELECT selected_plan_id FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user = $user_stmt->get_result()->fetch_assoc();
        
        if (empty($user['selected_plan_id']) && $approved_payment['plan_id']) {
            // Update user's selected_plan_id
            $update_sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $approved_payment['plan_id'], $user_id);
            
            if ($update_stmt->execute()) {
                $message .= " Plan has been assigned to your account.";
            }
        }
    } else {
        $message = "❌ No approved payment found. Check if admin actually approved the payment.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Payment Status</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Fix Payment Status</h1>
        
        <?php if ($message): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">The Issue</h2>
            <p class="mb-4">
                Your payment was approved by admin, but the profile page helper function is not reading the correct payment status.
                This is because the helper function uses a complex query that might not be getting the right data.
            </p>
            
            <div class="space-y-4">
                <div class="p-4 bg-yellow-50 border border-yellow-200 rounded">
                    <h3 class="font-semibold text-yellow-800 mb-2">Quick Fix:</h3>
                    <ol class="list-decimal list-inside space-y-1 text-sm">
                        <li>Go to: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/member/profile.php</code></li>
                        <li>Check if it now shows "Active Membership"</li>
                        <li>If not, we need to fix the helper function</li>
                    </ol>
                </div>
                
                <form method="post">
                    <button type="submit" name="fix_payment_status" 
                            class="w-full bg-blue-500 text-white px-6 py-3 rounded hover:bg-blue-600">
                        🔧 Try to Fix Payment Status
                    </button>
                </form>
                
                <div class="space-y-2">
                    <a href="debug_payment_mismatch.php" 
                       class="block w-full bg-gray-500 text-white text-center px-4 py-2 rounded hover:bg-gray-600">
                        🔍 Debug Again
                    </a>
                    <a href="member/profile.php" 
                       class="block w-full bg-green-500 text-white text-center px-4 py-2 rounded hover:bg-green-600">
                        📄 Check Profile
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 