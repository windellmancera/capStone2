<?php
require 'db.php';

echo "<h1>Database Fix Script</h1>";

// Function to safely execute SQL
function executeSQL($conn, $sql, $description) {
    try {
        if ($conn->query($sql)) {
            echo "✅ $description<br>";
            return true;
        } else {
            echo "❌ Error in $description: " . $conn->error . "<br>";
            return false;
        }
    } catch (Exception $e) {
        echo "❌ Exception in $description: " . $e->getMessage() . "<br>";
        return false;
    }
}

// 1. Add missing columns to users table
echo "<h2>1. Adding missing columns to users table</h2>";

$user_columns = [
    'selected_plan_id' => "ALTER TABLE users ADD COLUMN selected_plan_id INT DEFAULT NULL",
    'role' => "ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'member'",
    'full_name' => "ALTER TABLE users ADD COLUMN full_name VARCHAR(255) DEFAULT NULL",
    'qr_code' => "ALTER TABLE users ADD COLUMN qr_code TEXT DEFAULT NULL",
    'membership_start_date' => "ALTER TABLE users ADD COLUMN membership_start_date DATE DEFAULT NULL",
    'membership_end_date' => "ALTER TABLE users ADD COLUMN membership_end_date DATE DEFAULT NULL",
    'payment_status' => "ALTER TABLE users ADD COLUMN payment_status ENUM('active', 'inactive', 'pending') DEFAULT 'inactive'",
    'last_payment_date' => "ALTER TABLE users ADD COLUMN last_payment_date DATE DEFAULT NULL",
    'balance' => "ALTER TABLE users ADD COLUMN balance DECIMAL(10,2) DEFAULT 0.00"
];

foreach ($user_columns as $column => $sql) {
    $check_sql = "SHOW COLUMNS FROM users LIKE '$column'";
    $result = $conn->query($check_sql);
    if ($result->num_rows == 0) {
        executeSQL($conn, $sql, "Added column: $column");
    } else {
        echo "✅ Column $column already exists<br>";
    }
}

// 2. Create payment_history table
echo "<h2>2. Creating payment_history table</h2>";

$create_payment_history = "CREATE TABLE IF NOT EXISTS payment_history (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    plan_id INT(11) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    payment_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    payment_method VARCHAR(100) DEFAULT NULL,
    proof_image VARCHAR(255) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    reference_number VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    KEY plan_id (plan_id),
    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_plan FOREIGN KEY (plan_id) REFERENCES membership_plans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

executeSQL($conn, $create_payment_history, "Created payment_history table");

// 3. Update membership_plans table structure
echo "<h2>3. Updating membership_plans table</h2>";

$plan_columns = [
    'description' => "ALTER TABLE membership_plans ADD COLUMN description TEXT DEFAULT NULL",
    'features' => "ALTER TABLE membership_plans ADD COLUMN features TEXT DEFAULT NULL"
];

foreach ($plan_columns as $column => $sql) {
    $check_sql = "SHOW COLUMNS FROM membership_plans LIKE '$column'";
    $result = $conn->query($check_sql);
    if ($result->num_rows == 0) {
        executeSQL($conn, $sql, "Added column: $column to membership_plans");
    } else {
        echo "✅ Column $column already exists in membership_plans<br>";
    }
}

// 4. Create trainers table
echo "<h2>4. Creating trainers table</h2>";

$create_trainers = "CREATE TABLE IF NOT EXISTS trainers (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    specialization VARCHAR(255) DEFAULT NULL,
    experience_years INT(11) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    hourly_rate DECIMAL(10,2) DEFAULT NULL,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    CONSTRAINT fk_trainer_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

executeSQL($conn, $create_trainers, "Created trainers table");

// 5. Create equipment table
echo "<h2>5. Creating equipment table</h2>";

$create_equipment = "CREATE TABLE IF NOT EXISTS equipment (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('Available','In Use','Maintenance','Out of Order') DEFAULT 'Available',
    location VARCHAR(255) DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

executeSQL($conn, $create_equipment, "Created equipment table");

// 6. Update announcements table
echo "<h2>6. Updating announcements table</h2>";

$announcement_columns = [
    'priority' => "ALTER TABLE announcements ADD COLUMN priority ENUM('Low','Medium','High','Urgent') DEFAULT 'Medium'",
    'is_active' => "ALTER TABLE announcements ADD COLUMN is_active TINYINT(1) DEFAULT 1",
    'created_by' => "ALTER TABLE announcements ADD COLUMN created_by INT(11) DEFAULT NULL"
];

