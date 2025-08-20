<?php
require 'db.php';

echo "=== FIXING CHARLES EXPIRED DATE ===\n\n";

// Set charles to a definitely expired date (last year)
echo "Setting charles to an expired date...\n";
$charles_expired_query = "
    UPDATE users
    SET membership_end_date = '2024-01-15'
    WHERE role = 'member'
      AND username = 'charles'
";

$charles_result = $conn->query($charles_expired_query);
if ($charles_result) {
    echo "Successfully set charles to expired date (2024-01-15)\n";
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

// Show charles' new date
$charles_check = "
    SELECT username, membership_end_date, 
           CASE 
               WHEN membership_end_date <= CURDATE() THEN 'EXPIRED'
               ELSE 'ACTIVE'
           END as status
    FROM users
    WHERE role = 'member'
      AND username = 'charles'
";

$charles_check_result = $conn->query($charles_check);
if ($charles_check_result && $charles_check_result->num_rows > 0) {
    $charles_data = $charles_check_result->fetch_assoc();
    echo "\nCharles' new status:\n";
    echo "Username: {$charles_data['username']} | End Date: {$charles_data['membership_end_date']} | Status: {$charles_data['status']}\n";
}

if ($final_count == 1) {
    echo "\n✅ SUCCESS: Now charles should show as expired in the admin panel!\n";
} else {
    echo "\n❌ Still have $final_count expired memberships\n";
}
?> 