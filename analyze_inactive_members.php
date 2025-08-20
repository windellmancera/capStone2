<?php
require 'db.php';

echo "<h2>Analyzing Members with Payment History</h2>";
echo "<p>Current query counts ALL members with ANY payment history (result: 28)</p>";
echo "<p>User says this includes 'inactive' members. Let's identify them:</p>";

// Get all members with payment history and their details
$members_query = "
    SELECT 
        u.id,
        u.username,
        u.email,
        u.membership_end_date,
        u.membership_start_date,
        CASE 
            WHEN u.membership_end_date IS NULL THEN 'No End Date'
            WHEN u.membership_end_date > CURDATE() THEN 'Active'
            ELSE 'Expired'
        END as membership_status,
        (
            SELECT GROUP_CONCAT(ph.payment_status ORDER BY ph.payment_date DESC)
            FROM payment_history ph 
            WHERE ph.user_id = u.id
        ) as all_payment_statuses,
        (
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id
        ) as total_payments,
        (
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
        ) as approved_payments,
        (
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id AND ph.payment_status = 'Rejected'
        ) as rejected_payments,
        (
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id AND ph.payment_status = 'Pending'
        ) as pending_payments,
        (
            SELECT ph.payment_date 
            FROM payment_history ph 
            WHERE ph.user_id = u.id 
            ORDER BY ph.payment_date DESC 
            LIMIT 1
        ) as latest_payment_date,
        (
            SELECT ph.payment_status 
            FROM payment_history ph 
            WHERE ph.user_id = u.id 
            ORDER BY ph.payment_date DESC 
            LIMIT 1
        ) as latest_payment_status
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id
      )
    ORDER BY u.id DESC
";

$result = $conn->query($members_query);

echo "<h3>All Members with Payment History (28 total):</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
echo "<tr>
        <th>ID</th>
        <th>Username</th>
        <th>End Date</th>
        <th>Membership Status</th>
        <th>All Payment Statuses</th>
        <th>Total Payments</th>
        <th>Approved</th>
        <th>Rejected</th>
        <th>Pending</th>
        <th>Latest Payment</th>
        <th>Latest Status</th>
        <th>Potential Issue</th>
      </tr>";

$potentially_inactive = [];

while ($member = $result->fetch_assoc()) {
    $issue = "";
    $row_class = "";
    
    // Identify potential issues
    if ($member['membership_status'] === 'Expired') {
        $issue .= "Expired membership; ";
        $row_class = "background-color: #ffebee;";
    }
    
    if ($member['approved_payments'] == 0) {
        $issue .= "No approved payments; ";
        $row_class = "background-color: #fff3e0;";
    }
    
    if ($member['rejected_payments'] > 0) {
        $issue .= "Has rejected payments; ";
        $row_class = "background-color: #ffebee;";
    }
    
    if ($member['latest_payment_status'] === 'Rejected') {
        $issue .= "Latest payment rejected; ";
        $row_class = "background-color: #ffebee;";
    }
    
    if ($member['total_payments'] == 1 && $member['latest_payment_status'] === 'Pending') {
        $issue .= "Only pending payment; ";
        $row_class = "background-color: #fff3e0;";
    }
    
    if ($issue) {
        $potentially_inactive[] = $member;
    }
    
    echo "<tr style='$row_class'>";
    echo "<td>{$member['id']}</td>";
    echo "<td>{$member['username']}</td>";
    echo "<td>" . ($member['membership_end_date'] ? $member['membership_end_date'] : 'NULL') . "</td>";
    echo "<td>{$member['membership_status']}</td>";
    echo "<td>{$member['all_payment_statuses']}</td>";
    echo "<td>{$member['total_payments']}</td>";
    echo "<td>{$member['approved_payments']}</td>";
    echo "<td>{$member['rejected_payments']}</td>";
    echo "<td>{$member['pending_payments']}</td>";
    echo "<td>" . ($member['latest_payment_date'] ? date('M d, Y', strtotime($member['latest_payment_date'])) : 'N/A') . "</td>";
    echo "<td>{$member['latest_payment_status']}</td>";
    echo "<td style='color: red;'>$issue</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Potentially Inactive Members (" . count($potentially_inactive) . "):</h3>";
echo "<p>These members might be considered 'inactive' by the user:</p>";

if (count($potentially_inactive) > 0) {
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
    echo "<tr>
            <th>ID</th>
            <th>Username</th>
            <th>End Date</th>
            <th>Membership Status</th>
            <th>Latest Payment Status</th>
            <th>Approved Payments</th>
            <th>Issue</th>
          </tr>";
    
    foreach ($potentially_inactive as $member) {
        $issue = "";
        if ($member['membership_status'] === 'Expired') $issue .= "Expired membership; ";
        if ($member['approved_payments'] == 0) $issue .= "No approved payments; ";
        if ($member['latest_payment_status'] === 'Rejected') $issue .= "Latest payment rejected; ";
        if ($member['total_payments'] == 1 && $member['latest_payment_status'] === 'Pending') $issue .= "Only pending payment; ";
        
        echo "<tr>";
        echo "<td>{$member['id']}</td>";
        echo "<td>{$member['username']}</td>";
        echo "<td>" . ($member['membership_end_date'] ? $member['membership_end_date'] : 'NULL') . "</td>";
        echo "<td>{$member['membership_status']}</td>";
        echo "<td>{$member['latest_payment_status']}</td>";
        echo "<td>{$member['approved_payments']}</td>";
        echo "<td style='color: red;'>$issue</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Test different exclusion criteria
echo "<h3>Testing Different Exclusion Criteria:</h3>";

// Exclude expired memberships
$query1 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id
      )
      AND (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE())
";
$result1 = $conn->query($query1);
$count1 = $result1 ? $result1->fetch_assoc()['count'] : 0;
echo "<p>Excluding expired memberships: $count1</p>";

// Exclude members with no approved payments
$query2 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
      )
";
$result2 = $conn->query($query2);
$count2 = $result2 ? $result2->fetch_assoc()['count'] : 0;
echo "<p>Only members with approved payments: $count2</p>";

// Exclude expired AND require approved payments
$query3 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
      )
      AND (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE())
";
$result3 = $conn->query($query3);
$count3 = $result3 ? $result3->fetch_assoc()['count'] : 0;
echo "<p>Approved payments + valid end date: $count3</p>";

// Exclude members with only rejected payments
$query4 = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id
      )
      AND NOT EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND ph.payment_status = 'Rejected'
          AND NOT EXISTS (
              SELECT 1 FROM payment_history ph2 
              WHERE ph2.user_id = u.id AND ph2.payment_status != 'Rejected'
          )
      )
";
$result4 = $conn->query($query4);
$count4 = $result4 ? $result4->fetch_assoc()['count'] : 0;
echo "<p>Excluding members with only rejected payments: $count4</p>";

echo "<hr>";
echo "<p><strong>User expects: 26 active members</strong></p>";
echo "<p><strong>Current count: 28</strong></p>";

if ($count1 == 26) {
    echo "<p style='color: green;'>✅ Excluding expired memberships gives 26!</p>";
} elseif ($count2 == 26) {
    echo "<p style='color: green;'>✅ Only approved payments gives 26!</p>";
} elseif ($count3 == 26) {
    echo "<p style='color: green;'>✅ Approved payments + valid end date gives 26!</p>";
} elseif ($count4 == 26) {
    echo "<p style='color: green;'>✅ Excluding only-rejected payments gives 26!</p>";
} else {
    echo "<p style='color: red;'>❌ None of these criteria give exactly 26.</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 