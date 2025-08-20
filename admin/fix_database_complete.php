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
$fix_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_all'])) {
    try {
        // 1. Fix attendance table
        $fix_results['attendance'] = fixAttendanceTable($conn);
        
        // 2. Fix users table (add missing columns)
        $fix_results['users'] = fixUsersTable($conn);
        
        // 3. Fix membership_plans table
        $fix_results['membership_plans'] = fixMembershipPlansTable($conn);
        
        // 4. Fix payment_history table
        $fix_results['payment_history'] = fixPaymentHistoryTable($conn);
        
        // 5. Add sample data if needed
        $fix_results['sample_data'] = addSampleData($conn);
        
        $message = "✅ Database fix completed! Check results below.";
        $message_type = 'success';
        
    } catch (Exception $e) {
        $message = "❌ Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

function fixAttendanceTable($conn) {
    $results = [];
    
    // Check if attendance table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'attendance'")->num_rows > 0;
    
    if (!$table_exists) {
        // Create attendance table
        $create_table = "CREATE TABLE attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            check_in_time DATETIME NOT NULL,
            check_out_time DATETIME NULL,
            plan_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE SET NULL
        )";
        
        if ($conn->query($create_table)) {
            $results[] = "✅ Attendance table created";
        } else {
            $results[] = "❌ Error creating attendance table: " . $conn->error;
        }
    } else {
        $results[] = "✅ Attendance table exists";
        
        // Check and add plan_id column
        $columns = $conn->query("SHOW COLUMNS FROM attendance")->fetch_all(MYSQLI_ASSOC);
        $column_names = array_column($columns, 'Field');
        
        if (!in_array('plan_id', $column_names)) {
            $add_column = "ALTER TABLE attendance ADD COLUMN plan_id INT DEFAULT NULL";
            if ($conn->query($add_column)) {
                $results[] = "✅ plan_id column added to attendance";
            } else {
                $results[] = "❌ Error adding plan_id: " . $conn->error;
            }
        } else {
            $results[] = "✅ plan_id column exists in attendance";
        }
    }
    
    return $results;
}

function fixUsersTable($conn) {
    $results = [];
    
    // Check if users table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'users'")->num_rows > 0;
    
    if (!$table_exists) {
        $results[] = "❌ Users table does not exist - this is critical!";
        return $results;
    }
    
    // Check and add missing columns
    $columns = $conn->query("SHOW COLUMNS FROM users")->fetch_all(MYSQLI_ASSOC);
    $column_names = array_column($columns, 'Field');
    
    $required_columns = [
        'selected_plan_id' => "ALTER TABLE users ADD COLUMN selected_plan_id INT DEFAULT NULL",
        'qr_code' => "ALTER TABLE users ADD COLUMN qr_code TEXT NULL",
        'role' => "ALTER TABLE users ADD COLUMN role ENUM('admin', 'member', 'trainer') DEFAULT 'member'",
        'full_name' => "ALTER TABLE users ADD COLUMN full_name VARCHAR(255) NULL"
    ];
    
    foreach ($required_columns as $column => $sql) {
        if (!in_array($column, $column_names)) {
            if ($conn->query($sql)) {
                $results[] = "✅ Added $column column to users";
            } else {
                $results[] = "❌ Error adding $column: " . $conn->error;
            }
        } else {
            $results[] = "✅ $column column exists in users";
        }
    }
    
    return $results;
}

function fixMembershipPlansTable($conn) {
    $results = [];
    
    // Check if membership_plans table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'membership_plans'")->num_rows > 0;
    
    if (!$table_exists) {
        // Create membership_plans table
        $create_table = "CREATE TABLE membership_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            duration INT NOT NULL COMMENT 'Duration in days',
            features TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        if ($conn->query($create_table)) {
            $results[] = "✅ Membership plans table created";
        } else {
            $results[] = "❌ Error creating membership_plans: " . $conn->error;
        }
    } else {
        $results[] = "✅ Membership plans table exists";
    }
    
    return $results;
}

function fixPaymentHistoryTable($conn) {
    $results = [];
    
    // Check if payment_history table exists
    $table_exists = $conn->query("SHOW TABLES LIKE 'payment_history'")->num_rows > 0;
    
    if (!$table_exists) {
        // Create payment_history table
        $create_table = "CREATE TABLE payment_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_date DATETIME NOT NULL,
            payment_status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
            payment_method VARCHAR(100),
            proof_image VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (plan_id) REFERENCES membership_plans(id)
        )";
        
        if ($conn->query($create_table)) {
            $results[] = "✅ Payment history table created";
        } else {
            $results[] = "❌ Error creating payment_history: " . $conn->error;
        }
    } else {
        $results[] = "✅ Payment history table exists";
    }
    
    return $results;
}

