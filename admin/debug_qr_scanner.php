<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

$debug_info = [];
$test_results = [];

// Test 1: Check if attendance table exists and has correct structure
$table_exists = $conn->query("SHOW TABLES LIKE 'attendance'")->num_rows > 0;
$debug_info['attendance_table'] = $table_exists ? '✅ Exists' : '❌ Missing';

if ($table_exists) {
    $columns = $conn->query("SHOW COLUMNS FROM attendance")->fetch_all(MYSQLI_ASSOC);
    $column_names = array_column($columns, 'Field');
    $debug_info['plan_id_column'] = in_array('plan_id', $column_names) ? '✅ Exists' : '❌ Missing';
    $debug_info['table_structure'] = $column_names;
}

// Test 2: Check if there are any members with QR codes
$members_sql = "SELECT u.id, u.username, u.full_name, u.qr_code, mp.name as plan_name
                FROM users u 
                LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                WHERE u.role = 'member' 
                LIMIT 5";
$members_result = $conn->query($members_sql);
$debug_info['members_with_qr'] = $members_result->num_rows;
$debug_info['members_data'] = $members_result->fetch_all(MYSQLI_ASSOC);

// Test 3: Check if there are any approved payments
$payments_sql = "SELECT COUNT(*) as count FROM payment_history WHERE payment_status = 'Approved'";
$payments_result = $conn->query($payments_sql);
$debug_info['approved_payments'] = $payments_result->fetch_assoc()['count'];

// Test 4: Check browser capabilities
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$debug_info['user_agent'] = $user_agent;
$debug_info['is_https'] = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
$debug_info['server_name'] = $_SERVER['SERVER_NAME'];

// Handle test QR generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_test_qr'])) {
    $user_id = $_POST['user_id'];
    
    // Create test QR data
    $test_qr_data = [
        'user_id' => $user_id,
        'payment_id' => 1,
        'plan_name' => 'Test Plan',
        'timestamp' => time(),
        'hash' => hash('sha256', $user_id . '1' . time() . 'ALMO_FITNESS_SECRET')
    ];
    
    $test_results['qr_data'] = $test_qr_data;
    $test_results['qr_json'] = json_encode($test_qr_data);
}

