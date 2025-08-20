<?php
require 'db.php';

echo "Checking windellcute member data:\n";
echo "===============================\n\n";

$member = $conn->query("
    SELECT 
        u.*, 
        mp.name as plan_name,
        mp.duration as plan_duration,
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
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.username = 'windellcute'
")->fetch_assoc();

if ($member) {
    echo "Member ID: " . $member['id'] . "\n";
    echo "Username: " . $member['username'] . "\n";
    echo "Email: " . $member['email'] . "\n";
    echo "Created At: " . $member['created_at'] . "\n";
    echo "Membership Start Date: " . ($member['membership_start_date'] ?? 'NULL') . "\n";
    echo "Membership End Date: " . ($member['membership_end_date'] ?? 'NULL') . "\n";
    echo "Selected Plan ID: " . ($member['selected_plan_id'] ?? 'NULL') . "\n";
    echo "Plan Name: " . ($member['plan_name'] ?? 'NULL') . "\n";
    echo "Plan Duration: " . ($member['plan_duration'] ?? 'NULL') . " days\n";
    echo "Latest Payment Status: " . ($member['latest_payment_status'] ?? 'NULL') . "\n";
    echo "Latest Payment Date: " . ($member['latest_payment_date'] ?? 'NULL') . "\n";
    
    // Calculate what the expiry should be
    if ($member['membership_start_date'] && $member['plan_duration']) {
        $calculated_expiry = date('Y-m-d', strtotime($member['membership_start_date'] . ' + ' . $member['plan_duration'] . ' days'));
        echo "\nCalculated expiry from start date: " . $calculated_expiry . "\n";
    }
    
    if ($member['latest_payment_date'] && $member['plan_duration']) {
        $calculated_expiry_from_payment = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
        echo "Calculated expiry from payment date: " . $calculated_expiry_from_payment . "\n";
    }
} else {
    echo "Member not found.\n";
}
?> 