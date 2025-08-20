<?php
require 'db.php';

echo "<h2>Simple Count Test</h2>";

// Test the query that includes all members with any payment history
$active_query = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id
      )
";

$result = $conn->query($active_query);
$active_count = $result ? $result->fetch_assoc()['count'] : 0;

echo "<h3>Active Members Count (Any Payment History): $active_count</h3>";

if ($active_count == 26) {
    echo "<p style='color: green;'>✅ Perfect! The count matches the user's expectation of 26 active members!</p>";
} else {
    echo "<p style='color: red;'>❌ Still not 26. Current count: $active_count</p>";
}

// Also test the old query for comparison
$old_query = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id 
          AND ph.payment_status = 'Approved'
      )
";

$old_result = $conn->query($old_query);
$old_count = $old_result ? $old_result->fetch_assoc()['count'] : 0;

echo "<p>Old query count (approved only): $old_count</p>";

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 