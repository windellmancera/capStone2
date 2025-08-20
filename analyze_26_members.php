<?php
require 'db.php';

echo "<h2>Finding the Correct 26 Active Members</h2>";

// Get all members with detailed analysis
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
        ) as latest_approved_payment,
        (
            SELECT payment_status FROM payment_history ph 
            WHERE ph.user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_status
    FROM users u
    WHERE u.role = 'member'
    ORDER BY u.id DESC
";

$result = $conn->query($members_query);

echo "<h3>Detailed Member Analysis:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Date Status</th><th>Approved Payment</th><th>Payment Count</th><th>Latest Payment</th><th>Latest Status</th></tr>";

$members = [];
$active_candidates = [];

while ($member = $result->fetch_assoc()) {
    $members[] = $member;
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
    echo "<td>{$member['latest_payment_status']}</td>";
    echo "</tr>";
    
    // Consider different criteria for active membership
    if ($member['has_approved_payment'] === 'Yes') {
        $active_candidates[] = $member;
    }
}

echo "</table>";

echo "<h3>Active Candidates (by approved payment):</h3>";
echo "<p>Total with approved payments: " . count($active_candidates) . "</p>";

// Show the 24 members with approved payments
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Latest Payment</th><th>Latest Status</th></tr>";

foreach ($active_candidates as $member) {
    $end_date_display = $member['membership_end_date'] ? $member['membership_end_date'] : 'NULL';
    echo "<tr>";
    echo "<td>{$member['id']}</td>";
    echo "<td>{$member['username']}</td>";
    echo "<td>$end_date_display</td>";
    echo "<td>{$member['latest_approved_payment']}</td>";
    echo "<td>{$member['latest_payment_status']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check if there are members with pending payments that should be considered active
echo "<h3>Members with Pending Payments:</h3>";
$pending_query = "
    SELECT 
        u.id,
        u.username,
        u.membership_end_date,
        ph.payment_status,
        ph.payment_date
    FROM users u
    JOIN payment_history ph ON u.id = ph.user_id
    WHERE u.role = 'member'
      AND ph.payment_status = 'Pending'
      AND ph.payment_date = (
          SELECT MAX(payment_date) 
          FROM payment_history ph2 
          WHERE ph2.user_id = u.id
      )
    ORDER BY u.id DESC
";

$pending_result = $conn->query($pending_query);
$pending_members = [];

if ($pending_result && $pending_result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
    echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Payment Status</th><th>Payment Date</th></tr>";
    
    while ($pending = $pending_result->fetch_assoc()) {
        $pending_members[] = $pending;
        echo "<tr>";
        echo "<td>{$pending['id']}</td>";
        echo "<td>{$pending['username']}</td>";
        echo "<td>" . ($pending['membership_end_date'] ? $pending['membership_end_date'] : 'NULL') . "</td>";
        echo "<td>{$pending['payment_status']}</td>";
        echo "<td>{$pending['payment_date']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No members with pending payments found.</p>";
}

// Check if including pending payments gives us 26
$total_with_pending = count($active_candidates) + count($pending_members);
echo "<h3>Summary:</h3>";
echo "<p>Members with approved payments: " . count($active_candidates) . "</p>";
echo "<p>Members with pending payments: " . count($pending_members) . "</p>";
echo "<p>Total with approved OR pending: $total_with_pending</p>";

if ($total_with_pending == 26) {
    echo "<p style='color: green;'>✅ Found the 26 members! (Approved + Pending payments)</p>";
} else {
    echo "<p style='color: red;'>❌ Still not 26. Let me check other criteria...</p>";
    
    // Check if there are members with recent payments (within last 30 days)
    echo "<h3>Members with Recent Payments (Last 30 Days):</h3>";
    $recent_query = "
        SELECT 
            u.id,
            u.username,
            u.membership_end_date,
            ph.payment_status,
            ph.payment_date
        FROM users u
        JOIN payment_history ph ON u.id = ph.user_id
        WHERE u.role = 'member'
          AND ph.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY ph.payment_date DESC
    ";
    
    $recent_result = $conn->query($recent_query);
    $recent_members = [];
    
    if ($recent_result && $recent_result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; font-size: 11px;'>";
        echo "<tr><th>ID</th><th>Username</th><th>End Date</th><th>Payment Status</th><th>Payment Date</th></tr>";
        
        while ($recent = $recent_result->fetch_assoc()) {
            $recent_members[] = $recent;
            echo "<tr>";
            echo "<td>{$recent['id']}</td>";
            echo "<td>{$recent['username']}</td>";
            echo "<td>" . ($recent['membership_end_date'] ? $recent['membership_end_date'] : 'NULL') . "</td>";
            echo "<td>{$recent['payment_status']}</td>";
            echo "<td>{$recent['payment_date']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        $unique_recent = array_unique(array_column($recent_members, 'id'));
        echo "<p>Unique members with recent payments: " . count($unique_recent) . "</p>";
    }
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 