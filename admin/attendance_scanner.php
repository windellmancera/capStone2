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

// Handle QR code scanning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data'])) {
    $qr_data = $_POST['qr_data'];
    
    try {
        // Decode QR data
        $qr_info = json_decode($qr_data, true);
        
        if ($qr_info && isset($qr_info['user_id'])) {
            $user_id = $qr_info['user_id'];
            $payment_id = $qr_info['payment_id'];
            $plan_name = $qr_info['plan_name'];
            
            // Verify user exists and has active membership
            $user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration
                        FROM users u 
                        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                        WHERE u.id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user = $user_stmt->get_result()->fetch_assoc();
            
            if ($user) {
                // Check if user has active membership
                $payment_sql = "SELECT * FROM payment_history 
                               WHERE user_id = ? AND payment_status = 'Approved' 
                               ORDER BY payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->bind_param("i", $user_id);
                $payment_stmt->execute();
                $payment = $payment_stmt->get_result()->fetch_assoc();
                
                if ($payment) {
                    // Check if already checked in today
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
                                     "\nTime: " . date('H:i:s') . 
                                     "\n\n✅ Admin can scan this QR code with any QR scanner app" .
                                     "\n✅ Attendance tracking will record your entry/exit" .
                                     "\n✅ Membership verification confirms your active plan" .
                                     "\n✅ Real-time logging updates the database instantly";
                            $message_type = 'success';
                        } else {
                            $message = "❌ Error recording check-in";
                            $message_type = 'error';
                        }
                    } else {
                        // Check out
                        $checkout_sql = "UPDATE attendance SET check_out_time = NOW() WHERE id = ?";
                        $checkout_stmt = $conn->prepare($checkout_sql);
                        $checkout_stmt->bind_param("i", $attendance['id']);
                        
                        if ($checkout_stmt->execute()) {
                            $duration = calculateDuration($attendance['check_in_time']);
                            $message = "✅ CHECK-OUT SUCCESSFUL!\n\nMember: " . ($user['full_name'] ?? $user['username']) . 
                                     "\nPlan: " . $user['plan_name'] . 
                                     "\nDuration: " . $duration . " minutes" .
                                     "\nTime: " . date('H:i:s') . 
                                     "\n\n✅ Admin can scan this QR code with any QR scanner app" .
                                     "\n✅ Attendance tracking will record your entry/exit" .
                                     "\n✅ Membership verification confirms your active plan" .
                                     "\n✅ Real-time logging updates the database instantly";
                            $message_type = 'success';
                        } else {
                            $message = "❌ Error recording check-out";
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
    return $duration->h * 60 + $duration->i; // Convert to minutes
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
    <title>Attendance Scanner - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-red-800 text-white p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Attendance Scanner</h1>
                <a href="dashboard.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
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
                            <pre class="whitespace-pre-wrap font-mono text-sm"><?php echo $message; ?></pre>
                        </div>
                    <?php endif; ?>
                    
                    <!-- QR Scanner Container -->
                    <div id="reader" class="w-full h-64 border-2 border-gray-300 rounded-lg mb-4"></div>
                    
                    <!-- Manual Entry -->
                    <div class="mt-4">
                        <h3 class="font-semibold mb-2">Manual Entry (if QR fails)</h3>
                        <form method="post" class="space-y-2">
                            <input type="text" name="qr_data" placeholder="Paste QR code data here" 
                                   class="w-full p-2 border border-gray-300 rounded">
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
                                        <p class="font-semibold">
                                            <?php echo htmlspecialchars($record['full_name'] ?? $record['username']); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <?php echo htmlspecialchars($record['plan_name'] ?? 'No Plan'); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            Check-in: <?php echo date('M d, Y H:i', strtotime($record['check_in_time'])); ?>
                                        </p>
                                        <?php if ($record['check_out_time']): ?>
                                            <p class="text-xs text-gray-500">
                                                Check-out: <?php echo date('M d, Y H:i', strtotime($record['check_out_time'])); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <?php if ($record['check_out_time']): ?>
                                            <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs">Completed</span>
                                        <?php else: ?>
                                            <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded text-xs">Checked In</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($recent_attendance)): ?>
                            <p class="text-gray-500 text-center py-4">No recent attendance records</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize QR Scanner
        function onScanSuccess(decodedText, decodedResult) {
            // Stop scanner
            html5QrcodeScanner.clear();
            
            // Send QR data to server
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="qr_data" value="${decodedText}">`;
            document.body.appendChild(form);
            form.submit();
        }

        function onScanFailure(error) {
            // Handle scan failure, ignore for now
        }

        // Initialize scanner
        let html5QrcodeScanner = new Html5QrcodeScanner(
            "reader",
            { fps: 10, qrbox: {width: 250, height: 250} },
            /* verbose= */ false);
        html5QrcodeScanner.render(onScanSuccess, onScanFailure);

        // Auto-hide success messages after 8 seconds
        setTimeout(() => {
            const messages = document.querySelectorAll('.bg-green-100');
            messages.forEach(msg => {
                msg.style.display = 'none';
            });
        }, 8000);
    </script>
</body>
</html> 