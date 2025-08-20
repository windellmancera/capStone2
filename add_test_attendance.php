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

// Handle adding test attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_test_data'])) {
    
    // Check if attendance table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'attendance'")->num_rows > 0;
    
    if (!$table_exists) {
        // Create attendance table if it doesn't exist
        $create_table = "CREATE TABLE attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            check_in_time DATETIME NOT NULL,
            check_out_time DATETIME NULL,
            plan_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (plan_id) REFERENCES membership_plans(id)
        )";
        
        if ($conn->query($create_table)) {
            $message .= "✅ Attendance table created. ";
        } else {
            $message .= "❌ Error creating table: " . $conn->error . ". ";
        }
    }
    
    // Get user's plan
    $user_sql = "SELECT selected_plan_id FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user = $user_stmt->get_result()->fetch_assoc();
    
    $plan_id = $user['selected_plan_id'] ?? 1; // Default to plan 1 if none
    
    // Add test attendance for the last 7 days
    $success_count = 0;
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        
        // Skip weekends (optional)
        $day_of_week = date('N', strtotime($date));
        if ($day_of_week >= 6) continue; // Skip Saturday and Sunday
        
        // Random check-in time between 6 AM and 8 PM
        $hour = rand(6, 20);
        $minute = rand(0, 59);
        $check_in_time = $date . " " . sprintf("%02d:%02d:00", $hour, $minute);
        
        // Random duration between 30 and 120 minutes
        $duration_minutes = rand(30, 120);
        $check_out_time = date('Y-m-d H:i:s', strtotime($check_in_time . " + $duration_minutes minutes"));
        
        $insert_sql = "INSERT INTO attendance (user_id, check_in_time, check_out_time, plan_id) 
                       VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issi", $user_id, $check_in_time, $check_out_time, $plan_id);
        
        if ($insert_stmt->execute()) {
            $success_count++;
        }
    }
    
    if ($success_count > 0) {
        $message .= "✅ Added $success_count test attendance records. Your analytics should now show 'Active'!";
    } else {
        $message .= "❌ No attendance records were added.";
    }
}

// Get current attendance count
$attendance_sql = "SELECT COUNT(*) as count FROM attendance WHERE user_id = ?";
$attendance_stmt = $conn->prepare($attendance_sql);
$attendance_stmt->bind_param("i", $user_id);
$attendance_stmt->execute();
$attendance_count = $attendance_stmt->get_result()->fetch_assoc()['count'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Test Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Add Test Attendance Data</h1>
        
        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Why Your Analytics Show "Inactive"</h2>
            
            <div class="space-y-4">
                <div class="p-4 bg-blue-50 rounded">
                    <h3 class="font-semibold text-blue-800 mb-2">Current Status:</h3>
                    <ul class="list-disc list-inside space-y-1 text-sm">
                        <li><strong>Total Check-ins:</strong> <?php echo $attendance_count; ?></li>
                        <li><strong>Engagement Level:</strong> <?php echo $attendance_count > 0 ? 'Active' : 'Inactive'; ?></li>
                        <li><strong>Consistency Score:</strong> <?php echo $attendance_count > 0 ? 'Good' : 'Poor'; ?></li>
                    </ul>
                </div>
                
                <div class="p-4 bg-yellow-50 rounded">
                    <h3 class="font-semibold text-yellow-800 mb-2">The Issue:</h3>
                    <p class="text-sm">
                        Your fitness analytics show "Inactive" because you haven't checked in to the gym yet. 
                        The system tracks your gym usage to calculate engagement levels.
                    </p>
                </div>
                
                <div class="p-4 bg-green-50 rounded">
                    <h3 class="font-semibold text-green-800 mb-2">Solutions:</h3>
                    <ol class="list-decimal list-inside space-y-1 text-sm">
                        <li><strong>Real Check-ins:</strong> Use the QR code scanner to check in when you visit the gym</li>
                        <li><strong>Test Data:</strong> Add test attendance data to see how analytics work</li>
                        <li><strong>Admin Scanner:</strong> Go to admin panel and scan your QR code</li>
                    </ol>
                </div>
                
                <form method="post" class="space-y-4">
                    <div class="p-4 bg-gray-50 rounded">
                        <h3 class="font-semibold mb-2">Add Test Attendance Data</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            This will add realistic attendance records for the past week to improve your analytics.
                        </p>
                        <button type="submit" name="add_test_data" 
                                class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">
                            📊 Add Test Attendance Data
                        </button>
                    </div>
                </form>
                
                <div class="space-y-2">
                    <a href="member/profile.php" 
                       class="block w-full bg-green-500 text-white text-center px-4 py-2 rounded hover:bg-green-600">
                        📄 Check Updated Profile
                    </a>
                    <a href="admin/attendance_scanner.php" 
                       class="block w-full bg-purple-500 text-white text-center px-4 py-2 rounded hover:bg-purple-600">
                        📱 Test QR Scanner
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 