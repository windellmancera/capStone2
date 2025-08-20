<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member/member_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$debug_info = [];

// Get user's QR code data
$user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration
              FROM users u 
              LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
              WHERE u.id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Get payment info
$payment_sql = "SELECT * FROM payment_history 
                WHERE user_id = ? AND payment_status = 'Approved' 
                ORDER BY payment_date DESC LIMIT 1";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $user_id);
$payment_stmt->execute();
$payment = $payment_stmt->get_result()->fetch_assoc();

// Create QR data exactly as it should be
$qr_data = [
    'user_id' => $user_id,
    'payment_id' => $payment['id'] ?? 0,
    'plan_name' => $user['plan_name'] ?? 'No Plan',
    'plan_duration' => $user['plan_duration'] ?? 0,
    'timestamp' => time(),
    'hash' => hash('sha256', $user_id . ($payment['id'] ?? 0) . time() . 'ALMO_FITNESS_SECRET')
];

$debug_info['user'] = $user;
$debug_info['payment'] = $payment;
$debug_info['qr_data'] = $qr_data;
$debug_info['qr_json'] = json_encode($qr_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">QR Code Debug Information</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- User Information -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">User Information</h2>
                <div class="space-y-2">
                    <p><strong>User ID:</strong> <?php echo $user['id']; ?></p>
                    <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
                    <p><strong>Full Name:</strong> <?php echo $user['full_name'] ?? 'N/A'; ?></p>
                    <p><strong>Selected Plan ID:</strong> <?php echo $user['selected_plan_id'] ?? 'NULL'; ?></p>
                    <p><strong>Plan Name:</strong> <?php echo $user['plan_name'] ?? 'No Plan'; ?></p>
                    <p><strong>Plan Duration:</strong> <?php echo $user['plan_duration'] ?? 'N/A'; ?> days</p>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Payment Information</h2>
                <?php if ($payment): ?>
                    <div class="space-y-2">
                        <p><strong>Payment ID:</strong> <?php echo $payment['id']; ?></p>
                        <p><strong>Amount:</strong> ₱<?php echo $payment['amount']; ?></p>
                        <p><strong>Status:</strong> <?php echo $payment['payment_status']; ?></p>
                        <p><strong>Date:</strong> <?php echo $payment['payment_date']; ?></p>
                        <p><strong>Method:</strong> <?php echo $payment['payment_method']; ?></p>
                    </div>
                <?php else: ?>
                    <p class="text-red-600">No approved payment found!</p>
                <?php endif; ?>
            </div>

            <!-- QR Data -->
            <div class="bg-white p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">QR Code Data</h2>
                <div class="bg-gray-100 p-4 rounded">
                    <pre class="text-sm overflow-x-auto"><?php echo json_encode($qr_data, JSON_PRETTY_PRINT); ?></pre>
                </div>
            </div>

            <!-- Test QR Code -->
            <div class="bg-white p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Test QR Code</h2>
                <div class="text-center">
                    <div id="qrcode" class="inline-block"></div>
                    <p class="mt-4 text-sm text-gray-600">Scan this QR code with the admin scanner to test</p>
                </div>
            </div>

            <!-- Instructions -->
            <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Testing Instructions</h2>
                <ol class="list-decimal list-inside space-y-2">
                    <li>Go to: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/admin/attendance_scanner.php</code></li>
                    <li>Scan the QR code above with the admin scanner</li>
                    <li>Check if it shows the success message</li>
                    <li>If it doesn't work, check the error message</li>
                </ol>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script>
        // Generate QR code
        const qrData = <?php echo json_encode($qr_data); ?>;
        const qrJson = JSON.stringify(qrData);
        
        QRCode.toCanvas(document.getElementById('qrcode'), qrJson, {
            width: 300,
            margin: 2
        }, function (error) {
            if (error) console.error(error);
        });
    </script>
</body>
</html> 