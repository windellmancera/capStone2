<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

$message = '';
$message_type = '';

// Handle QR code processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data'])) {
    $qr_data = $_POST['qr_data'];
    
    // Log the received data
    error_log("Processing QR data: " . $qr_data);
    
    try {
        // Parse QR data
        $qr_info = json_decode($qr_data, true);
        
        if ($qr_info && isset($qr_info['user_id'])) {
            $user_id = $qr_info['user_id'];
            
            // Get user information
            $user_sql = "SELECT u.*, mp.name as plan_name 
                        FROM users u 
                        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                        WHERE u.id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user = $user_stmt->get_result()->fetch_assoc();
            
            if ($user) {
                // Check if user has approved payment
                $payment_sql = "SELECT * FROM payment_history 
                               WHERE user_id = ? AND payment_status = 'Approved' 
                               ORDER BY payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->bind_param("i", $user_id);
                $payment_stmt->execute();
                $payment = $payment_stmt->get_result()->fetch_assoc();
                
                if ($payment) {
                    // Check today's attendance
                    $today = date('Y-m-d');
                    $attendance_sql = "SELECT * FROM attendance 
                                      WHERE user_id = ? AND DATE(check_in_time) = ? 
                                      ORDER BY check_in_time DESC LIMIT 1";
                    $attendance_stmt = $conn->prepare($attendance_sql);
                    $attendance_stmt->bind_param("is", $user_id, $today);
                    $attendance_stmt->execute();
                    $attendance = $attendance_stmt->get_result()->fetch_assoc();
                    
                    if (!$attendance) {
                        // Check in
                        $checkin_sql = "INSERT INTO attendance (user_id, check_in_time, plan_id) VALUES (?, NOW(), ?)";
                        $checkin_stmt = $conn->prepare($checkin_sql);
                        $checkin_stmt->bind_param("ii", $user_id, $user['selected_plan_id']);
                        
                        if ($checkin_stmt->execute()) {
                            $message = "✅ CHECK-IN SUCCESSFUL!\n\nMember: " . ($user['full_name'] ?? $user['username']) . 
                                     "\nPlan: " . $user['plan_name'] . 
                                     "\nTime: " . date('H:i:s');
                            $message_type = 'success';
                            
                            // Redirect to attendance history after successful check-in
                            header("Location: attendance_history.php?success=checkin&member=" . urlencode($user['full_name'] ?? $user['username']));
                            exit();
                        } else {
                            $message = "❌ Error recording check-in: " . $checkin_stmt->error;
                            $message_type = 'error';
                        }
                    } else {
                        // Check out
                        $checkout_sql = "UPDATE attendance SET check_out_time = NOW() WHERE id = ?";
                        $checkout_stmt = $conn->prepare($checkout_sql);
                        $checkout_stmt->bind_param("i", $attendance['id']);
                        
                        if ($checkout_stmt->execute()) {
                            $message = "✅ CHECK-OUT SUCCESSFUL!\n\nMember: " . ($user['full_name'] ?? $user['username']) . 
                                     "\nPlan: " . $user['plan_name'] . 
                                     "\nDuration: " . calculateDuration($attendance['check_in_time']) . " minutes";
                            $message_type = 'success';
                            
                            // Redirect to attendance history after successful check-out
                            header("Location: attendance_history.php?success=checkout&member=" . urlencode($user['full_name'] ?? $user['username']));
                            exit();
                        } else {
                            $message = "❌ Error recording check-out: " . $checkout_stmt->error;
                            $message_type = 'error';
                        }
                    }
                } else {
                    $message = "❌ No approved payment found for this member";
                    $message_type = 'error';
                }
            } else {
                $message = "❌ User not found";
                $message_type = 'error';
            }
        } else {
            $message = "❌ Invalid QR code data";
            $message_type = 'error';
        }
    } catch (Exception $e) {
        $message = "❌ Error processing QR code: " . $e->getMessage();
        $message_type = 'error';
    }
}

function calculateDuration($check_in_time) {
    $check_in = new DateTime($check_in_time);
    $check_out = new DateTime();
    $duration = $check_in->diff($check_out);
    return $duration->h * 60 + $duration->i;
}

