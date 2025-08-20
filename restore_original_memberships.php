<?php
require 'db.php';

echo "=== RESTORING ORIGINAL MEMBERSHIP END DATES ===\n\n";

// Restore membership_end_date for all users based on their latest approved payment and plan duration
echo "Restoring membership end dates based on payment history...\n";
$restore_query = "
    UPDATE users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN (
        SELECT user_id, payment_status, payment_date
        FROM payment_history
        WHERE payment_status = 'Approved'
        ORDER BY payment_date DESC
    ) ph ON ph.user_id = u.id
    SET u.membership_end_date = DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY)
    WHERE u.role = 'member'
      AND ph.payment_status = 'Approved'
      AND mp.duration IS NOT NULL
";

$restore_result = $conn->query($restore_query);
if ($restore_result) {
    echo "Successfully restored membership end dates\n";
} else {
    echo "Error restoring: " . $conn->error . "\n";
}

// Check the current expired count
echo "\n=== CURRENT STATUS ===\n";
$expired_check = "
    SELECT COUNT(*) as count
    FROM users
    WHERE role = 'member'
      AND membership_end_date IS NOT NULL
      AND membership_end_date <= CURDATE()
";

$expired_result = $conn->query($expired_check);
$expired_count = $expired_result->fetch_assoc()['count'];
echo "Total expired memberships: $expired_count\n";

// Show all expired members
$show_expired_query = "
    SELECT id, username, email, membership_end_date
    FROM users
    WHERE role = 'member'
      AND membership_end_date IS NOT NULL
      AND membership_end_date <= CURDATE()
    ORDER BY membership_end_date DESC
";

$show_expired_result = $conn->query($show_expired_query);
if ($show_expired_result && $show_expired_result->num_rows > 0) {
    echo "\nExpired members:\n";
    while ($member = $show_expired_result->fetch_assoc()) {
        echo "ID: {$member['id']} | Username: {$member['username']} | End Date: {$member['membership_end_date']}\n";
    }
} else {
    echo "\nNo expired members found.\n";
}

echo "\n✅ System restored to original state based on payment history!\n";
?> 