<?php
require_once 'db.php';

echo "<h2>Payment History Date Check</h2>";

// Get recent payment transactions
$result = $conn->query("
    SELECT 
        ph.id,
        ph.payment_date,
        ph.amount,
        ph.payment_method,
        ph.payment_status,
        u.username,
        u.email,
        mp.name as plan_name
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE ph.payment_status = 'Approved'
    ORDER BY ph.payment_date DESC
    LIMIT 10
");

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>ID</th><th>Payment Date</th><th>Amount</th><th>Method</th><th>Status</th><th>Username</th><th>Plan</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['id'] . "</td>";
    echo "<td>" . $row['payment_date'] . "</td>";
    echo "<td>₱" . number_format($row['amount'], 2) . "</td>";
    echo "<td>" . $row['payment_method'] . "</td>";
    echo "<td>" . $row['payment_status'] . "</td>";
    echo "<td>" . $row['username'] . "</td>";
    echo "<td>" . $row['plan_name'] . "</td>";
    echo "</tr>";
}

echo "</table>";

$conn->close();
?> 