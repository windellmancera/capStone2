<?php
require 'db.php';

// Test the exact query from manage_members.php
$members = $conn->query("
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
        ) as latest_payment_date,
        (
            SELECT COUNT(*) FROM attendance WHERE user_id = u.id
        ) as attendance_count,
        (
            SELECT COUNT(*) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved'
        ) as payment_count
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role != 'admin'
    ORDER BY u.created_at DESC
    LIMIT 5
");

echo "Testing table generation:\n";
echo "=======================\n\n";

if ($members && $members->num_rows > 0) {
    echo "Found " . $members->num_rows . " members\n\n";
    
    while($member = $members->fetch_assoc()) {
        echo "Member: {$member['username']}\n";
        echo "  Email: {$member['email']}\n";
        echo "  Plan: " . ($member['plan_name'] ? $member['plan_name'] : 'No Plan') . "\n";
        echo "  Payment Status: " . ($member['latest_payment_status'] ? $member['latest_payment_status'] : 'None') . "\n";
        
        // Calculate status like in the main file
        $today = date('Y-m-d');
        $is_active = false;
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
        echo "  Status: $status\n";
        echo "  ---\n";
    }
} else {
    echo "No members found or error: " . $conn->error . "\n";
}
?> 