// Handle test scan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_scan'])) {
    $qr_data = $_POST['qr_data'];
    $test_results['scanned_data'] = $qr_data;
    
    try {
        $qr_info = json_decode($qr_data, true);
        if ($qr_info && isset($qr_info['user_id'])) {
            $test_results['scan_success'] = true;
            $test_results['parsed_data'] = $qr_info;
        } else {
            $test_results['scan_success'] = false;
            $test_results['error'] = 'Invalid QR data format';
        }
    } catch (Exception $e) {
        $test_results['scan_success'] = false;
        $test_results['error'] = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug QR Scanner - Admin</title>
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
                <h1 class="text-2xl font-bold">Debug QR Scanner</h1>
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
            
            <!-- System Information -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">System Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h3 class="font-semibold mb-2">Database Status:</h3>
                        <ul class="space-y-1 text-sm">
                            <li>Attendance Table: <?php echo $debug_info['attendance_table']; ?></li>
                            <li>Plan ID Column: <?php echo $debug_info['plan_id_column']; ?></li>
                            <li>Members with QR: <?php echo $debug_info['members_with_qr']; ?></li>
                            <li>Approved Payments: <?php echo $debug_info['approved_payments']; ?></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold mb-2">Browser/Server Info:</h3>
                        <ul class="space-y-1 text-sm">
                            <li>HTTPS: <?php echo $debug_info['is_https'] ? '✅ Yes' : '❌ No'; ?></li>
                            <li>Server: <?php echo $debug_info['server_name']; ?></li>
                            <li>User Agent: <?php echo substr($debug_info['user_agent'], 0, 50) . '...'; ?></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Camera Test -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Camera Test</h2>
                <div id="camera-status" class="mb-4 p-4 bg-gray-100 rounded">
                    <p>Checking camera access...</p>
                </div>
                <div id="camera-test" class="w-full h-64 border-2 border-gray-300 rounded-lg mb-4"></div>
                <button onclick="testCamera()" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    <i class="fas fa-camera mr-2"></i>Test Camera Access
                </button>
            </div>

            <!-- QR Code Test -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                
                <!-- Generate Test QR -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-4">Generate Test QR</h2>
                    
                    <?php if ($debug_info['members_data']): ?>
                        <form method="post" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select Member:</label>
                            <select name="user_id" class="w-full p-2 border border-gray-300 rounded mb-4">
                                <?php foreach ($debug_info['members_data'] as $member): ?>
                                    <option value="<?php echo $member['id']; ?>">
                                        <?php echo htmlspecialchars($member['full_name'] ?? $member['username']); ?> (ID: <?php echo $member['id']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="generate_test_qr" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                                <i class="fas fa-qrcode mr-2"></i>Generate Test QR
                            </button>
                        </form>
                        
                        <?php if (isset($test_results['qr_data'])): ?>
                            <div class="flex justify-center mb-4">
                                <div id="test-qrcode"></div>
                            </div>
                            <div class="bg-gray-100 p-3 rounded text-xs">
                                <p class="font-semibold mb-1">QR Data:</p>
                                <p class="break-all"><?php echo htmlspecialchars($test_results['qr_json']); ?></p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-red-600">No members found. Please add members first.</p>
                    <?php endif; ?>
                </div>

                <!-- Test Scanner -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-xl font-semibold mb-4">Test Scanner</h2>
                    
                    <?php if (isset($test_results['scan_success'])): ?>
                        <div class="mb-4 p-4 rounded-lg <?php echo $test_results['scan_success'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <?php if ($test_results['scan_success']): ?>
                                <p>✅ Scan successful!</p>
                                <p class="text-sm">User ID: <?php echo $test_results['parsed_data']['user_id']; ?></p>
                            <?php else: ?>
                                <p>❌ Scan failed: <?php echo $test_results['error']; ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div id="scanner-test" class="w-full h-64 border-2 border-gray-300 rounded-lg mb-4"></div>
                    
                    <form method="post" class="mt-4">
                        <textarea name="qr_data" placeholder="Paste QR data here for manual test" 
                                  class="w-full p-2 border border-gray-300 rounded h-20 mb-2"><?php echo isset($test_results['qr_json']) ? htmlspecialchars($test_results['qr_json']) : ''; ?></textarea>
                        <button type="submit" name="test_scan" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            <i class="fas fa-play mr-2"></i>Test Scan
                        </button>
                    </form>
                </div>
            </div>

            <!-- Troubleshooting Guide -->
            <div class="bg-yellow-50 rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4 text-yellow-800">Troubleshooting Guide</h2>
                <div class="space-y-4">
                    <div>
                        <h3 class="font-semibold text-yellow-700">If camera doesn't work:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm text-yellow-700">
                            <li>Make sure you're using HTTPS (required for camera access)</li>
                            <li>Allow camera permissions in your browser</li>
                            <li>Try refreshing the page</li>
                            <li>Check if your camera is being used by another application</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-yellow-700">If QR codes aren't being detected:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm text-yellow-700">
                            <li>Make sure the QR code is clear and well-lit</li>
                            <li>Try holding the QR code closer to the camera</li>
                            <li>Check if the QR code format is correct (should be JSON)</li>
                            <li>Try the manual test with pasted QR data</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-yellow-700">If database operations fail:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm text-yellow-700">
                            <li>Run the database fix: <a href="fix_attendance_table.php" class="underline">Fix Attendance Table</a></li>
                            <li>Check if members have approved payments</li>
                            <li>Verify the attendance table structure</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Test camera access
        function testCamera() {
            const statusDiv = document.getElementById('camera-status');
            const testDiv = document.getElementById('camera-test');
            
            statusDiv.innerHTML = '<p>Requesting camera access...</p>';
            
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    statusDiv.innerHTML = '<p class="text-green-600">✅ Camera access granted!</p>';
                    
                    // Show video stream
                    const video = document.createElement('video');
                    video.srcObject = stream;
                    video.autoplay = true;
                    video.style.width = '100%';
                    video.style.height = '100%';
                    video.style.objectFit = 'cover';
                    
                    testDiv.innerHTML = '';
                    testDiv.appendChild(video);
                    
                    // Stop stream after 5 seconds
                    setTimeout(() => {
                        stream.getTracks().forEach(track => track.stop());
                        testDiv.innerHTML = '<div class="flex items-center justify-center h-full"><p class="text-gray-500">Camera test completed</p></div>';
                    }, 5000);
                })
                .catch(function(error) {
                    statusDiv.innerHTML = `<p class="text-red-600">❌ Camera access denied: ${error.message}</p>`;
                    testDiv.innerHTML = '<div class="flex items-center justify-center h-full"><p class="text-red-500">Camera not available</p></div>';
                });
        }

        // Initialize scanner test
        function initScannerTest() {
            const scannerDiv = document.getElementById('scanner-test');
            
            try {
                let html5QrcodeScanner = new Html5QrcodeScanner(
                    "scanner-test",
                    { fps: 10, qrbox: {width: 250, height: 250} },
                    false
                );
                
                html5QrcodeScanner.render(function(decodedText, decodedResult) {
                    console.log('QR Code detected:', decodedText);
                    
                    // Show result
                    scannerDiv.innerHTML = `
                        <div class="flex items-center justify-center h-full">
                            <div class="text-center">
                                <i class="fas fa-check-circle text-2xl text-green-500 mb-2"></i>
                                <p><strong>QR Code Detected!</strong></p>
                                <p class="text-sm">Data: ${decodedText.substring(0, 50)}...</p>
                            </div>
                        </div>
                    `;
                }, function(error) {
                    console.log('Scan failed:', error);
                });
                
                console.log('Scanner initialized successfully');
            } catch (error) {
                console.error('Scanner initialization failed:', error);
                scannerDiv.innerHTML = `
                    <div class="flex items-center justify-center h-full">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-2xl text-red-500 mb-2"></i>
                            <p class="text-red-600">Scanner failed to initialize</p>
                            <p class="text-sm text-gray-500">${error.message}</p>
                        </div>
                    </div>
                `;
            }
        }

        // Generate test QR code if data is available
        <?php if (isset($test_results['qr_data'])): ?>
        const testQrData = <?php echo json_encode($test_results['qr_data']); ?>;
        const testQrJson = JSON.stringify(testQrData);
        
        QRCode.toCanvas(document.getElementById('test-qrcode'), testQrJson, {
            width: 200,
            margin: 2
        }, function (error) {
            if (error) console.error(error);
        });
        <?php endif; ?>

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-test camera
            setTimeout(testCamera, 1000);
            
            // Initialize scanner test
            setTimeout(initScannerTest, 2000);
        });
    </script>
</body>
</html> 