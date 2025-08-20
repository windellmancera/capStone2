<?php
require 'db.php';

echo "<h1>Database Structure Test</h1>";

// Test 1: Check if payment_history table exists
echo "<h2>1. Checking payment_history table</h2>";
$result = $conn->query("SHOW TABLES LIKE 'payment_history'");
if ($result->num_rows > 0) {
    echo "✅ payment_history table exists<br>";
    
    // Check columns
    $columns = $conn->query("SHOW COLUMNS FROM payment_history");
    echo "Columns in payment_history:<br>";
    while ($column = $columns->fetch_assoc()) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")<br>";
    }
} else {
    echo "❌ payment_history table does not exist<br>";
}

// Test 2: Check users table structure
echo "<h2>2. Checking users table structure</h2>";
$required_columns = ['selected_plan_id', 'role', 'full_name', 'qr_code', 'membership_start_date', 'membership_end_date', 'payment_status'];
$existing_columns = [];

$columns = $conn->query("SHOW COLUMNS FROM users");
while ($column = $columns->fetch_assoc()) {
    $existing_columns[] = $column['Field'];
}

foreach ($required_columns as $column) {
    if (in_array($column, $existing_columns)) {
        echo "✅ Column '$column' exists in users table<br>";
    } else {
        echo "❌ Column '$column' missing from users table<br>";
    }
}

// Test 3: Check for sample data
echo "<h2>3. Checking for sample data</h2>";

$payment_count = $conn->query("SELECT COUNT(*) as count FROM payment_history")->fetch_assoc()['count'];
echo "Payment records: $payment_count<br>";

$member_count = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'member'")->fetch_assoc()['count'];
echo "Member users: $member_count<br>";

$active_members = $conn->query("SELECT COUNT(*) as count FROM users WHERE membership_end_date > CURDATE()")->fetch_assoc()['count'];
echo "Active members: $active_members<br>";

// Test 4: Test reports query
echo "<h2>4. Testing reports query</h2>";
try {
    $test_query = "
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN ph.payment_status = 'Approved' THEN ph.amount ELSE 0 END) as total_revenue,
            COUNT(CASE WHEN ph.payment_status = 'Approved' THEN 1 END) as completed_payments
        FROM payment_history ph
        JOIN users u ON ph.user_id = u.id
        WHERE ph.payment_date BETWEEN '2025-01-01' AND '2025-12-31'
    ";
    
    $result = $conn->query($test_query);
    if ($result) {
        $data = $result->fetch_assoc();
        echo "✅ Reports query works<br>";
        echo "Total payments: " . $data['total_payments'] . "<br>";
        echo "Total revenue: ₱" . number_format($data['total_revenue'] ?? 0, 2) . "<br>";
        echo "Completed payments: " . $data['completed_payments'] . "<br>";
    } else {
        echo "❌ Reports query failed: " . $conn->error . "<br>";
    }
} catch (Exception $e) {
    echo "❌ Exception in reports query: " . $e->getMessage() . "<br>";
}

// Test 5: Check membership_plans table
echo "<h2>5. Checking membership_plans table</h2>";
$plans = $conn->query("SELECT * FROM membership_plans");
echo "Membership plans:<br>";
while ($plan = $plans->fetch_assoc()) {
    echo "- " . $plan['name'] . " (₱" . $plan['price'] . ")<br>";
}

echo "<h2>Test completed!</h2>";
echo "<p>If you see any ❌ marks above, run the fix_database_complete.php script to resolve them.</p>";
?> 