// Get recent attendance records
$recent_attendance_sql = "SELECT a.*, u.full_name, u.username, mp.name as plan_name
                          FROM attendance a
                          JOIN users u ON a.user_id = u.id
                          LEFT JOIN membership_plans mp ON a.plan_id = mp.id
                          ORDER BY a.check_in_time DESC
                          LIMIT 10";
$recent_attendance = $conn->query($recent_attendance_sql)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple QR Scanner - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-red-800 text-white p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Simple QR Scanner</h1>
                <div class="flex space-x-4">
                    <a href="scan_attendance.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-qrcode mr-2"></i>Advanced Scanner
                    </a>
                    <a href="dashboard.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="max-w-6xl mx-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- QR Scanner -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">QR Code Scanner</h2>
                    
                    <!-- Message Display -->
                    <?php if ($message): ?>
                        <div class="mb-4 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                            <pre class="whitespace-pre-wrap"><?php echo $message; ?></pre>
                        </div>
                    <?php endif; ?>
                    
                    <!-- QR Scanner Container -->
                    <div id="reader" class="w-full h-64 border-2 border-gray-300 rounded-lg mb-4"></div>
                    
                    <!-- Manual Entry -->
                    <div class="mt-4">
                        <h3 class="font-semibold mb-2">Manual Entry (if QR fails)</h3>
                        <form method="post" class="space-y-2">
                            <textarea name="qr_data" placeholder="Paste QR code data here" 
                                      class="w-full p-2 border border-gray-300 rounded h-20">{"user_id":2,"payment_id":1,"plan_name":"Basic Plan","timestamp":<?php echo time(); ?>,"hash":"test"}</textarea>
                            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                                Process Manual Entry
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Recent Attendance -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">Recent Attendance</h2>
                    
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php foreach ($recent_attendance as $record): ?>
                            <div class="border border-gray-200 rounded p-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-semibold"><?php echo htmlspecialchars($record['full_name'] ?? $record['username']); ?></p>
                                        <p class="text-sm text-gray-600">
                                            Check-in: <?php echo date('M j, Y g:i A', strtotime($record['check_in_time'])); ?>
                                        </p>
                                        <?php if ($record['check_out_time']): ?>
                                            <p class="text-sm text-gray-600">
                                                Check-out: <?php echo date('M j, Y g:i A', strtotime($record['check_out_time'])); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <span class="px-2 py-1 rounded text-xs <?php echo $record['check_out_time'] ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                            <?php echo $record['check_out_time'] ? 'Completed' : 'Active'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let html5QrcodeScanner = null;
        let isScanning = false;

        // Simple QR Scanner
        function onScanSuccess(decodedText, decodedResult) {
            console.log('QR Code scanned:', decodedText);
            
            // Stop scanner
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
                isScanning = false;
            }
            
            // Show processing message
            const reader = document.getElementById('reader');
            reader.innerHTML = '<div class="flex items-center justify-center h-full"><div class="text-center"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p>Processing QR code...</p></div></div>';
            
            // Create and submit form
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="qr_data" value="${decodedText}">`;
            document.body.appendChild(form);
            form.submit();
        }

        function onScanFailure(error) {
            console.log('Scan failed:', error);
        }

        function initializeScanner() {
            const reader = document.getElementById('reader');
            reader.innerHTML = '';
            
            try {
                html5QrcodeScanner = new Html5QrcodeScanner(
                    "reader",
                    { 
                        fps: 10, 
                        qrbox: {width: 250, height: 250},
                        aspectRatio: 1.0
                    },
                    false
                );
                
                html5QrcodeScanner.render(onScanSuccess, onScanFailure);
                isScanning = true;
                console.log('Scanner initialized');
            } catch (error) {
                console.error('Scanner error:', error);
                reader.innerHTML = `
                    <div class="flex items-center justify-center h-full">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-2xl text-red-500 mb-2"></i>
                            <p class="text-red-600">Camera not available</p>
                            <p class="text-sm text-gray-500">Please allow camera access</p>
                        </div>
                    </div>
                `;
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initializeScanner();
        });
    </script>
</body>
</html> 