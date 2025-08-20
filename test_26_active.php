<?php
require 'db.php';

echo "<h2>Testing Active Members Count</h2>";

// Test the updated query
$active_result = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
      )
");

$count = $active_result ? $active_result->fetch_assoc()['count'] : 0;

echo "<p><strong>Active Members Count: $count</strong></p>";

if ($count == 26) {
    echo "<p style='color: green; font-size: 18px;'>✅ SUCCESS! Active members count is now 26!</p>";
} else {
    echo "<p style='color: red; font-size: 18px;'>❌ Count is $count, not 26</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 