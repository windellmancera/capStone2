<?php
session_start();
require_once 'db.php';
require_once 'member/payment_status_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member/member_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user data using the helper function (same as profile.php)
$user = getUserPaymentStatus($conn, $user_id);

// Get raw payment data
$payment_sql = "SELECT ph.*, mp.name as plan_name, mp.price as plan_price, mp.duration as plan_duration
                FROM payment_history ph
                LEFT JOIN membership_plans mp ON ph.plan_id = mp.id
                WHERE ph.user_id = ?
                ORDER BY ph.payment_date DESC";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $user_id);
$payment_stmt->execute();
$payments = $payment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get user's current plan data
$user_plan_sql = "SELECT u.*, mp.name as plan_name, mp.price as plan_price, mp.duration as plan_duration
                   FROM users u
                   LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                   WHERE u.id = ?";
$user_plan_stmt = $conn->prepare($user_plan_sql);
$user_plan_stmt->bind_param("i", $user_id);
$user_plan_stmt->execute();
$user_plan = $user_plan_stmt->get_result()->fetch_assoc();

// Check what the profile page logic would show
$has_plan = !empty($user['plan_name']);
$has_selected_plan = !empty($user['selected_plan_id']);
$payment_status = $user['payment_status'] ?? 'No Payment';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Mismatch Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Payment Mismatch Debug</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Helper Function Data -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Helper Function Data</h2>
                <div class="space-y-2">
                    <p><strong>User ID:</strong> <?php echo $user['id'] ?? 'N/A'; ?></p>
                    <p><strong>Username:</strong> <?php echo $user['username'] ?? 'N/A'; ?></p>
                    <p><strong>Selected Plan ID:</strong> <?php echo $user['selected_plan_id'] ?? 'NULL'; ?></p>
                    <p><strong>Plan Name:</strong> <?php echo $user['plan_name'] ?? 'No Plan'; ?></p>
                    <p><strong>Plan Price:</strong> ₱<?php echo number_format($user['plan_price'] ?? 0, 2); ?></p>
                    <p><strong>Plan Duration:</strong> <?php echo $user['plan_duration'] ?? 0; ?> days</p>
                    <p><strong>Payment Status:</strong> 
                        <span class="px-2 py-1 rounded-full text-xs 
                            <?php echo $payment_status === 'Approved' ? 'bg-green-100 text-green-800' : 
                                   ($payment_status === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                                   'bg-red-100 text-red-800'); ?>">
                            <?php echo $payment_status; ?>
                        </span>
                    </p>
                    <p><strong>Payment Date:</strong> <?php echo $user['payment_date'] ?? 'N/A'; ?></p>
                    <p><strong>Membership End Date:</strong> <?php echo $user['membership_end_date'] ?? 'N/A'; ?></p>
                </div>
            </div>

            <!-- Raw Payment Data -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Raw Payment Data</h2>
                <?php if (!empty($payments)): ?>
                    <div class="space-y-4">
                        <?php foreach ($payments as $payment): ?>
                            <div class="border rounded-lg p-4">
                                <p><strong>Payment ID:</strong> <?php echo $payment['id']; ?></p>
                                <p><strong>Amount:</strong> ₱<?php echo number_format($payment['amount'], 2); ?></p>
                                <p><strong>Plan:</strong> <?php echo $payment['plan_name'] ?? 'N/A'; ?></p>
                                <p><strong>Status:</strong> 
                                    <span class="px-2 py-1 rounded-full text-xs 
                                        <?php echo $payment['payment_status'] === 'Approved' ? 'bg-green-100 text-green-800' : 
                                               ($payment['payment_status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800' : 
                                               'bg-red-100 text-red-800'); ?>">
                                        <?php echo $payment['payment_status']; ?>
                                    </span>
                                </p>
                                <p><strong>Date:</strong> <?php echo $payment['payment_date']; ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-red-600">No payments found!</p>
                <?php endif; ?>
            </div>

            <!-- Profile Logic Analysis -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Profile Logic Analysis</h2>
                <div class="space-y-2">
                    <p><strong>Has Plan Name:</strong> 
                        <span class="<?php echo $has_plan ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $has_plan ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                    <p><strong>Has Selected Plan ID:</strong> 
                        <span class="<?php echo $has_selected_plan ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $has_selected_plan ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                    <p><strong>Payment Status:</strong> <?php echo $payment_status; ?></p>
                    
                    <div class="mt-4 p-4 bg-blue-50 rounded">
                        <h3 class="font-semibold mb-2">Profile Display Logic:</h3>
                        <p class="text-sm">
                            <?php if ($has_plan && $has_selected_plan): ?>
                                <span class="text-green-600">✅ Should show "Selected Plan (Pending)"</span><br>
                                <span class="text-sm text-gray-600">Because: Has plan name AND selected plan ID</span>
                            <?php elseif (empty($user['plan_name']) && empty($user['selected_plan_id'])): ?>
                                <span class="text-red-600">❌ Should show "No Active Membership"</span><br>
                                <span class="text-sm text-gray-600">Because: No plan name AND no selected plan ID</span>
                            <?php else: ?>
                                <span class="text-yellow-600">⚠️ Unexpected condition</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Fix Options -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Fix Options</h2>
                
                <?php if ($payment_status !== 'Approved' && !empty($payments)): ?>
                    <div class="mb-4 p-4 bg-yellow-50 border border-yellow-200 rounded">
                        <p class="text-yellow-800">
                            <strong>Issue:</strong> Payment status in helper function doesn't match database.
                        </p>
                    </div>
                <?php endif; ?>
                
                <div class="space-y-3">
                    <form method="post" action="fix_payment_status.php">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <button type="submit" name="fix_payment_status" 
                                class="w-full bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            🔧 Fix Payment Status Mismatch
                        </button>
                    </form>
                    
                    <form method="post" action="fix_plan_assignment.php">
                        <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                        <button type="submit" name="fix_plan_assignment" 
                                class="w-full bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                            🔧 Fix Plan Assignment
                        </button>
                    </form>
                    
                    <a href="member/profile.php" 
                       class="block w-full bg-gray-500 text-white text-center px-4 py-2 rounded hover:bg-gray-600">
                        📄 Check Profile Page
                    </a>
                </div>
            </div>

            <!-- Status Summary -->
            <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Status Summary</h2>
                <div class="space-y-2">
                    <p><strong>Profile Should Show:</strong> 
                        <?php if ($has_plan && $has_selected_plan): ?>
                            <span class="text-blue-600">"Selected Plan (Pending)"</span>
                        <?php elseif (empty($user['plan_name']) && empty($user['selected_plan_id'])): ?>
                            <span class="text-red-600">"No Active Membership"</span>
                        <?php else: ?>
                            <span class="text-yellow-600">Unexpected condition</span>
                        <?php endif; ?>
                    </p>
                    <p><strong>Actual Payment Status:</strong> <?php echo $payment_status; ?></p>
                    <p><strong>Has Approved Payment:</strong> 
                        <?php 
                        $has_approved = false;
                        foreach ($payments as $payment) {
                            if ($payment['payment_status'] === 'Approved') {
                                $has_approved = true;
                                break;
                            }
                        }
                        ?>
                        <span class="<?php echo $has_approved ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $has_approved ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                </div>
            </div>

        </div>
    </div>
</body>
</html> 