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

// Get user's QR data
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

// Create QR data
$qr_data = [
    'user_id' => $user_id,
    'payment_id' => $payment['id'] ?? 0,
    'plan_name' => $user['plan_name'] ?? 'No Plan',
    'plan_duration' => $user['plan_duration'] ?? 0,
    'timestamp' => time(),
    'hash' => hash('sha256', $user_id . ($payment['id'] ?? 0) . time() . 'ALMO_FITNESS_SECRET')
];

$qr_json = json_encode($qr_data);

// Handle manual test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_qr'])) {
    $test_qr_data = $_POST['qr_data'];
    
    try {
        $qr_info = json_decode($test_qr_data, true);
        if ($qr_info && isset($qr_info['user_id'])) {
            $message = "✅ QR Code is valid! User ID: " . $qr_info['user_id'];
        } else {
            $message = "❌ Invalid QR code format";
        }
    } catch (Exception $e) {
        $message = "❌ Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scanner Test</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">QR Scanner Test</h1>
        
        <?php if ($message): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- QR Code Display -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Your QR Code</h2>
                <div class="text-center">
                    <div id="qrcode" class="inline-block mb-4"></div>
                    <p class="text-sm text-gray-600">Scan this QR code with the admin scanner</p>
                </div>
                
                <div class="mt-4 p-4 bg-gray-100 rounded">
                    <h3 class="font-semibold mb-2">QR Data:</h3>
                    <pre class="text-xs overflow-x-auto"><?php echo $qr_json; ?></pre>
                </div>
            </div>

            <!-- QR Scanner Test -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Test QR Scanner</h2>
                
                <div id="reader" class="w-full h-64 border-2 border-gray-300 rounded-lg mb-4"></div>
                
                <div class="mt-4">
                    <h3 class="font-semibold mb-2">Manual Test</h3>
                    <form method="post" class="space-y-2">
                        <textarea name="qr_data" placeholder="Paste QR code data here" 
                                  class="w-full p-2 border border-gray-300 rounded h-20"><?php echo $qr_json; ?></textarea>
                        <button type="submit" name="test_qr" 
                                class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            Test QR Data
                        </button>
                    </form>
                </div>
            </div>

            <!-- User Info -->
            <div class="bg-white p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">User Information</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p><strong>User ID:</strong> <?php echo $user_id; ?></p>
                        <p><strong>Username:</strong> <?php echo $user['username']; ?></p>
                        <p><strong>Plan:</strong> <?php echo $user['plan_name'] ?? 'No Plan'; ?></p>
                        <p><strong>Payment Status:</strong> 
                            <?php echo $payment ? 'Approved' : 'No Approved Payment'; ?>
                        </p>
                    </div>
                    <div>
                        <p><strong>Payment ID:</strong> <?php echo $payment['id'] ?? 'N/A'; ?></p>
                        <p><strong>Payment Date:</strong> <?php echo $payment['payment_date'] ?? 'N/A'; ?></p>
                        <p><strong>Amount:</strong> ₱<?php echo number_format($payment['amount'] ?? 0, 2); ?></p>
                    </div>
                </div>
            </div>

            <!-- Instructions -->
            <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Testing Instructions</h2>
                <ol class="list-decimal list-inside space-y-2">
                    <li><strong>Test QR Scanner:</strong> Use the scanner above to scan your QR code</li>
                    <li><strong>Manual Test:</strong> Copy the QR data and paste it in the manual test</li>
                    <li><strong>Admin Scanner:</strong> Go to <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/admin/attendance_scanner.php</code></li>
                    <li><strong>Check Results:</strong> See if the scanner detects and processes the QR code</li>
                </ol>
                
                <div class="mt-4 space-y-2">
                    <a href="admin/attendance_scanner.php" 
                       class="block w-full bg-green-500 text-white text-center px-4 py-2 rounded hover:bg-green-600">
                        📱 Go to Admin Scanner
                    </a>
                    <a href="member/profile.php" 
                       class="block w-full bg-blue-500 text-white text-center px-4 py-2 rounded hover:bg-blue-600">
                        📄 Back to Profile
                    </a>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script>
        // Generate QR code
        const qrData = <?php echo json_encode($qr_data); ?>;
        const qrJson = JSON.stringify(qrData);
        
        QRCode.toCanvas(document.getElementById('qrcode'), qrJson, {
            width: 200,
            margin: 2
        }, function (error) {
            if (error) console.error(error);
        });

        // QR Scanner
        function onScanSuccess(decodedText, decodedResult) {
            console.log('QR Code detected:', decodedText);
            
            // Test the decoded data
            try {
                const qrInfo = JSON.parse(decodedText);
                alert('QR Code detected! User ID: ' + qrInfo.user_id);
            } catch (e) {
                alert('Invalid QR code format: ' + decodedText);
            }
        }

        function onScanFailure(error) {
            // Handle scan failure, ignore for now
        }

        // Initialize scanner
        let html5QrcodeScanner = new Html5QrcodeScanner(
            "reader",
            { fps: 10, qrbox: {width: 250, height: 250} },
            false);
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);
    </script>
</body>
</html> 