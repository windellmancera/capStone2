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

// Get user data using the helper function
$user = getUserPaymentStatus($conn, $user_id);

// Get raw user data from database
$raw_user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration
                  FROM users u 
                  LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                  WHERE u.id = ?";
$raw_user_stmt = $conn->prepare($raw_user_sql);
$raw_user_stmt->bind_param("i", $user_id);
$raw_user_stmt->execute();
$raw_user = $raw_user_stmt->get_result()->fetch_assoc();

// Get payment data
$payment_sql = "SELECT * FROM payment_history 
                WHERE user_id = ? 
                ORDER BY payment_date DESC 
                LIMIT 5";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $user_id);
$payment_stmt->execute();
$payments = $payment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Test membership status
$has_active = hasActiveMembership($user);
$membership_status = getMembershipStatus($user);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Status Test</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Membership Status Test</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- User Data from Helper -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">User Data (from Helper)</h2>
                <div class="space-y-2">
                    <p><strong>User ID:</strong> <?php echo $user['id'] ?? 'N/A'; ?></p>
                    <p><strong>Username:</strong> <?php echo $user['username'] ?? 'N/A'; ?></p>
                    <p><strong>Selected Plan ID:</strong> <?php echo $user['selected_plan_id'] ?? 'NULL'; ?></p>
                    <p><strong>Plan Name:</strong> <?php echo $user['plan_name'] ?? 'No Plan'; ?></p>
                    <p><strong>Plan Duration:</strong> <?php echo $user['plan_duration'] ?? 'N/A'; ?> days</p>
                    <p><strong>Payment Status:</strong> <?php echo $user['payment_status'] ?? 'N/A'; ?></p>
                    <p><strong>Payment Date:</strong> <?php echo $user['payment_date'] ?? 'N/A'; ?></p>
                    <p><strong>Membership End Date:</strong> <?php echo $user['membership_end_date'] ?? 'N/A'; ?></p>
                    <p><strong>Total Paid:</strong> ₱<?php echo $user['total_paid'] ?? '0'; ?></p>
                    <p><strong>Completed Payments:</strong> <?php echo $user['completed_payments'] ?? '0'; ?></p>
                </div>
            </div>

            <!-- Raw User Data -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Raw User Data</h2>
                <div class="space-y-2">
                    <p><strong>User ID:</strong> <?php echo $raw_user['id'] ?? 'N/A'; ?></p>
                    <p><strong>Username:</strong> <?php echo $raw_user['username'] ?? 'N/A'; ?></p>
                    <p><strong>Selected Plan ID:</strong> <?php echo $raw_user['selected_plan_id'] ?? 'NULL'; ?></p>
                    <p><strong>Plan Name:</strong> <?php echo $raw_user['plan_name'] ?? 'No Plan'; ?></p>
                    <p><strong>Plan Duration:</strong> <?php echo $raw_user['plan_duration'] ?? 'N/A'; ?> days</p>
                    <p><strong>Role:</strong> <?php echo $raw_user['role'] ?? 'N/A'; ?></p>
                </div>
            </div>

            <!-- Membership Status -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Membership Status</h2>
                <div class="space-y-2">
                    <p><strong>Has Active Membership:</strong> 
                        <span class="<?php echo $has_active ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $has_active ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                    <p><strong>Membership Status:</strong> 
                        <span class="px-2 py-1 rounded-full text-xs 
                            <?php echo $membership_status === 'active' ? 'bg-green-100 text-green-800' : 
                                   ($membership_status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                   'bg-red-100 text-red-800'); ?>">
                            <?php echo ucfirst($membership_status); ?>
                        </span>
                    </p>
                    <p><strong>Current Date:</strong> <?php echo date('Y-m-d'); ?></p>
                </div>
            </div>

            <!-- Payment History -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Payment History</h2>
                <?php if (!empty($payments)): ?>
                    <div class="space-y-2">
                        <?php foreach ($payments as $payment): ?>
                            <div class="border-b pb-2">
                                <p><strong>Payment ID:</strong> <?php echo $payment['id']; ?></p>
                                <p><strong>Amount:</strong> ₱<?php echo $payment['amount']; ?></p>
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

            <!-- Status Summary -->
            <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Status Summary</h2>
                <?php if ($has_active): ?>
                    <div class="text-green-600 font-semibold">
                        ✅ SUCCESS! Your membership is active.
                    </div>
                    <p class="mt-2">Your profile should now show:</p>
                    <ul class="list-disc list-inside mt-2 space-y-1">
                        <li>Plan: <?php echo $user['plan_name'] ?? 'No Plan'; ?></li>
                        <li>Status: <?php echo ucfirst($membership_status); ?></li>
                        <li>End Date: <?php echo $user['membership_end_date'] ?? 'N/A'; ?></li>
                    </ul>
                <?php else: ?>
                    <div class="text-red-600 font-semibold">
                        ❌ Your membership is not active.
                    </div>
                    <p class="mt-2">Possible issues:</p>
                    <ul class="list-disc list-inside mt-2 space-y-1">
                        <li>No selected plan ID: <?php echo $user['selected_plan_id'] ?? 'NULL'; ?></li>
                        <li>Payment status: <?php echo $user['payment_status'] ?? 'N/A'; ?></li>
                        <li>Membership end date: <?php echo $user['membership_end_date'] ?? 'N/A'; ?></li>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Next Steps -->
            <div class="bg-green-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Next Steps</h2>
                <ol class="list-decimal list-inside space-y-2">
                    <li>Go to: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/member/profile.php</code></li>
                    <li>Check if membership information is now showing correctly</li>
                    <li>If still not working, check the payment status and plan assignment</li>
                </ol>
            </div>

        </div>
    </div>
</body>
</html> 