function addSampleData($conn) {
    $results = [];
    
    // Check if we have any membership plans
    $plans_count = $conn->query("SELECT COUNT(*) as count FROM membership_plans")->fetch_assoc()['count'];
    
    if ($plans_count == 0) {
        // Add sample membership plans
        $sample_plans = [
            "INSERT INTO membership_plans (name, description, price, duration, features) VALUES 
            ('Basic Plan', 'Basic gym access', 1000.00, 30, 'Gym access, Basic equipment')",
            "INSERT INTO membership_plans (name, description, price, duration, features) VALUES 
            ('Premium Plan', 'Premium gym access with trainer', 2000.00, 30, 'Gym access, All equipment, Personal trainer')",
            "INSERT INTO membership_plans (name, description, price, duration, features) VALUES 
            ('VIP Plan', 'VIP gym access with all amenities', 3000.00, 30, 'Gym access, All equipment, Personal trainer, Spa access')"
        ];
        
        foreach ($sample_plans as $sql) {
            if ($conn->query($sql)) {
                $results[] = "✅ Added sample membership plan";
            } else {
                $results[] = "❌ Error adding sample plan: " . $conn->error;
            }
        }
    } else {
        $results[] = "✅ Membership plans already exist ($plans_count plans)";
    }
    
    // Check if we have any approved payments
    $payments_count = $conn->query("SELECT COUNT(*) as count FROM payment_history WHERE payment_status = 'Approved'")->fetch_assoc()['count'];
    
    if ($payments_count == 0) {
        // Add sample approved payment for first user
        $first_user = $conn->query("SELECT id FROM users WHERE role = 'member' LIMIT 1")->fetch_assoc();
        $first_plan = $conn->query("SELECT id FROM membership_plans LIMIT 1")->fetch_assoc();
        
        if ($first_user && $first_plan) {
            $sample_payment = "INSERT INTO payment_history (user_id, plan_id, amount, payment_date, payment_status, payment_method) 
                              VALUES ({$first_user['id']}, {$first_plan['id']}, 1000.00, NOW(), 'Approved', 'Cash')";
            
            if ($conn->query($sample_payment)) {
                $results[] = "✅ Added sample approved payment";
            } else {
                $results[] = "❌ Error adding sample payment: " . $conn->error;
            }
        } else {
            $results[] = "⚠️ No users or plans available for sample payment";
        }
    } else {
        $results[] = "✅ Approved payments already exist ($payments_count payments)";
    }
    
    return $results;
}

// Get current database status
$db_status = [];

try {
    // Check all required tables
    $tables = ['users', 'attendance', 'membership_plans', 'payment_history'];
    foreach ($tables as $table) {
        $exists = $conn->query("SHOW TABLES LIKE '$table'")->num_rows > 0;
        $db_status[$table] = $exists ? '✅ Exists' : '❌ Missing';
    }
    
    // Check if we have members with approved payments
    $members_with_payments = $conn->query("
        SELECT COUNT(DISTINCT u.id) as count 
        FROM users u 
        JOIN payment_history ph ON u.id = ph.user_id 
        WHERE ph.payment_status = 'Approved' AND u.role = 'member'
    ")->fetch_assoc()['count'];
    
    $db_status['members_with_approved_payments'] = $members_with_payments;
    
} catch (Exception $e) {
    $db_status['error'] = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Database - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <div class="bg-red-800 text-white p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold">Fix Database</h1>
                <div class="flex space-x-4">
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
                        <h3 class="font-semibold mb-2">Required Tables:</h3>
                        <ul class="space-y-1 text-sm">
                            <li>Users Table: <?php echo $db_status['users'] ?? 'Unknown'; ?></li>
                            <li>Attendance Table: <?php echo $db_status['attendance'] ?? 'Unknown'; ?></li>
                            <li>Membership Plans: <?php echo $db_status['membership_plans'] ?? 'Unknown'; ?></li>
                            <li>Payment History: <?php echo $db_status['payment_history'] ?? 'Unknown'; ?></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold mb-2">Data Status:</h3>
                        <ul class="space-y-1 text-sm">
                            <li>Members with Approved Payments: <?php echo $db_status['members_with_approved_payments'] ?? 'Unknown'; ?></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Fix Database -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Fix Database</h2>
                
                <?php if ($message): ?>
                    <div class="mb-4 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" class="mb-4">
                    <button type="submit" name="fix_all" class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 font-semibold">
                        <i class="fas fa-wrench mr-2"></i>Fix All Database Issues
                    </button>
                </form>
                
                <p class="text-sm text-gray-600">
                    This will check and fix all required tables and add sample data if needed.
                </p>
            </div>

            <!-- Fix Results -->
            <?php if (!empty($fix_results)): ?>
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Fix Results</h2>
                    
                    <?php foreach ($fix_results as $table => $results): ?>
                        <div class="mb-4">
                            <h3 class="font-semibold text-lg mb-2"><?php echo ucfirst($table); ?> Table:</h3>
                            <ul class="space-y-1 text-sm">
                                <?php foreach ($results as $result): ?>
                                    <li><?php echo htmlspecialchars($result); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Instructions -->
            <div class="bg-blue-50 rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4 text-blue-800">What This Fixes:</h2>
                <div class="space-y-4">
                    <div>
                        <h3 class="font-semibold text-blue-700">Database Tables:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm text-blue-700">
                            <li>Creates missing attendance table with proper structure</li>
                            <li>Adds plan_id column to attendance table</li>
                            <li>Ensures users table has all required columns</li>
                            <li>Creates membership_plans table if missing</li>
                            <li>Creates payment_history table if missing</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-blue-700">Sample Data:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm text-blue-700">
                            <li>Adds sample membership plans if none exist</li>
                            <li>Creates sample approved payment for testing</li>
                            <li>Ensures QR scanning has data to work with</li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="font-semibold text-blue-700">After Fix:</h3>
                        <ul class="list-disc list-inside space-y-1 text-sm text-blue-700">
                            <li>QR scanner should work properly</li>
                            <li>Attendance records will be saved correctly</li>
                            <li>Members can be checked in/out</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 