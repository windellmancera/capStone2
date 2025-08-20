<?php
require 'db.php';

echo "<h2>Finding the Exact 26 Active Members</h2>";

// Get all members with all possible payment statuses
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
            SELECT COUNT(*) FROM payment_history ph 
            WHERE ph.user_id = u.id
        ) as total_payments,
        (
            SELECT payment_status FROM payment_history ph 
            WHERE ph.user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_status,
        (
            SELECT payment_date FROM payment_history ph 
            WHERE ph.user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_date
    FROM users u
    WHERE u.role = 'member'
    ORDER BY u.id DESC
";

$result = $conn->query($members_query);

echo "<h3>All Members with Payment Analysis:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 10px;'>";
echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Date Status</th><th>Approved</th><th>Pending</th><th>Rejected</th><th>Total Payments</th><th>Latest Status</th><th>Latest Date</th></tr>";

$members = [];
$approved_only = [];
$pending_only = [];
$rejected_only = [];
$no_payments = [];

while ($member = $result->fetch_assoc()) {
    $members[] = $member;
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
    echo "<td>{$member['total_payments']}</td>";
    echo "<td>{$member['latest_payment_status']}</td>";
    echo "<td>" . ($member['latest_payment_date'] ? $member['latest_payment_date'] : 'N/A') . "</td>";
    echo "</tr>";
    
    // Categorize members
    if ($member['has_approved_payment'] === 'Yes') {
        $approved_only[] = $member;
    } elseif ($member['has_pending_payment'] === 'Yes') {
        $pending_only[] = $member;
    } elseif ($member['has_rejected_payment'] === 'Yes') {
        $rejected_only[] = $member;
    } else {
        $no_payments[] = $member;
    }
}

echo "</table>";

echo "<h3>Summary:</h3>";
echo "<p>Total Members: " . count($members) . "</p>";
echo "<p>Members with Approved payments: " . count($approved_only) . "</p>";
echo "<p>Members with Pending payments: " . count($pending_only) . "</p>";
echo "<p>Members with Rejected payments: " . count($rejected_only) . "</p>";
echo "<p>Members with No payments: " . count($no_payments) . "</p>";

// Test different combinations
echo "<h3>Testing Different Combinations:</h3>";

$combinations = [
    "Approved only" => count($approved_only),
    "Approved + Pending" => count($approved_only) + count($pending_only),
    "Approved + Pending + Rejected" => count($approved_only) + count($pending_only) + count($rejected_only),
    "All with any payment" => count($approved_only) + count($pending_only) + count($rejected_only),
    "Approved + Rejected" => count($approved_only) + count($rejected_only),
    "Pending + Rejected" => count($pending_only) + count($rejected_only)
];

foreach ($combinations as $description => $count) {
    $status = ($count == 26) ? "✅ MATCH!" : "";
    echo "<p>$description: $count $status</p>";
}

// Show members with rejected payments
if (!empty($rejected_only)) {
    echo "<h3>Members with Rejected Payments:</h3>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
    echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Latest Payment Date</th></tr>";
    
    foreach ($rejected_only as $member) {
        echo "<tr>";
        echo "<td>{$member['id']}</td>";
        echo "<td>{$member['username']}</td>";
        echo "<td>" . ($member['membership_end_date'] ? $member['membership_end_date'] : 'NULL') . "</td>";
        echo "<td>{$member['latest_payment_date']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 