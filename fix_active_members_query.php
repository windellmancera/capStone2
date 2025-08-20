<?php
require 'db.php';

echo "=== CHECKING ACTIVE MEMBERS QUERIES ===\n\n";

// Current admin query (counts members with approved payments)
$current_admin_query = "
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph 
          WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
      )
";

$current_result = $conn->query($current_admin_query);
$current_count = $current_result->fetch_assoc()['count'];
echo "Current admin query result: $current_count\n";

// What it should be (counts non-expired members)
$correct_query = "
    SELECT COUNT(*) as count
    FROM users
    WHERE role = 'member'
      AND (membership_end_date IS NULL OR membership_end_date > CURDATE())
";

$correct_result = $conn->query($correct_query);
$correct_count = $correct_result->fetch_assoc()['count'];
echo "Correct query result: $correct_count\n";

// Show the difference
echo "\nDifference: " . ($correct_count - $current_count) . "\n";

// Show members that are counted differently
echo "\n=== MEMBERS COUNTED DIFFERENTLY ===\n";

$difference_query = "
    SELECT 
        u.username,
        u.membership_end_date,
        CASE 
            WHEN EXISTS (SELECT 1 FROM payment_history ph WHERE ph.user_id = u.id AND ph.payment_status = 'Approved') 
            THEN 'HAS APPROVED PAYMENT'
            ELSE 'NO APPROVED PAYMENT'
        END as payment_status,
        CASE 
            WHEN u.membership_end_date IS NULL OR u.membership_end_date > CURDATE() 
            THEN 'NOT EXPIRED'
            ELSE 'EXPIRED'
        END as expiry_status
    FROM users u
    WHERE u.role = 'member'
      AND (
          (EXISTS (SELECT 1 FROM payment_history ph WHERE ph.user_id = u.id AND ph.payment_status = 'Approved') 
           AND (u.membership_end_date IS NOT NULL AND u.membership_end_date <= CURDATE()))
        OR
          (NOT EXISTS (SELECT 1 FROM payment_history ph WHERE ph.user_id = u.id AND ph.payment_status = 'Approved') 
           AND (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE()))
      )
    ORDER BY u.username
";

$difference_result = $conn->query($difference_query);
if ($difference_result && $difference_result->num_rows > 0) {
    while ($member = $difference_result->fetch_assoc()) {
        echo "Username: {$member['username']} | End Date: {$member['membership_end_date']} | Payment: {$member['payment_status']} | Expiry: {$member['expiry_status']}\n";
    }
} else {
    echo "No differences found\n";
}
?> 