<?php
require 'db.php';

echo "=== SETTING REALISTIC DATES ===\n\n";

// Set realistic dates for monthly plans
echo "Setting realistic dates for monthly plans...\n";

// For members who joined around July 30, 2024, set expiry to August 29, 2025
$realistic_dates_query = "
    UPDATE users
    SET membership_end_date = '2025-08-29'
    WHERE role = 'member'
      AND username IN ('haroldabiera', 'graceabiera', 'france', 'iannpogi', 
                       'ashleyyyyyy', 'yuriifullante', 'victortanafranca', 
                       'khaiibaldos', 'shane', 'ajhaypogi', 'maffycute', 
                       'paul', 'angelangit', 'leoknor', 'shanecute', 
                       'ezekielpogi', 'xandeecute', 'laulau')
";

$realistic_result = $conn->query($realistic_dates_query);
if ($realistic_result) {
    echo "Successfully set realistic dates for monthly plans\n";
} else {
    echo "Error setting dates: " . $conn->error . "\n";
}

// Set some members as expired (those who joined earlier)
$expired_dates_query = "
    UPDATE users
    SET membership_end_date = '2024-07-15'
    WHERE role = 'member'
      AND username IN ('andrew', 'genwilson', 'rizzaaa')
";

$expired_result = $conn->query($expired_dates_query);
if ($expired_result) {
    echo "Successfully set expired dates for older members\n";
} else {
    echo "Error setting expired dates: " . $conn->error . "\n";
}

// Keep charles as the only currently expired member
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

// Show sample dates
$sample_query = "
    SELECT username, membership_end_date, 
           CASE 
               WHEN membership_end_date <= CURDATE() THEN 'EXPIRED'
               ELSE 'ACTIVE'
           END as status
    FROM users
    WHERE role = 'member'
    ORDER BY membership_end_date DESC
    LIMIT 15
";

$sample_result = $conn->query($sample_query);
if ($sample_result && $sample_result->num_rows > 0) {
    echo "\nSample membership dates:\n";
    while ($member = $sample_result->fetch_assoc()) {
        echo "Username: {$member['username']} | End Date: {$member['membership_end_date']} | Status: {$member['status']}\n";
    }
}

if ($final_count == 1) {
    echo "\n✅ SUCCESS: Now there is exactly 1 expired membership with realistic dates!\n";
} else {
    echo "\n❌ Still have $final_count expired memberships\n";
}
?> 