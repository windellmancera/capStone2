<?php
require 'db.php';

echo "<h2>Testing Reports Data</h2>";

// Test 1: Check payment_history table
echo "<h3>1. Payment History Data:</h3>";
$test_sql = "SELECT COUNT(*) as total_payments FROM payment_history";
$result = $conn->query($test_sql);
$row = $result->fetch_assoc();
echo "Total payments: " . $row['total_payments'] . "<br>";

if ($row['total_payments'] > 0) {
    $sample_sql = "SELECT * FROM payment_history LIMIT 5";
    $sample_result = $conn->query($sample_sql);
    echo "<h4>Sample payments:</h4>";
    while ($payment = $sample_result->fetch_assoc()) {
        echo "ID: " . $payment['id'] . ", Amount: " . $payment['amount'] . ", Date: " . $payment['payment_date'] . ", Status: " . $payment['payment_status'] . "<br>";
    }
} else {
    echo "No payment data found!<br>";
}

// Test 2: Check users table
echo "<h3>2. Users Data:</h3>";
$users_sql = "SELECT COUNT(*) as total_users FROM users WHERE role = 'member'";
$users_result = $conn->query($users_sql);
$users_row = $users_result->fetch_assoc();
echo "Total members: " . $users_row['total_users'] . "<br>";

// Test 3: Check membership_plans table
echo "<h3>3. Membership Plans Data:</h3>";
$plans_sql = "SELECT COUNT(*) as total_plans FROM membership_plans";
$plans_result = $conn->query($plans_sql);
$plans_row = $plans_result->fetch_assoc();
echo "Total plans: " . $plans_row['total_plans'] . "<br>";

if ($plans_row['total_plans'] > 0) {
    $sample_plans_sql = "SELECT * FROM membership_plans";
    $sample_plans_result = $conn->query($sample_plans_sql);
    echo "<h4>Available plans:</h4>";
    while ($plan = $sample_plans_result->fetch_assoc()) {
        echo "ID: " . $plan['id'] . ", Name: " . $plan['name'] . ", Price: " . $plan['price'] . "<br>";
    }
}

// Test 4: Check daily revenue data
echo "<h3>4. Daily Revenue Data:</h3>";
$date_from = '2024-01-01'; // Default to start of year to show all data
$date_to = date('Y-m-d'); // Today
$daily_sql = "
    SELECT 
        DATE(payment_date) as date,
        SUM(amount) as daily_revenue,
        COUNT(*) as payment_count
    FROM payment_history 
    WHERE payment_status = 'Approved' AND payment_date BETWEEN ? AND ?
    GROUP BY DATE(payment_date)
    ORDER BY date ASC
";
$daily_stmt = $conn->prepare($daily_sql);
$date_to_with_time = $date_to . ' 23:59:59';
$daily_stmt->bind_param("ss", $date_from, $date_to_with_time);
$daily_stmt->execute();
$daily_result = $daily_stmt->get_result();

echo "Date range: $date_from to $date_to<br>";
echo "Daily revenue records: " . $daily_result->num_rows . "<br>";

if ($daily_result->num_rows > 0) {
    echo "<h4>Daily revenue data:</h4>";
    while ($row = $daily_result->fetch_assoc()) {
        echo "Date: " . $row['date'] . ", Revenue: ₱" . $row['daily_revenue'] . ", Count: " . $row['payment_count'] . "<br>";
    }
} else {
    echo "No daily revenue data found!<br>";
}

// Test 5: Check payment method data
echo "<h3>5. Payment Method Data:</h3>";
$method_sql = "
    SELECT 
        payment_method,
        COUNT(*) as payment_count,
        SUM(amount) as total_amount
    FROM payment_history 
    WHERE payment_date BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total_amount DESC
";
$method_stmt = $conn->prepare($method_sql);
$method_stmt->bind_param("ss", $date_from, $date_to_with_time);
$method_stmt->execute();
$method_result = $method_stmt->get_result();

echo "Payment method records: " . $method_result->num_rows . "<br>";

if ($method_result->num_rows > 0) {
    echo "<h4>Payment method data:</h4>";
    while ($row = $method_result->fetch_assoc()) {
        echo "Method: " . $row['payment_method'] . ", Count: " . $row['payment_count'] . ", Total: ₱" . $row['total_amount'] . "<br>";
    }
} else {
    echo "No payment method data found!<br>";
}

// Test 6: Check membership plan revenue data
echo "<h3>6. Membership Plan Revenue Data:</h3>";
$plan_revenue_sql = "
    SELECT 
        COALESCE(mp.name, 'No Plan') as plan_name,
        COUNT(*) as payment_count,
        SUM(ph.amount) as total_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE ph.payment_date BETWEEN ? AND ?
    GROUP BY mp.id, mp.name
    ORDER BY total_amount DESC
";
$plan_revenue_stmt = $conn->prepare($plan_revenue_sql);
$plan_revenue_stmt->bind_param("ss", $date_from, $date_to_with_time);
$plan_revenue_stmt->execute();
$plan_revenue_result = $plan_revenue_stmt->get_result();

echo "Membership plan revenue records: " . $plan_revenue_result->num_rows . "<br>";

if ($plan_revenue_result->num_rows > 0) {
    echo "<h4>Membership plan revenue data:</h4>";
    while ($row = $plan_revenue_result->fetch_assoc()) {
        echo "Plan: " . $row['plan_name'] . ", Count: " . $row['payment_count'] . ", Total: ₱" . $row['total_amount'] . "<br>";
    }
} else {
    echo "No membership plan revenue data found!<br>";
}

echo "<h3>Summary:</h3>";
echo "The reports page will show data if there are approved payments in the payment_history table within the current month's date range.<br>";
echo "If no data is showing, it means either:<br>";
echo "1. No payments exist in the database<br>";
echo "2. No payments are marked as 'Approved'<br>";
echo "3. No payments exist within the current month's date range<br>";
?> 