<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member/member_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$issues = [];
$fixes = [];

// Check if attendance table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'attendance'")->num_rows > 0;

if (!$table_exists) {
    $issues[] = "Attendance table does not exist";
    $fixes[] = "CREATE TABLE attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        check_in_time DATETIME NOT NULL,
        check_out_time DATETIME NULL,
        plan_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (plan_id) REFERENCES membership_plans(id)
    )";
}

// If table exists, check structure
if ($table_exists) {
    $columns = $conn->query("SHOW COLUMNS FROM attendance")->fetch_all(MYSQLI_ASSOC);
    $column_names = array_column($columns, 'Field');
    
    $required_columns = ['id', 'user_id', 'check_in_time', 'check_out_time', 'plan_id'];
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $column_names)) {
            $issues[] = "Missing column: $col";
        }
    }
}

// Test QR code generation
$user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration
              FROM users u 
              LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
              WHERE u.id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

$payment_sql = "SELECT * FROM payment_history 
                WHERE user_id = ? AND payment_status = 'Approved' 
                ORDER BY payment_date DESC LIMIT 1";
$payment_stmt = $conn->prepare($payment_sql);
$payment_stmt->bind_param("i", $user_id);
$payment_stmt->execute();
$payment = $payment_stmt->get_result()->fetch_assoc();

$qr_data = [
    'user_id' => $user_id,
    'payment_id' => $payment['id'] ?? 0,
    'plan_name' => $user['plan_name'] ?? 'No Plan',
    'plan_duration' => $user['plan_duration'] ?? 0,
    'timestamp' => time(),
    'hash' => hash('sha256', $user_id . ($payment['id'] ?? 0) . time() . 'ALMO_FITNESS_SECRET')
];

// Test attendance insertion
if ($table_exists && empty($issues)) {
    try {
        $test_sql = "INSERT INTO attendance (user_id, check_in_time, plan_id) VALUES (?, NOW(), ?)";
        $test_stmt = $conn->prepare($test_sql);
        $test_stmt->bind_param("ii", $user_id, $user['selected_plan_id']);
        
        if ($test_stmt->execute()) {
            $test_id = $conn->insert_id;
            // Delete the test record
            $conn->query("DELETE FROM attendance WHERE id = $test_id");
            $test_result = "✅ Attendance table works correctly";
        } else {
            $test_result = "❌ Error inserting test attendance: " . $conn->error;
        }
    } catch (Exception $e) {
        $test_result = "❌ Exception: " . $e->getMessage();
    }
} else {
    $test_result = "❌ Cannot test - table issues found";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Table Check</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Attendance System Check</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Table Status -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Attendance Table Status</h2>
                <div class="space-y-2">
                    <p><strong>Table Exists:</strong> 
                        <span class="<?php echo $table_exists ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $table_exists ? '✅ Yes' : '❌ No'; ?>
                        </span>
                    </p>
                    
                    <?php if ($table_exists): ?>
                        <p><strong>Columns:</strong></p>
                        <ul class="list-disc list-inside ml-4">
                            <?php foreach ($columns as $col): ?>
                                <li><?php echo $col['Field']; ?> (<?php echo $col['Type']; ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Issues & Fixes -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Issues Found</h2>
                <?php if (empty($issues)): ?>
                    <p class="text-green-600">✅ No issues found!</p>
                <?php else: ?>
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($issues as $issue): ?>
                            <li class="text-red-600">❌ <?php echo $issue; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <?php if (!empty($fixes)): ?>
                        <h3 class="font-semibold mt-4 mb-2">SQL Fixes:</h3>
                        <div class="bg-gray-100 p-3 rounded text-sm">
                            <pre><?php echo implode("\n\n", $fixes); ?></pre>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Test Results -->
            <div class="bg-white p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Test Results</h2>
                <div class="space-y-4">
                    <p><strong>Attendance Insertion Test:</strong> <?php echo $test_result; ?></p>
                    
                    <div>
                        <strong>QR Code Data:</strong>
                        <div class="bg-gray-100 p-3 rounded mt-2">
                            <pre class="text-sm"><?php echo json_encode($qr_data, JSON_PRETTY_PRINT); ?></pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Fix Button -->
            <?php if (!empty($issues)): ?>
                <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                    <h2 class="text-xl font-semibold mb-4">Quick Fix</h2>
                    <form method="post" action="fix_attendance_table.php">
                        <button type="submit" class="bg-blue-500 text-white px-6 py-3 rounded hover:bg-blue-600">
                            Fix Attendance Table
                        </button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>
</body>
</html> 