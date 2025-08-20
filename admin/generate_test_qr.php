<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

// Get a sample member for testing
$member_sql = "SELECT u.*, mp.name as plan_name 
               FROM users u 
               LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
               WHERE u.role = 'member' 
               LIMIT 1";
$member_result = $conn->query($member_sql);
$member = $member_result->fetch_assoc();

if (!$member) {
    echo "No members found for testing.";
    exit();
}

// Create test QR data
$qr_data = [
    'user_id' => $member['id'],
    'payment_id' => 1, // Test payment ID
    'plan_duration' => 30,
    'timestamp' => time(),
    'hash' => hash('sha256', $member['id'] . '1' . '30' . time() . 'ALMO_FITNESS_SECRET')
];

$qr_json = json_encode($qr_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test QR Code Generator - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen py-8">
        <div class="max-w-4xl mx-auto px-4">
            <div class="bg-white rounded-lg shadow-lg p-8">
                <h1 class="text-2xl font-bold text-gray-900 mb-6">Test QR Code Generator</h1>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Member Info -->
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Test Member</h2>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <p><strong>Name:</strong> <?php echo htmlspecialchars($member['full_name'] ?? $member['username']); ?></p>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($member['email']); ?></p>
                            <p><strong>Plan:</strong> <?php echo htmlspecialchars($member['plan_name'] ?? 'No Plan'); ?></p>
                            <p><strong>User ID:</strong> <?php echo $member['id']; ?></p>
                        </div>
                        
                        <div class="mt-4">
                            <h3 class="text-md font-semibold text-gray-900 mb-2">QR Code Data:</h3>
                            <textarea class="w-full h-32 p-2 border border-gray-300 rounded text-sm" readonly><?php echo $qr_json; ?></textarea>
                        </div>
                    </div>
                    
                    <!-- QR Code Display -->
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Generated QR Code</h2>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 flex justify-center">
                            <div id="qrcode"></div>
                        </div>
                        
                        <div class="mt-4 text-center">
                            <p class="text-sm text-gray-600 mb-2">Scan this QR code with the admin scanner to test attendance</p>
                            <a href="dashboard.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                <i class="fas fa-qrcode mr-2"></i>
                                Go to Admin Scanner
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="mt-8 p-4 bg-blue-50 rounded-lg">
                    <h3 class="text-lg font-semibold text-blue-900 mb-2">How to Test:</h3>
                    <ol class="list-decimal list-inside text-blue-800 space-y-1">
                        <li>Open the admin dashboard in another tab</li>
                        <li>Go to the QR Scanner section</li>
                        <li>Point the camera at this QR code</li>
                        <li>Check if attendance is recorded</li>
                        <li>View the attendance history to confirm</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Generate QR Code
        QRCode.toCanvas(document.getElementById('qrcode'), '<?php echo $qr_json; ?>', {
            width: 200,
            margin: 2,
            color: {
                dark: '#000000',
                light: '#FFFFFF'
            }
        }, function (error) {
            if (error) console.error(error);
        });
    </script>
</body>
</html> 