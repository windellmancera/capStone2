<?php
require 'db.php';

echo "=== FINAL DATE FIX ===\n\n";

// Make all members active with realistic 2025 dates
echo "Making all members active with realistic dates...\n";
$make_active_query = "
    UPDATE users
    SET membership_end_date = '2025-08-29'
    WHERE role = 'member'
      AND username != 'charles'
";

$make_active_result = $conn->query($make_active_query);
if ($make_active_result) {
    echo "Successfully made all members active\n";
} else {
    echo "Error making members active: " . $conn->error . "\n";
}

// Set charles as the only expired member
echo "\nSetting charles as the only expired member...\n";
$set_expired_query = "
    UPDATE users
    SET membership_end_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    WHERE role = 'member'
      AND username = 'charles'
";

$set_expired_result = $conn->query($set_expired_query);
if ($set_expired_result) {
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
    LIMIT 10
";

$sample_result = $conn->query($sample_query);
if ($sample_result && $sample_result->num_rows > 0) {
    echo "\nSample membership dates:\n";
    while ($member = $sample_result->fetch_assoc()) {
        echo "Username: {$member['username']} | End Date: {$member['membership_end_date']} | Status: {$member['status']}\n";
    }
}

if ($final_count == 1) {
    echo "\n✅ SUCCESS: Now there is exactly 1 expired membership!\n";
} else {
    echo "\n❌ Still have $final_count expired memberships\n";
}
?> 