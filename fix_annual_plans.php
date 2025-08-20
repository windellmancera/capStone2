<?php
require 'db.php';

echo "=== FIXING ANNUAL PLAN DATES ===\n\n";

// Set annual plan members to 2026 expiry dates
echo "Setting annual plan members to 2026...\n";
$annual_plans_query = "
    UPDATE users
    SET membership_end_date = '2026-08-29'
    WHERE role = 'member'
      AND username IN ('micolyy', 'carlandreww', 'gavinnn', 'jesicahaha', 'owapawi', 'melitbautista')
";

$annual_result = $conn->query($annual_plans_query);
if ($annual_result) {
    echo "Successfully set annual plan members to 2026\n";
} else {
    echo "Error setting annual plans: " . $conn->error . "\n";
}

// Keep monthly plan members at 2025
echo "\nKeeping monthly plan members at 2025...\n";
$monthly_plans_query = "
    UPDATE users
    SET membership_end_date = '2025-08-29'
    WHERE role = 'member'
      AND username IN ('haroldabiera', 'graceabiera', 'france', 'iannpogi', 
                       'ashleyyyyyy', 'yuriifullante', 'victortanafranca', 
                       'khaiibaldos', 'shane', 'ajhaypogi', 'maffycute', 
                       'paul', 'angelangit', 'leoknor', 'shanecute', 
                       'ezekielpogi', 'xandeecute', 'laulau', 'andrew', 
                       'genwilson', 'rizzaaa')
";

$monthly_result = $conn->query($monthly_plans_query);
if ($monthly_result) {
    echo "Successfully set monthly plan members to 2025\n";
} else {
    echo "Error setting monthly plans: " . $conn->error . "\n";
}

// Keep charles as the only expired member
echo "\nSetting charles as the only expired member...\n";
$charles_expired_query = "
    UPDATE users
    SET membership_end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    WHERE role = 'member'
      AND username = 'charles'
";

$charles_result = $conn->query($charles_expired_query);
if ($charles_result) {
    echo "Successfully set charles as expired\n";
} else {
    echo "Error setting charles as expired: " . $conn->error . "\n";
}

// Final check
echo "\n=== FINAL CHECK ===\n";
$final_check = "
    SELECT COUNT(*) as count
    FROM users
    WHERE role = 'member'
      AND membership_end_date IS NOT NULL
      AND membership_end_date <= CURDATE()
";

$final_result = $conn->query($final_check);
$final_count = $final_result->fetch_assoc()['count'];

echo "Total expired memberships: $final_count\n";

// Show sample dates by plan type
$sample_query = "
    SELECT username, membership_end_date, 
           CASE 
               WHEN membership_end_date <= CURDATE() THEN 'EXPIRED'
               ELSE 'ACTIVE'
           END as status,
           CASE 
               WHEN username IN ('micolyy', 'carlandreww', 'gavinnn', 'jesicahaha', 'owapawi', 'melitbautista') THEN 'ANNUAL'
               ELSE 'MONTHLY'
           END as plan_type
    FROM users
    WHERE role = 'member'
    ORDER BY membership_end_date DESC
    LIMIT 15
";

$sample_result = $conn->query($sample_query);
if ($sample_result && $sample_result->num_rows > 0) {
    echo "\nSample membership dates:\n";
    while ($member = $sample_result->fetch_assoc()) {
        echo "Username: {$member['username']} | End Date: {$member['membership_end_date']} | Status: {$member['status']} | Plan: {$member['plan_type']}\n";
    }
}

if ($final_count == 1) {
    echo "\n✅ SUCCESS: Now there is exactly 1 expired membership with correct plan dates!\n";
} else {
    echo "\n❌ Still have $final_count expired memberships\n";
}
?> 