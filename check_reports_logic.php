<?php
require 'db.php';

echo "<h2>Checking Reports Logic</h2>";

// Check what the reports page counts as completed payments
$completed_payments_sql = "
    SELECT COUNT(CASE WHEN ph.payment_status = 'Approved' THEN 1 END) as completed_payments
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
";

$completed_result = $conn->query($completed_payments_sql);
$completed_count = $completed_result ? $completed_result->fetch_assoc()['completed_payments'] : 0;

echo "<p><strong>Completed Payments (Approved): $completed_count</strong></p>";

// Check what the reports page counts as active members
$active_members_sql = "
    SELECT COUNT(CASE WHEN EXISTS (SELECT 1 FROM payment_history ph WHERE ph.user_id = u.id AND ph.payment_status = 'Approved') THEN 1 END) as active_members
    FROM users u
    WHERE u.role = 'member'
";

$active_result = $conn->query($active_members_sql);
$active_count = $active_result ? $active_result->fetch_assoc()['active_members'] : 0;

echo "<p><strong>Active Members (with approved payments): $active_count</strong></p>";

// Check total payments
$total_payments_sql = "SELECT COUNT(*) as total_payments FROM payment_history ph";
$total_result = $conn->query($total_payments_sql);
$total_count = $total_result ? $total_result->fetch_assoc()['total_payments'] : 0;

echo "<p><strong>Total Payments: $total_count</strong></p>";

// Check payment status breakdown
$status_sql = "
    SELECT 
        payment_status,
        COUNT(*) as count
    FROM payment_history 
    GROUP BY payment_status
    ORDER BY payment_status
";

$status_result = $conn->query($status_sql);

echo "<h3>Payment Status Breakdown:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>Status</th><th>Count</th></tr>";

while ($row = $status_result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['payment_status']}</td>";
    echo "<td>{$row['count']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<p><strong>Reports shows: 26 completed payments</strong></p>";
echo "<p><strong>Our count: $completed_count completed payments</strong></p>";

if ($completed_count == 26) {
    echo "<p style='color: green;'>✅ Completed payments match!</p>";
} else {
    echo "<p style='color: red;'>❌ Completed payments don't match. Expected 26, got $completed_count</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/reports.php'>Go to Reports</a></p>";
?> 