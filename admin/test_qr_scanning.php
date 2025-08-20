<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

$test_message = '';
$test_qr_data = '';

// Get a sample user for testing
$user_sql = "SELECT u.id, u.username, u.full_name, mp.name as plan_name 
             FROM users u 
             LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
             WHERE u.role = 'member' 
             LIMIT 1";
$user_result = $conn->query($user_sql);
$test_user = $user_result->fetch_assoc();

if ($test_user) {
    // Create test QR data
    $test_qr_data = [
        'user_id' => $test_user['id'],
        'payment_id' => 1, // Test payment ID
        'plan_name' => $test_user['plan_name'] ?? 'Test Plan',
        'timestamp' => time(),
        'hash' => hash('sha256', $test_user['id'] . '1' . time() . 'ALMO_FITNESS_SECRET')
    ];
    $test_qr_json = json_encode($test_qr_data);
}

// Handle test scan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_scan'])) {
    $qr_data = $_POST['qr_data'];
    
    try {
        $qr_info = json_decode($qr_data, true);
        if ($qr_info && isset($qr_info['user_id'])) {
            $test_message = "✅ Test scan successful! User ID: " . $qr_info['user_id'];
        } else {
            $test_message = "❌ Invalid QR data format";
        }
    } catch (Exception $e) {
        $test_message = "❌ Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test QR Scanning - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-red-800 text-white p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Test QR Scanning</h1>
                <div class="flex space-x-4">
                    <a href="scan_attendance.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-qrcode mr-2"></i>Real Scanner
                    </a>
                    <a href="dashboard.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="max-w-6xl mx-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- Test QR Code -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Test QR Code</h2>
                    
                    <?php if ($test_user): ?>
                        <div class="mb-4">
                            <p class="text-sm text-gray-600 mb-2">Test User: <?php echo htmlspecialchars($test_user['full_name'] ?? $test_user['username']); ?></p>
                            <p class="text-sm text-gray-600 mb-4">User ID: <?php echo $test_user['id']; ?></p>
                        </div>
                        
                        <div class="flex justify-center mb-4">
                            <div id="test-qrcode"></div>
                        </div>
                        
                        <div class="bg-gray-100 p-3 rounded text-xs font-mono">
                            <p class="font-semibold mb-1">QR Data:</p>
                            <p class="break-all"><?php echo htmlspecialchars($test_qr_json); ?></p>
                        </div>
                    <?php else: ?>
                        <p class="text-red-600">No test user found. Please add a member first.</p>
                    <?php endif; ?>
                </div>

                <!-- QR Scanner -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Test Scanner</h2>
                    
                    <?php if ($test_message): ?>
                        <div class="mb-4 p-4 rounded-lg <?php echo strpos($test_message, '✅') !== false ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php echo htmlspecialchars($test_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- QR Scanner Container -->
                    <div id="reader" class="w-full h-64 border-2 border-gray-300 rounded-lg mb-4"></div>
                    
                    <!-- Manual Test -->
                    <div class="mt-4">
                        <h3 class="font-semibold mb-2">Manual Test</h3>
                        <form method="post" class="space-y-2">
                            <textarea name="qr_data" placeholder="Paste QR code data here" 
                                      class="w-full p-2 border border-gray-300 rounded h-20"><?php echo htmlspecialchars($test_qr_json); ?></textarea>
                            <button type="submit" name="test_scan" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                                Test Scan
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Instructions -->
            <div class="bg-blue-50 rounded-lg shadow p-6 mt-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Testing Instructions:</h2>
                <ol class="list-decimal list-inside space-y-2 text-blue-700">
                    <li>Use the test QR code on the left to scan with the scanner on the right</li>
                    <li>Or use the manual test form to paste QR data</li>
                    <li>Check if the scanner detects the QR code properly</li>
                    <li>Verify that the QR data is being parsed correctly</li>
                    <li>If this works, the real scanner should also work</li>
                </ol>
            </div>
        </div>
    </div>

    <script>
        // Generate test QR code
        <?php if ($test_qr_data): ?>
        const testQrData = <?php echo json_encode($test_qr_data); ?>;
        const testQrJson = JSON.stringify(testQrData);
        
        QRCode.toCanvas(document.getElementById('test-qrcode'), testQrJson, {
            width: 200,
            margin: 2
        }, function (error) {
            if (error) console.error(error);
        });
        <?php endif; ?>

        // Initialize QR Scanner
        function onScanSuccess(decodedText, decodedResult) {
            console.log('QR Code scanned:', decodedText);
            
            // Stop scanner
            html5QrcodeScanner.clear();
            
            // Show what was scanned
            const reader = document.getElementById('reader');
            reader.innerHTML = `
                <div class="flex items-center justify-center h-full">
                    <div class="text-center">
                        <i class="fas fa-check-circle text-2xl text-green-500 mb-2"></i>
                        <p><strong>QR Code Detected!</strong></p>
                        <p class="text-sm">Data: ${decodedText.substring(0, 50)}...</p>
                    </div>
                </div>
            `;
            
            // Auto-submit the form with the scanned data
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="qr_data" value="${decodedText}">
                <input type="hidden" name="test_scan" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function onScanFailure(error) {
            console.log('Scan failed:', error);
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