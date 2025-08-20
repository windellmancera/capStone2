<?php
require 'db.php';

echo "<h2>Debugging Active Members Count</h2>";

// Get all members with their details
$members_query = "
    SELECT 
        u.id,
        u.username,
        u.email,
        u.membership_end_date,
        u.membership_start_date,
        CASE WHEN u.membership_end_date > CURDATE() THEN 'Active' ELSE 'Expired' END as date_status,
        CASE WHEN EXISTS (
            SELECT 1 FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Approved'
        ) THEN 'Yes' ELSE 'No' END as has_approved_payment,
        (
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Approved'
        ) as approved_payments_count,
        (
            SELECT payment_date FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Approved'
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_approved_payment
    FROM users u
    WHERE u.role = 'member'
    ORDER BY u.id DESC
";

$result = $conn->query($members_query);

echo "<h3>All Members Analysis:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 12px;'>";
echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Date Status</th><th>Approved Payment</th><th>Payment Count</th><th>Latest Payment</th></tr>";

$active_by_date = 0;
$active_by_payment = 0;
$active_by_both = 0;
$total_members = 0;

while ($member = $result->fetch_assoc()) {
    $total_members++;
    $date_status_class = $member['date_status'] === 'Active' ? 'color: green;' : 'color: red;';
    $payment_class = $member['has_approved_payment'] === 'Yes' ? 'color: green;' : 'color: red;';
    
    echo "<tr>";
    echo "<td>{$member['id']}</td>";
    echo "<td>{$member['username']}</td>";
    echo "<td>" . ($member['membership_end_date'] ? $member['membership_end_date'] : 'NULL') . "</td>";
    echo "<td style='$date_status_class'>{$member['date_status']}</td>";
    echo "<td style='$payment_class'>{$member['has_approved_payment']}</td>";
    echo "<td>{$member['approved_payments_count']}</td>";
    echo "<td>" . ($member['latest_approved_payment'] ? $member['latest_approved_payment'] : 'N/A') . "</td>";
    echo "</tr>";
    
    if ($member['date_status'] === 'Active') {
        $active_by_date++;
    }
    if ($member['has_approved_payment'] === 'Yes') {
        $active_by_payment++;
    }
    if ($member['date_status'] === 'Active' && $member['has_approved_payment'] === 'Yes') {
        $active_by_both++;
    }
}
echo "</table>";

echo "<h3>Summary:</h3>";
echo "<p>Total Members: $total_members</p>";
echo "<p>Active by Date (end_date > CURDATE()): $active_by_date</p>";
echo "<p>Active by Payment (has approved payment): $active_by_payment</p>";
echo "<p>Active by Both (date + payment): $active_by_both</p>";

// Test different query variations
echo "<h3>Testing Different Query Variations:</h3>";

// Query 1: Only approved payments (original)
$query1 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id 
          AND ph.payment_status = 'Approved'
      )
";
$result1 = $conn->query($query1);
$count1 = $result1 ? $result1->fetch_assoc()['count'] : 0;
echo "<p>Query 1 (only approved payments): $count1</p>";

// Query 2: Only valid end date
$query2 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE())
";
$result2 = $conn->query($query2);
$count2 = $result2 ? $result2->fetch_assoc()['count'] : 0;
echo "<p>Query 2 (only valid end date): $count2</p>";

// Query 3: Both conditions (current)
$query3 = "
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
$result3 = $conn->query($query3);
$count3 = $result3 ? $result3->fetch_assoc()['count'] : 0;
echo "<p>Query 3 (both conditions): $count3</p>";

// Query 4: Approved payment OR valid end date
$query4 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND (
          EXISTS (
              SELECT 1 FROM payment_history ph 
              WHERE ph.user_id = u.id 
              AND ph.payment_status = 'Approved'
          )
          OR (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE())
      )
";
$result4 = $conn->query($query4);
$count4 = $result4 ? $result4->fetch_assoc()['count'] : 0;
echo "<p>Query 4 (approved payment OR valid end date): $count4</p>";

echo "<hr>";
echo "<p><strong>User expects: 26 active members</strong></p>";
echo "<p><strong>Current count: $count3</strong></p>";

if ($count4 == 26) {
    echo "<p style='color: green;'>✅ Query 4 matches user's expectation!</p>";
    echo "<p>This suggests active members should be those with approved payments OR valid end dates.</p>";
} elseif ($count1 == 26) {
    echo "<p style='color: green;'>✅ Query 1 matches user's expectation!</p>";
    echo "<p>This suggests active members should only be those with approved payments.</p>";
} elseif ($count2 == 26) {
    echo "<p style='color: green;'>✅ Query 2 matches user's expectation!</p>";
    echo "<p>This suggests active members should only be those with valid end dates.</p>";
} else {
    echo "<p style='color: red;'>❌ None of the queries match the expected count of 26.</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 