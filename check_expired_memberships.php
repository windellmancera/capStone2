<?php
require 'db.php';

echo "=== EXPIRED MEMBERSHIPS CHECK ===\n\n";

// Check expired memberships using membership_end_date
$expired_query = "
    SELECT 
        u.id,
        u.username,
        u.email,
        u.membership_end_date,
        (
            SELECT payment_status FROM payment_history 
            WHERE user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_status,
        (
            SELECT payment_date FROM payment_history 
            WHERE user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_date
    FROM users u
    WHERE u.role = 'member' 
      AND u.membership_end_date IS NOT NULL 
      AND u.membership_end_date <= CURDATE()
    ORDER BY u.membership_end_date DESC
";

$expired_result = $conn->query($expired_query);

if ($expired_result && $expired_result->num_rows > 0) {
    echo "Found " . $expired_result->num_rows . " expired memberships:\n\n";
    
    while ($member = $expired_result->fetch_assoc()) {
        echo "ID: {$member['id']}\n";
        echo "Username: {$member['username']}\n";
        echo "Email: {$member['email']}\n";
        echo "Membership End Date: {$member['membership_end_date']}\n";
        echo "Latest Payment Status: " . ($member['latest_payment_status'] ? $member['latest_payment_status'] : 'None') . "\n";
        echo "Latest Payment Date: " . ($member['latest_payment_date'] ? $member['latest_payment_date'] : 'None') . "\n";
        echo "---\n";
    }
} else {
    echo "No expired memberships found based on membership_end_date.\n";
}

echo "\n=== ALTERNATIVE EXPIRED CHECK ===\n\n";

// Alternative check using payment status and calculated expiry
$alternative_query = "
    SELECT 
        u.id,
        u.username,
        u.email,
        u.membership_end_date,
        mp.duration as plan_duration,
        ph.payment_status,
        ph.payment_date,
        DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as calculated_expiry
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN (
        SELECT user_id, payment_status, payment_date
        FROM payment_history 
        WHERE payment_status = 'Approved'
        ORDER BY payment_date DESC
    ) ph ON ph.user_id = u.id
    WHERE u.role = 'member'
      AND ph.payment_status = 'Approved'
      AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) <= CURDATE()
    ORDER BY ph.payment_date DESC
";

$alternative_result = $conn->query($alternative_query);

if ($alternative_result && $alternative_result->num_rows > 0) {
    echo "Found " . $alternative_result->num_rows . " expired memberships (calculated from payment date):\n\n";
    
    while ($member = $alternative_result->fetch_assoc()) {
        echo "ID: {$member['id']}\n";
        echo "Username: {$member['username']}\n";
        echo "Email: {$member['email']}\n";
        echo "Plan Duration: {$member['plan_duration']} days\n";
        echo "Payment Date: {$member['payment_date']}\n";
        echo "Calculated Expiry: {$member['calculated_expiry']}\n";
        echo "---\n";
    }
} else {
    echo "No expired memberships found using payment date calculation.\n";
}

echo "\n=== SUMMARY ===\n";
$expired_count = $conn->query("
    SELECT COUNT(*) as count 
    FROM users 
    WHERE role = 'member' 
      AND membership_end_date IS NOT NULL 
      AND membership_end_date <= CURDATE()
")->fetch_assoc()['count'];

echo "Total expired memberships: $expired_count\n";
?> 