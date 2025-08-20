<?php
require 'db.php';

echo "<h2>Fixing Active Members Count</h2>";

// Current active members query (only checks approved payments)
echo "<h3>Current Active Members Query:</h3>";
$current_query = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id 
          AND ph.payment_status = 'Approved'
      )
";

$current_result = $conn->query($current_query);
$current_count = $current_result ? $current_result->fetch_assoc()['count'] : 0;
echo "Current Active Members: $current_count<br>";

// Show current membership end dates
echo "<h3>Current Member End Dates:</h3>";
$members_sql = "
    SELECT u.id, u.username, u.membership_end_date, 
           CASE WHEN u.membership_end_date > CURDATE() THEN 'Active' ELSE 'Expired' END as status
    FROM users u
    WHERE u.role = 'member'
    ORDER BY u.membership_end_date DESC
";
$members_result = $conn->query($members_sql);

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>ID</th><th>Username</th><th>Membership End Date</th><th>Status</th></tr>";

$active_count = 0;
$expired_count = 0;
while ($member = $members_result->fetch_assoc()) {
    $status_class = $member['status'] === 'Active' ? 'color: green;' : 'color: red;';
    echo "<tr>";
    echo "<td>{$member['id']}</td>";
    echo "<td>{$member['username']}</td>";
    echo "<td>{$member['membership_end_date']}</td>";
    echo "<td style='$status_class'>{$member['status']}</td>";
    echo "</tr>";
    
    if ($member['status'] === 'Active') {
        $active_count++;
    } else {
        $expired_count++;
    }
}
echo "</table>";

echo "<p><strong>Active Members (by end date): $active_count</strong></p>";
echo "<p><strong>Expired Members: $expired_count</strong></p>";

// Fix the dashboard query to include membership end date check
echo "<h3>Updating Dashboard Active Members Query...</h3>";

// The correct query should check both approved payments AND valid membership end date
$correct_query = "
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

$correct_result = $conn->query($correct_query);
$correct_count = $correct_result ? $correct_result->fetch_assoc()['count'] : 0;
echo "Correct Active Members Count: $correct_count<br>";

echo "<hr>";
echo "<h3>Summary:</h3>";
echo "<p>Current query only checks approved payments: <strong>$current_count</strong></p>";
echo "<p>Members with valid end dates: <strong>$active_count</strong></p>";
echo "<p>Correct count (approved payments + valid end date): <strong>$correct_count</strong></p>";

echo "<hr>";
echo "<p><strong>✅ The active members count should be updated to show: $correct_count</strong></p>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 