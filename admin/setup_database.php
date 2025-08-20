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
$setup_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_database'])) {
    try {
        // Read the SQL file
        $sql_file = '../sql/setup_complete_database.sql';
        
        if (!file_exists($sql_file)) {
            throw new Exception("SQL file not found: $sql_file");
        }
        
        $sql_content = file_get_contents($sql_file);
        
        // Split SQL into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql_content)));
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue; // Skip comments and empty lines
            }
            
            try {
                if ($conn->query($statement)) {
                    $success_count++;
                    $setup_results[] = "✅ " . substr($statement, 0, 50) . "...";
                } else {
                    $error_count++;
                    $setup_results[] = "❌ Error: " . $conn->error . " in: " . substr($statement, 0, 50) . "...";
                }
            } catch (Exception $e) {
                $error_count++;
                $setup_results[] = "❌ Exception: " . $e->getMessage() . " in: " . substr($statement, 0, 50) . "...";
            }
        }
        
        if ($error_count == 0) {
            $message = "✅ Database setup completed successfully! $success_count statements executed.";
            $message_type = 'success';
        } else {
            $message = "⚠️ Database setup completed with $error_count errors. $success_count statements executed successfully.";
            $message_type = 'warning';
        }
        
    } catch (Exception $e) {
        $message = "❌ Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Check current database status
$db_status = [];

try {
    // Check all required tables
    $tables = ['users', 'attendance', 'membership_plans', 'payment_history', 'trainers', 'equipment', 'announcements', 'feedback'];
    foreach ($tables as $table) {
        $exists = $conn->query("SHOW TABLES LIKE '$table'")->num_rows > 0;
        $db_status[$table] = $exists ? '✅ Exists' : '❌ Missing';
    }
    
    // Check if we have sample data
    $users_count = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    $plans_count = $conn->query("SELECT COUNT(*) as count FROM membership_plans")->fetch_assoc()['count'];
    $payments_count = $conn->query("SELECT COUNT(*) as count FROM payment_history WHERE payment_status = 'Approved'")->fetch_assoc()['count'];
    
    $db_status['users_count'] = $users_count;
    $db_status['plans_count'] = $plans_count;
    $db_status['approved_payments'] = $payments_count;
    
} catch (Exception $e) {
    $db_status['error'] = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Database - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-red-800 text-white p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Setup Database</h1>
                <div class="flex space-x-4">
                    <a href="check_database.php" class="bg-blue-600 px-4 py-2 rounded hover:bg-blue-700">
                        <i class="fas fa-database mr-2"></i>Check Database
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

        <div class="max-w-6xl mx-auto p-6">
            
            <!-- Current Database Status -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Current Database Status</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h3 class="font-semibold mb-2">Tables:</h3>
                        <ul class="space-y-1 text-sm">
                            <li>Users: <?php echo $db_status['users'] ?? 'Unknown'; ?></li>
                            <li>Attendance: <?php echo $db_status['attendance'] ?? 'Unknown'; ?></li>
                            <li>Membership Plans: <?php echo $db_status['membership_plans'] ?? 'Unknown'; ?></li>
                            <li>Payment History: <?php echo $db_status['payment_history'] ?? 'Unknown'; ?></li>
                            <li>Trainers: <?php echo $db_status['trainers'] ?? 'Unknown'; ?></li>
                            <li>Equipment: <?php echo $db_status['equipment'] ?? 'Unknown'; ?></li>
                            <li>Announcements: <?php echo $db_status['announcements'] ?? 'Unknown'; ?></li>
                            <li>Feedback: <?php echo $db_status['feedback'] ?? 'Unknown'; ?></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold mb-2">Data:</h3>
                        <ul class="space-y-1 text-sm">
                            <li>Users: <?php echo $db_status['users_count'] ?? 'Unknown'; ?></li>
                            <li>Membership Plans: <?php echo $db_status['plans_count'] ?? 'Unknown'; ?></li>
                            <li>Approved Payments: <?php echo $db_status['approved_payments'] ?? 'Unknown'; ?></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Setup Database -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Setup Complete Database</h2>
                
                <?php if ($message): ?>
                    <div class="mb-4 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : ($message_type === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" class="mb-4">
                    <button type="submit" name="setup_database" class="bg-green-500 text-white px-6 py-3 rounded-lg hover:bg-green-600 font-semibold">
                        <i class="fas fa-database mr-2"></i>Setup Complete Database
                    </button>
                </form>
                
                <div class="bg-blue-50 p-4 rounded-lg">
                    <h3 class="font-semibold text-blue-800 mb-2">What this will create:</h3>
                    <ul class="list-disc list-inside space-y-1 text-sm text-blue-700">
                        <li>All required tables (users, attendance, membership_plans, etc.)</li>
                        <li>Sample membership plans (Basic, Premium, VIP)</li>
                        <li>Sample admin user (admin/admin123)</li>
                        <li>Sample member user (member1/member123)</li>
                        <li>Sample approved payment for testing</li>
                        <li>Sample equipment and announcements</li>
                        <li>Proper foreign key relationships</li>
                        <li>Performance indexes</li>
                    </ul>
                </div>
            </div>

            <!-- Setup Results -->
            <?php if (!empty($setup_results)): ?>
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Setup Results</h2>
                    <div class="max-h-96 overflow-y-auto">
                        <?php foreach ($setup_results as $result): ?>
                            <div class="py-1 text-sm">
                                <?php echo htmlspecialchars($result); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="bg-green-50 rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4 text-green-800">After Setup:</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="check_database.php" class="bg-green-500 text-white p-4 rounded-lg hover:bg-green-600 text-center">
                        <i class="fas fa-check-circle text-2xl mb-2"></i>
                        <p class="font-semibold">Verify Setup</p>
                        <p class="text-sm">Check if everything is working</p>
                    </a>
                    <a href="scan_attendance.php" class="bg-blue-500 text-white p-4 rounded-lg hover:bg-blue-600 text-center">
                        <i class="fas fa-qrcode text-2xl mb-2"></i>
                        <p class="font-semibold">Test QR Scanner</p>
                        <p class="text-sm">Try scanning the sample member</p>
                    </a>
                    <a href="debug_qr_scanner.php" class="bg-yellow-500 text-white p-4 rounded-lg hover:bg-yellow-600 text-center">
                        <i class="fas fa-bug text-2xl mb-2"></i>
                        <p class="font-semibold">Debug Scanner</p>
                        <p class="text-sm">Test camera and QR functionality</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 