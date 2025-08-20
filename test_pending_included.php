<?php
require 'db.php';

echo "<h2>Testing Different Active Member Criteria</h2>";

// Test 1: Only approved payments
$query1 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
      )
";
$result1 = $conn->query($query1);
$count1 = $result1 ? $result1->fetch_assoc()['count'] : 0;
echo "<p><strong>Only Approved Payments:</strong> $count1</p>";

// Test 2: Approved OR Pending payments
$query2 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND (ph.payment_status = 'Approved' OR ph.payment_status = 'Pending')
      )
";
$result2 = $conn->query($query2);
$count2 = $result2 ? $result2->fetch_assoc()['count'] : 0;
echo "<p><strong>Approved OR Pending Payments:</strong> $count2</p>";

// Test 3: Any payment history (current)
$query3 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id
      )
";
$result3 = $conn->query($query3);
$count3 = $result3 ? $result3->fetch_assoc()['count'] : 0;
echo "<p><strong>Any Payment History:</strong> $count3</p>";

echo "<hr>";
echo "<p><strong>User wants: 26 active members</strong></p>";

if ($count1 == 26) {
    echo "<p style='color: green;'>✅ Only approved payments gives 26!</p>";
} elseif ($count2 == 26) {
    echo "<p style='color: green;'>✅ Approved OR Pending payments gives 26!</p>";
    echo "<p>This makes sense for a gym - members with pending payments are still active.</p>";
} elseif ($count3 == 26) {
    echo "<p style='color: green;'>✅ Any payment history gives 26!</p>";
} else {
    echo "<p style='color: red;'>❌ None of these give exactly 26.</p>";
    echo "<p>Closest: Approved OR Pending = $count2</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
?> 