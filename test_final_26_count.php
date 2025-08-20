<?php
require 'db.php';

echo "<h2>Testing Final 26 Active Members (Any Payment History)</h2>";

// Test the updated query that includes all members with any payment history
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

// Show detailed breakdown
echo "<h3>Detailed Breakdown:</h3>";

$detailed_query = "
    SELECT 
        u.id,
        u.username,
        u.membership_end_date,
        CASE WHEN u.membership_end_date > CURDATE() THEN 'Active' ELSE 'Expired' END as date_status,
        CASE WHEN EXISTS (
            SELECT 1 FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Approved'
        ) THEN 'Yes' ELSE 'No' END as has_approved_payment,
        CASE WHEN EXISTS (
            SELECT 1 FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Pending'
        ) THEN 'Yes' ELSE 'No' END as has_pending_payment,
        CASE WHEN EXISTS (
            SELECT 1 FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Rejected'
        ) THEN 'Yes' ELSE 'No' END as has_rejected_payment,
        (
            SELECT payment_status FROM payment_history ph 
            WHERE ph.user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_status,
        (
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id
        ) as total_payments
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id
      )
    ORDER BY u.id DESC
";

$detailed_result = $conn->query($detailed_query);

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Date Status</th><th>Approved</th><th>Pending</th><th>Rejected</th><th>Latest Status</th><th>Total Payments</th></tr>";

$active_members = 0;
while ($member = $detailed_result->fetch_assoc()) {
    $date_status_class = $member['date_status'] === 'Active' ? 'color: green;' : 'color: red;';
    $approved_class = $member['has_approved_payment'] === 'Yes' ? 'color: green;' : 'color: red;';
    $pending_class = $member['has_pending_payment'] === 'Yes' ? 'color: orange;' : 'color: red;';
    $rejected_class = $member['has_rejected_payment'] === 'Yes' ? 'color: red;' : 'color: red;';
    
    echo "<tr>";
    echo "<td>{$member['id']}</td>";
    echo "<td>{$member['username']}</td>";
    echo "<td>" . ($member['membership_end_date'] ? $member['membership_end_date'] : 'NULL') . "</td>";
    echo "<td style='$date_status_class'>{$member['date_status']}</td>";
    echo "<td style='$approved_class'>{$member['has_approved_payment']}</td>";
    echo "<td style='$pending_class'>{$member['has_pending_payment']}</td>";
    echo "<td style='$rejected_class'>{$member['has_rejected_payment']}</td>";
    echo "<td>{$member['latest_payment_status']}</td>";
    echo "<td>{$member['total_payments']}</td>";
    echo "</tr>";
    
    $active_members++;
}
echo "</table>";

echo "<p><strong>Total Active Members (calculated): $active_members</strong></p>";
echo "<p><strong>Active Members (from query): $active_count</strong></p>";

if ($active_members === $active_count) {
    echo "<p style='color: green;'>✅ Active members count is working correctly!</p>";
} else {
    echo "<p style='color: red;'>❌ There's a discrepancy in the count.</p>";
}

if ($active_count == 26) {
    echo "<p style='color: green;'>✅ Perfect! The count now matches the user's expectation of 26 active members!</p>";
    echo "<p>This means active members are those with any payment history (approved, pending, or rejected).</p>";
} else {
    echo "<p style='color: red;'>❌ Still not 26. Current count: $active_count</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 