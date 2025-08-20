<?php
require 'db.php';

echo "<h2>Testing Active Members Count (Fixed)</h2>";

// Test the updated query
$active_query = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id 
          AND ph.payment_status = 'Approved'
      )
      AND (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE())
";

$result = $conn->query($active_query);
$active_count = $result ? $result->fetch_assoc()['count'] : 0;

echo "<h3>Active Members Count: $active_count</h3>";

// Show detailed breakdown
echo "<h3>Detailed Breakdown:</h3>";

$detailed_query = "
    SELECT u.id, u.username, u.membership_end_date, 
           CASE WHEN u.membership_end_date > CURDATE() THEN 'Active' ELSE 'Expired' END as status,
           CASE WHEN EXISTS (
               SELECT 1 FROM payment_history ph 
               WHERE ph.user_id = u.id 
               AND ph.payment_status = 'Approved'
           ) THEN 'Yes' ELSE 'No' END as has_approved_payment
    FROM users u
    WHERE u.role = 'member'
    ORDER BY u.id DESC
";

$detailed_result = $conn->query($detailed_query);

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Status</th><th>Approved Payment</th></tr>";

$active_members = 0;
while ($member = $detailed_result->fetch_assoc()) {
    $status_class = $member['status'] === 'Active' ? 'color: green;' : 'color: red;';
    $payment_class = $member['has_approved_payment'] === 'Yes' ? 'color: green;' : 'color: red;';
    
    echo "<tr>";
    echo "<td>{$member['id']}</td>";
    echo "<td>{$member['username']}</td>";
    echo "<td>{$member['membership_end_date']}</td>";
    echo "<td style='$status_class'>{$member['status']}</td>";
    echo "<td style='$payment_class'>{$member['has_approved_payment']}</td>";
    echo "</tr>";
    
    if ($member['status'] === 'Active' && $member['has_approved_payment'] === 'Yes') {
        $active_members++;
    }
}
echo "</table>";

echo "<p><strong>Total Active Members (calculated): $active_members</strong></p>";
echo "<p><strong>Active Members (from query): $active_count</strong></p>";

if ($active_members === $active_count) {
    echo "<p style='color: green;'>✅ Active members count is working correctly!</p>";
} else {
    echo "<p style='color: red;'>❌ There's a discrepancy in the count.</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 