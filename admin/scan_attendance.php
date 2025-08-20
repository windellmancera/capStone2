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
    
    // Debug: Log the received QR data
    error_log("QR Data received: " . $qr_data);
    
    // Check if this is an AJAX request
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    try {
        // Decode QR data
        $qr_info = json_decode($qr_data, true);
        
        // Debug: Log the decoded data
        error_log("Decoded QR info: " . print_r($qr_info, true));
        
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
                        
                        // Debug: Log the check-in attempt
                        error_log("Attempting check-in for user_id: $user_id, plan_id: " . $user['selected_plan_id']);
                        
                        if ($checkin_stmt->execute()) {
                            $attendance_id = $conn->insert_id;
                            error_log("Check-in successful. Attendance ID: $attendance_id");
                            
                            $message = "✅ CHECK-IN SUCCESSFUL!\n\nMember: " . ($user['full_name'] ?? $user['username']) . 
                                     "\nPlan: " . $user['plan_name'] . 
                                     "\nTime: " . date('H:i:s');
                            $message_type = 'success';
                            
                            // If AJAX request, return JSON response
                            if ($is_ajax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'message' => $message,
                                    'redirect' => 'attendance_history.php?success=checkin&member=' . urlencode($user['full_name'] ?? $user['username'])
                                ]);
                                exit();
                            }
                            
                            // Redirect to attendance history after successful check-in
                            header("Location: attendance_history.php?success=checkin&member=" . urlencode($user['full_name'] ?? $user['username']));
                            exit();
                        } else {
                            error_log("Check-in failed. SQL Error: " . $checkin_stmt->error);
                            $message = "❌ Error recording check-in: " . $checkin_stmt->error;
                            $message_type = 'error';
                            
                            // If AJAX request, return JSON response
                            if ($is_ajax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => false,
                                    'message' => $message
                                ]);
                                exit();
                            }
                        }
                    } else {
                        // Check out
                        $checkout_sql = "UPDATE attendance SET check_out_time = NOW() WHERE id = ?";
                        $checkout_stmt = $conn->prepare($checkout_sql);
                        $checkout_stmt->bind_param("i", $attendance['id']);
                        
                        // Debug: Log the check-out attempt
                        error_log("Attempting check-out for attendance_id: " . $attendance['id']);
                        
                        if ($checkout_stmt->execute()) {
                            error_log("Check-out successful for attendance_id: " . $attendance['id']);
                            
                            $message = "✅ CHECK-OUT SUCCESSFUL!\n\nMember: " . ($user['full_name'] ?? $user['username']) . 
                                     "\nPlan: " . $user['plan_name'] . 
                                     "\nDuration: " . calculateDuration($attendance['check_in_time']) . " minutes";
                            $message_type = 'success';
                            
                            // If AJAX request, return JSON response
                            if ($is_ajax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'message' => $message,
                                    'redirect' => 'attendance_history.php?success=checkout&member=' . urlencode($user['full_name'] ?? $user['username'])
                                ]);
                                exit();
                            }
                            
                            // Redirect to attendance history after successful check-out
                            header("Location: attendance_history.php?success=checkout&member=" . urlencode($user['full_name'] ?? $user['username']));
                            exit();
                        } else {
                            error_log("Check-out failed. SQL Error: " . $checkout_stmt->error);
                            $message = "❌ Error recording check-out: " . $checkout_stmt->error;
                            $message_type = 'error';
                            
                            // If AJAX request, return JSON response
                            if ($is_ajax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => false,
                                    'message' => $message
                                ]);
                                exit();
                            }
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
            error_log("Invalid QR code data. QR info: " . print_r($qr_info, true));
            $message = "❌ Invalid QR code data - missing user_id";
            $message_type = 'error';
        }
    } catch (Exception $e) {
        error_log("Error processing QR code: " . $e->getMessage());
        $message = "❌ Error processing QR code: " . $e->getMessage();
        $message_type = 'error';
    }
} else {
    // Debug: Log when no QR data is received
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("POST request received but no qr_data found. POST data: " . print_r($_POST, true));
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
                <div class="flex space-x-4">
                    <a href="setup_database.php" class="bg-purple-600 px-4 py-2 rounded hover:bg-purple-700">
                        <i class="fas fa-database mr-2"></i>Setup Database
                    </a>
                    <a href="check_database.php" class="bg-green-600 px-4 py-2 rounded hover:bg-green-700">
                        <i class="fas fa-check-circle mr-2"></i>Check Database
                    </a>
                    <a href="debug_qr_scanner.php" class="bg-yellow-600 px-4 py-2 rounded hover:bg-yellow-700">
                        <i class="fas fa-bug mr-2"></i>Debug Scanner
                    </a>
                    <a href="test_qr_scanning.php" class="bg-blue-600 px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-vial mr-2"></i>Test Scanner
                    </a>
                    <a href="attendance_history.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-clock mr-2"></i>Attendance History
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
                    
                    <!-- Camera Selection -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Camera:</label>
                        <select id="camera-select" class="w-full p-2 border border-gray-300 rounded mb-2">
                            <option value="">Loading cameras...</option>
                        </select>
                        <button onclick="restartScanner()" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                            <i class="fas fa-refresh mr-2"></i>Restart Scanner
                        </button>
                    </div>
                    
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
        let html5QrcodeScanner = null;
        let isScanning = false;

        // Initialize QR Scanner
        function onScanSuccess(decodedText, decodedResult) {
            console.log('QR Code scanned:', decodedText);
            
            // Stop scanner
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
                isScanning = false;
            }
            
            // Show loading indicator
            const reader = document.getElementById('reader');
            reader.innerHTML = '<div class="flex items-center justify-center h-full"><div class="text-center"><i class="fas fa-spinner fa-spin text-2xl text-blue-500 mb-2"></i><p>Processing QR code...</p></div></div>';
            
            // Try to parse the QR data to show what was scanned
            try {
                const qrData = JSON.parse(decodedText);
                console.log('Parsed QR data:', qrData);
                
                // Show what was scanned
                const messageDiv = document.createElement('div');
                messageDiv.className = 'mb-4 p-4 rounded-lg bg-blue-100 text-blue-800';
                messageDiv.innerHTML = `
                    <div class="text-center">
                        <i class="fas fa-qrcode text-2xl mb-2"></i>
                        <p><strong>QR Code Detected!</strong></p>
                        <p class="text-sm">User ID: ${qrData.user_id || 'Unknown'}</p>
                        <p class="text-sm">Processing...</p>
                    </div>
                `;
                
                // Insert before the reader
                reader.parentNode.insertBefore(messageDiv, reader);
                
            } catch (e) {
                console.log('QR data is not JSON:', decodedText);
            }
            
            // Use AJAX instead of form submission to prevent page refresh
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'qr_data=' + encodeURIComponent(decodedText)
            })
            .then(response => {
                console.log('Response received:', response);
                
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    return response.json().then(data => {
                        console.log('JSON response:', data);
                        
                        if (data.success) {
                            // Show success message
                            const successDiv = document.createElement('div');
                            successDiv.className = 'mb-4 p-4 rounded-lg bg-green-100 text-green-800';
                            successDiv.innerHTML = `
                                <div class="text-center">
                                    <i class="fas fa-check-circle text-2xl mb-2"></i>
                                    <p><strong>Success!</strong></p>
                                    <p class="text-sm">${data.message}</p>
                                </div>
                            `;
                            
                            // Insert before the reader
                            reader.parentNode.insertBefore(successDiv, reader);
                            
                            // Redirect after 2 seconds
                            setTimeout(() => {
                                if (data.redirect) {
                                    window.location.href = data.redirect;
                                } else {
                                    window.location.reload();
                                }
                            }, 2000);
                        } else {
                            // Show error message
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'mb-4 p-4 rounded-lg bg-red-100 text-red-800';
                            errorDiv.innerHTML = `
                                <div class="text-center">
                                    <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                                    <p><strong>Error!</strong></p>
                                    <p class="text-sm">${data.message}</p>
                                </div>
                            `;
                            
                            // Insert before the reader
                            reader.parentNode.insertBefore(errorDiv, reader);
                            
                            // Restart scanner after 3 seconds
                            setTimeout(() => {
                                initializeScanner();
                            }, 3000);
                        }
                    });
                } else {
                    // Handle non-JSON response (redirect)
                    if (response.redirected) {
                        window.location.href = response.url;
                    } else {
                        window.location.reload();
                    }
                }
            })
            .catch(error => {
                console.error('Error submitting QR data:', error);
                // Show error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'mb-4 p-4 rounded-lg bg-red-100 text-red-800';
                errorDiv.innerHTML = `
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                        <p><strong>Network Error!</strong></p>
                        <p class="text-sm">Failed to process QR code. Please try again.</p>
                    </div>
                `;
                
                // Insert before the reader
                reader.parentNode.insertBefore(errorDiv, reader);
                
                // Restart scanner after 3 seconds
                setTimeout(() => {
                    initializeScanner();
                }, 3000);
            });
        }

        function onScanFailure(error) {
            // Only log errors, don't stop scanning
            console.log('Scan failed:', error);
        }

        // Initialize scanner with better configuration
        function initializeScanner() {
            const reader = document.getElementById('reader');
            
            // Clear any existing content
            reader.innerHTML = '';
            
            try {
                html5QrcodeScanner = new Html5QrcodeScanner(
                    "reader",
                    { 
                        fps: 10, 
                        qrbox: {width: 250, height: 250},
                        aspectRatio: 1.0,
                        disableFlip: false
                    },
                    false
                );
                
                html5QrcodeScanner.render(onScanSuccess, onScanFailure);
                isScanning = true;
                
                console.log('QR Scanner initialized successfully');
            } catch (error) {
                console.error('Error initializing QR scanner:', error);
                reader.innerHTML = `
                    <div class="flex items-center justify-center h-full">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-2xl text-red-500 mb-2"></i>
                            <p class="text-red-600">Camera access denied or not available</p>
                            <p class="text-sm text-gray-500 mt-2">Please allow camera access and refresh the page</p>
                        </div>
                    </div>
                `;
            }
        }

        // Camera selection
        async function getCameras() {
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const videoDevices = devices.filter(device => device.kind === 'videoinput');
                
                const cameraSelect = document.getElementById('camera-select');
                if (cameraSelect) {
                    cameraSelect.innerHTML = '';
                    
                    videoDevices.forEach((device, index) => {
                        const option = document.createElement('option');
                        option.value = device.deviceId;
                        option.text = device.label || `Camera ${index + 1}`;
                        cameraSelect.appendChild(option);
                    });
                    
                    // Auto-select first camera
                    if (videoDevices.length > 0) {
                        cameraSelect.value = videoDevices[0].deviceId;
                    }
                }
            } catch (error) {
                console.error('Error getting cameras:', error);
            }
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Request camera permission first
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    // Stop the stream immediately
                    stream.getTracks().forEach(track => track.stop());
                    
                    // Get available cameras
                    getCameras();
                    
                    // Initialize scanner
                    initializeScanner();
                })
                .catch(function(error) {
                    console.error('Camera access denied:', error);
                    const reader = document.getElementById('reader');
                    reader.innerHTML = `
                        <div class="flex items-center justify-center h-full">
                            <div class="text-center">
                                <i class="fas fa-camera-slash text-2xl text-red-500 mb-2"></i>
                                <p class="text-red-600">Camera access denied</p>
                                <p class="text-sm text-gray-500 mt-2">Please allow camera access in your browser settings</p>
                                <button onclick="location.reload()" class="mt-4 bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                                    <i class="fas fa-refresh mr-2"></i>Retry
                                </button>
                            </div>
                        </div>
                    `;
                });

            // Auto-hide success messages after 5 seconds
            setTimeout(() => {
                const messages = document.querySelectorAll('.bg-green-100');
                messages.forEach(msg => {
                    msg.style.display = 'none';
                });
            }, 5000);
        });

        // Restart scanner function
        function restartScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
            initializeScanner();
        }
    </script>
</body>
</html> 