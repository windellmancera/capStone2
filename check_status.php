<?php
require 'db.php';

echo "Checking member statuses:\n";
echo "=======================\n\n";

$result = $conn->query("
    SELECT 
        u.username,
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
        ) as latest_payment_date,
        mp.duration as plan_duration
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role != 'admin'
    LIMIT 10
");

if ($result && $result->num_rows > 0) {
    while ($member = $result->fetch_assoc()) {
        $today = date('Y-m-d');
        $is_active = false;
        
        // Calculate expiry date if payment is approved and plan is set
        $expiry = null;
        if ($member['latest_payment_status'] === 'Approved' && $member['latest_payment_date'] && $member['plan_duration']) {
            $expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
        }
        
        if ($member['latest_payment_status'] === 'Approved' && $expiry && $expiry >= $today) {
            $is_active = true;
        } elseif ($member['membership_end_date'] && $member['membership_end_date'] >= $today) {
            $is_active = true;
        }
        
        $status = $is_active ? 'active' : 'inactive';
        
        echo "Username: {$member['username']}\n";
        echo "  Payment Status: {$member['latest_payment_status']}\n";
        echo "  Payment Date: {$member['latest_payment_date']}\n";
        echo "  Plan Duration: {$member['plan_duration']}\n";
        echo "  Expiry: " . ($expiry ? $expiry : 'N/A') . "\n";
        echo "  Membership End: " . ($member['membership_end_date'] ? $member['membership_end_date'] : 'N/A') . "\n";
        echo "  Is Active: " . ($is_active ? 'true' : 'false') . "\n";
        echo "  Status: $status\n";
        echo "  Today: $today\n";
        echo "  ---\n";
    }
} else {
    echo "No members found.\n";
}
?> 