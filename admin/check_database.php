<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

$issues = [];
$status = [];

// Check 1: Attendance table
$attendance_exists = $conn->query("SHOW TABLES LIKE 'attendance'")->num_rows > 0;
if (!$attendance_exists) {
    $issues[] = "❌ Attendance table is missing";
} else {
    $status[] = "✅ Attendance table exists";
    
    // Check plan_id column
    $columns = $conn->query("SHOW COLUMNS FROM attendance")->fetch_all(MYSQLI_ASSOC);
    $column_names = array_column($columns, 'Field');
    if (!in_array('plan_id', $column_names)) {
        $issues[] = "❌ plan_id column missing in attendance table";
    } else {
        $status[] = "✅ plan_id column exists in attendance";
    }
}

// Check 2: Users table
$users_exists = $conn->query("SHOW TABLES LIKE 'users'")->num_rows > 0;
if (!$users_exists) {
    $issues[] = "❌ Users table is missing (CRITICAL!)";
} else {
    $status[] = "✅ Users table exists";
    
    // Check required columns
    $columns = $conn->query("SHOW COLUMNS FROM users")->fetch_all(MYSQLI_ASSOC);
    $column_names = array_column($columns, 'Field');
    $required_columns = ['selected_plan_id', 'qr_code', 'role', 'full_name'];
    
    foreach ($required_columns as $col) {
        if (!in_array($col, $column_names)) {
            $issues[] = "❌ $col column missing in users table";
        } else {
            $status[] = "✅ $col column exists in users";
        }
    }
}

// Check 3: Membership plans table
$plans_exists = $conn->query("SHOW TABLES LIKE 'membership_plans'")->num_rows > 0;
if (!$plans_exists) {
    $issues[] = "❌ Membership plans table is missing";
} else {
    $status[] = "✅ Membership plans table exists";
}

// Check 4: Payment history table
$payments_exists = $conn->query("SHOW TABLES LIKE 'payment_history'")->num_rows > 0;
if (!$payments_exists) {
    $issues[] = "❌ Payment history table is missing";
} else {
    $status[] = "✅ Payment history table exists";
}

// Check 5: Members with approved payments
$members_with_payments = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count 
    FROM users u 
    JOIN payment_history ph ON u.id = ph.user_id 
    WHERE ph.payment_status = 'Approved' AND u.role = 'member'
")->fetch_assoc()['count'];

if ($members_with_payments == 0) {
    $issues[] = "❌ No members with approved payments (QR scanning won't work)";
} else {
    $status[] = "✅ Found $members_with_payments members with approved payments";
}

// Check 6: Sample data
$plans_count = $conn->query("SELECT COUNT(*) as count FROM membership_plans")->fetch_assoc()['count'];
if ($plans_count == 0) {
    $issues[] = "❌ No membership plans available";
} else {
    $status[] = "✅ Found $plans_count membership plans";
}

$has_issues = count($issues) > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Database - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-red-800 text-white p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Database Check</h1>
                <div class="flex space-x-4">
                    <a href="fix_database_complete.php" class="bg-green-600 px-4 py-2 rounded hover:bg-green-700">
                        <i class="fas fa-wrench mr-2"></i>Fix Database
                    </a>
                    <a href="scan_attendance.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-qrcode mr-2"></i>QR Scanner
                    </a>
                    <a href="dashboard.php" class="bg-red-700 px-4 py-2 rounded hover:bg-red-600">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="max-w-4xl mx-auto p-6">
            
            <!-- Status Summary -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">
                    <?php if ($has_issues): ?>
                        <span class="text-red-600">❌ Database Issues Found</span>
                    <?php else: ?>
                        <span class="text-green-600">✅ Database is Ready</span>
                    <?php endif; ?>
                </h2>
                
                <?php if ($has_issues): ?>
                    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded">
                        <h3 class="font-semibold text-red-800 mb-2">Issues Found:</h3>
                        <ul class="space-y-1 text-sm text-red-700">
                            <?php foreach ($issues as $issue): ?>
                                <li><?php echo htmlspecialchars($issue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div class="text-center">
                        <a href="fix_database_complete.php" class="bg-red-600 text-white px-6 py-3 rounded-lg hover:bg-red-700 font-semibold inline-block">
                            <i class="fas fa-wrench mr-2"></i>Fix All Issues
                        </a>
                    </div>
                <?php else: ?>
                    <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded">
                        <h3 class="font-semibold text-green-800 mb-2">All Good!</h3>
                        <p class="text-green-700">Your database is properly configured for QR scanning.</p>
                    </div>
                    
                    <div class="text-center">
                        <a href="scan_attendance.php" class="bg-green-600 text-white px-6 py-3 rounded-lg hover:bg-green-700 font-semibold inline-block">
                            <i class="fas fa-qrcode mr-2"></i>Test QR Scanner
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Detailed Status -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Detailed Status</h2>
                
                <div class="space-y-4">
                    <?php foreach ($status as $item): ?>
                        <div class="flex items-center">
                            <span class="text-sm"><?php echo htmlspecialchars($item); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-blue-50 rounded-lg shadow p-6 mt-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">Quick Actions</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <a href="fix_database_complete.php" class="bg-blue-500 text-white p-4 rounded-lg hover:bg-blue-600 text-center">
                        <i class="fas fa-wrench text-2xl mb-2"></i>
                        <p class="font-semibold">Fix Database</p>
                        <p class="text-sm">Fix all missing tables and data</p>
                    </a>
                    <a href="debug_qr_scanner.php" class="bg-yellow-500 text-white p-4 rounded-lg hover:bg-yellow-600 text-center">
                        <i class="fas fa-bug text-2xl mb-2"></i>
                        <p class="font-semibold">Debug Scanner</p>
                        <p class="text-sm">Test camera and QR scanning</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 