foreach ($announcement_columns as $column => $sql) {
    $check_sql = "SHOW COLUMNS FROM announcements LIKE '$column'";
    $result = $conn->query($check_sql);
    if ($result->num_rows == 0) {
        executeSQL($conn, $sql, "Added column: $column to announcements");
    } else {
        echo "✅ Column $column already exists in announcements<br>";
    }
}

// 7. Update attendance table
echo "<h2>7. Updating attendance table</h2>";

$attendance_columns = [
    'check_out_time' => "ALTER TABLE attendance ADD COLUMN check_out_time DATETIME DEFAULT NULL",
    'plan_id' => "ALTER TABLE attendance ADD COLUMN plan_id INT(11) DEFAULT NULL",
    'created_at' => "ALTER TABLE attendance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
];

foreach ($attendance_columns as $column => $sql) {
    $check_sql = "SHOW COLUMNS FROM attendance LIKE '$column'";
    $result = $conn->query($check_sql);
    if ($result->num_rows == 0) {
        executeSQL($conn, $sql, "Added column: $column to attendance");
    } else {
        echo "✅ Column $column already exists in attendance<br>";
    }
}

// 8. Add sample data for testing
echo "<h2>8. Adding sample data</h2>";

// Add sample payment records
$sample_payments = [
    "INSERT INTO payment_history (user_id, plan_id, amount, payment_date, payment_status, payment_method) VALUES (20, 2, 1200.00, NOW() - INTERVAL 5 DAY, 'Approved', 'Cash')",
    "INSERT INTO payment_history (user_id, plan_id, amount, payment_date, payment_status, payment_method) VALUES (23, 1, 100.00, NOW() - INTERVAL 3 DAY, 'Approved', 'GCash')",
    "INSERT INTO payment_history (user_id, plan_id, amount, payment_date, payment_status, payment_method) VALUES (24, 3, 8000.00, NOW() - INTERVAL 1 DAY, 'Pending', 'PayMaya')"
];

foreach ($sample_payments as $payment_sql) {
    executeSQL($conn, $payment_sql, "Added sample payment");
}

// Update users with selected plans
$update_users = [
    "UPDATE users SET selected_plan_id = 2, role = 'member', full_name = 'Owapawi User', payment_status = 'active', membership_start_date = CURDATE(), membership_end_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE id = 20",
    "UPDATE users SET selected_plan_id = 1, role = 'member', full_name = 'Windell User', payment_status = 'active', membership_start_date = CURDATE(), membership_end_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) WHERE id = 23",
    "UPDATE users SET selected_plan_id = 3, role = 'member', full_name = 'Frenwin User', payment_status = 'pending' WHERE id = 24"
];

foreach ($update_users as $update_sql) {
    executeSQL($conn, $update_sql, "Updated user data");
}

// 9. Create indexes for better performance
echo "<h2>9. Creating indexes</h2>";

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_payment_user_status ON payment_history (user_id, payment_status)",
    "CREATE INDEX IF NOT EXISTS idx_payment_date ON payment_history (payment_date)",
    "CREATE INDEX IF NOT EXISTS idx_users_role ON users (role)",
    "CREATE INDEX IF NOT EXISTS idx_users_selected_plan ON users (selected_plan_id)"
];

foreach ($indexes as $index_sql) {
    executeSQL($conn, $index_sql, "Created index");
}

// 10. Verify the fix
echo "<h2>10. Verification</h2>";

$verification_queries = [
    "SELECT COUNT(*) as count FROM payment_history" => "Payment History Records",
    "SELECT COUNT(*) as count FROM users WHERE role = 'member'" => "Member Users",
    "SELECT COUNT(*) as count FROM users WHERE selected_plan_id IS NOT NULL" => "Users with Selected Plans",
    "SELECT SUM(amount) as total FROM payment_history WHERE payment_status = 'Approved'" => "Total Approved Revenue"
];

foreach ($verification_queries as $sql => $description) {
    $result = $conn->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ $description: " . $row['count'] . "<br>";
    } else {
        echo "❌ Error checking $description: " . $conn->error . "<br>";
    }
}

echo "<h2>Database fix completed!</h2>";
echo "<p>The reports system should now work properly. Try accessing the reports page again.</p>";
?> 