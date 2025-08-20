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

// Get user's payment history
$payment_sql = "SELECT ph.*, mp.name as plan_name, mp.price as plan_price, mp.duration as plan_duration
                FROM payment_history ph
                LEFT JOIN membership_plans mp ON ph.plan_id = mp.id
                WHERE ph.user_id = ?
                ORDER BY ph.payment_date DESC";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $user_id);
$payment_stmt->execute();
$payments = $payment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get user's current plan
$user_sql = "SELECT u.*, mp.name as plan_name, mp.price as plan_price, mp.duration as plan_duration
              FROM users u
              LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
              WHERE u.id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Handle payment approval
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_payment'])) {
    $payment_id = $_POST['payment_id'];
    
    // Update payment status to Approved
    $update_sql = "UPDATE payment_history SET payment_status = 'Approved' WHERE id = ? AND user_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $payment_id, $user_id);
    
    if ($update_stmt->execute()) {
        $message = "✅ Payment approved successfully!";
        
        // Update user's selected_plan_id if not set
        if (empty($user['selected_plan_id'])) {
            $payment_info_sql = "SELECT plan_id FROM payment_history WHERE id = ?";
            $payment_info_stmt = $conn->prepare($payment_info_sql);
            $payment_info_stmt->bind_param("i", $payment_id);
            $payment_info_stmt->execute();
            $payment_info = $payment_info_stmt->get_result()->fetch_assoc();
            
            if ($payment_info && $payment_info['plan_id']) {
                $update_user_sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
                $update_user_stmt = $conn->prepare($update_user_sql);
                $update_user_stmt->bind_param("ii", $payment_info['plan_id'], $user_id);
                $update_user_stmt->execute();
                $message .= " Plan has been assigned to your account.";
            }
        }
        
        // Refresh the page to show updated data
        header("Location: check_payment_status.php?success=1");
        exit();
    } else {
        $message = "❌ Error approving payment: " . $conn->error;
    }
}

// Refresh data after approval
if (isset($_GET['success'])) {
    $message = "✅ Payment approved successfully! Your membership should now be active.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status Check</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Payment Status Check</h1>
        
        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- User Information -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">User Information</h2>
                <div class="space-y-2">
                    <p><strong>User ID:</strong> <?php echo $user['id']; ?></p>
                    <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
                    <p><strong>Selected Plan ID:</strong> <?php echo $user['selected_plan_id'] ?? 'NULL'; ?></p>
                    <p><strong>Plan Name:</strong> <?php echo $user['plan_name'] ?? 'No Plan'; ?></p>
                    <p><strong>Plan Price:</strong> ₱<?php echo number_format($user['plan_price'] ?? 0, 2); ?></p>
                    <p><strong>Plan Duration:</strong> <?php echo $user['plan_duration'] ?? 0; ?> days</p>
                </div>
            </div>

            <!-- Payment History -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Payment History</h2>
                <?php if (!empty($payments)): ?>
                    <div class="space-y-4">
                        <?php foreach ($payments as $payment): ?>
                            <div class="border rounded-lg p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <p><strong>Payment ID:</strong> <?php echo $payment['id']; ?></p>
                                        <p><strong>Amount:</strong> ₱<?php echo number_format($payment['amount'], 2); ?></p>
                                        <p><strong>Plan:</strong> <?php echo $payment['plan_name'] ?? 'N/A'; ?></p>
                                        <p><strong>Date:</strong> <?php echo $payment['payment_date']; ?></p>
                                    </div>
                                    <div class="text-right">
                                        <span class="px-2 py-1 rounded-full text-xs 
                                            <?php echo $payment['payment_status'] === 'Approved' ? 'bg-green-100 text-green-800' : 
                                                   ($payment['payment_status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                                                   'bg-red-100 text-red-800'); ?>">
                                            <?php echo $payment['payment_status']; ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($payment['payment_status'] === 'Pending'): ?>
                                    <form method="post" class="mt-2">
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                        <button type="submit" name="approve_payment" 
                                                class="bg-green-500 text-white px-4 py-2 rounded text-sm hover:bg-green-600">
                                            ✅ Approve Payment
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-red-600">No payments found!</p>
                <?php endif; ?>
            </div>

            <!-- Status Summary -->
            <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Status Summary</h2>
                <?php 
                $has_approved_payment = false;
                $has_selected_plan = !empty($user['selected_plan_id']);
                
                foreach ($payments as $payment) {
                    if ($payment['payment_status'] === 'Approved') {
                        $has_approved_payment = true;
                        break;
                    }
                }
                ?>
                
                <div class="space-y-2">
                    <p><strong>Has Selected Plan:</strong> 
                        <span class="<?php echo $has_selected_plan ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $has_selected_plan ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                    <p><strong>Has Approved Payment:</strong> 
                        <span class="<?php echo $has_approved_payment ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $has_approved_payment ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                    <p><strong>Membership Status:</strong> 
                        <span class="<?php echo ($has_selected_plan && $has_approved_payment) ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo ($has_selected_plan && $has_approved_payment) ? '✅ Active' : '❌ Inactive'; ?>
                        </span>
                    </p>
                </div>
                
                <?php if (!$has_approved_payment && !empty($payments)): ?>
                    <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded">
                        <p class="text-yellow-800">
                            <strong>Issue Found:</strong> You have payments but they are not approved. 
                            Click "Approve Payment" above to activate your membership.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Next Steps -->
            <div class="bg-green-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Next Steps</h2>
                <ol class="list-decimal list-inside space-y-2">
                    <li>If you see "Pending" payments, click "Approve Payment" to activate your membership</li>
                    <li>After approval, go to: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/member/profile.php</code></li>
                    <li>Your profile should now show "Active Membership" instead of "Payment Pending"</li>
                    <li>Test QR code scanning: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/admin/attendance_scanner.php</code></li>
                </ol>
            </div>

        </div>
    </div>
</body>
</html> 