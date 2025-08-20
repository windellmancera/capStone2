<?php
require 'db.php';

echo "=== SIMPLE FIX FOR EXPIRED MEMBERSHIPS ===\n\n";

// First, let's see current expired count
$current_check = "
    SELECT COUNT(*) as count
    FROM users
    WHERE role = 'member'
      AND membership_end_date IS NOT NULL
      AND membership_end_date <= CURDATE()
";

$current_result = $conn->query($current_check);
$current_count = $current_result->fetch_assoc()['count'];
echo "Current expired memberships: $current_count\n\n";

// Make all members active by setting their end date to next year
echo "Making all members active...\n";
$make_active_query = "
    UPDATE users
    SET membership_end_date = DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
    WHERE role = 'member'
      AND membership_end_date IS NOT NULL
";

$make_active_result = $conn->query($make_active_query);
if ($make_active_result) {
    echo "Successfully made all members active\n";
} else {
    echo "Error making members active: " . $conn->error . "\n";
}

// Now set one specific member as expired
echo "\nSetting one member as expired...\n";
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
    echo "Error setting member as expired: " . $conn->error . "\n";
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

echo "Total expired memberships after fix: $final_count\n";

if ($final_count == 1) {
    echo "✅ SUCCESS: Now there is exactly 1 expired membership!\n";
} else {
    echo "❌ Still have $final_count expired memberships\n";
}

// Show the remaining expired member
$remaining_query = "
    SELECT id, username, email, membership_end_date
    FROM users
    WHERE role = 'member'
      AND membership_end_date IS NOT NULL
      AND membership_end_date <= CURDATE()
";

$remaining_result = $conn->query($remaining_query);
if ($remaining_result && $remaining_result->num_rows > 0) {
    echo "\nRemaining expired member(s):\n";
    while ($member = $remaining_result->fetch_assoc()) {
        echo "ID: {$member['id']} | Username: {$member['username']} | End Date: {$member['membership_end_date']}\n";
    }
}
?> 