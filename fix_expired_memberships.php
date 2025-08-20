<?php
require 'db.php';

echo "=== FIXING EXPIRED MEMBERSHIPS ===\n\n";

// First, let's see what we have
echo "Current expired memberships (calculated from payment date):\n";
$check_query = "
    SELECT
        u.id,
        u.username,
        u.email,
        u.membership_end_date,
        mp.duration as plan_duration,
        ph.payment_status,
        ph.payment_date,
        DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as calculated_expiry
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN (
        SELECT user_id, payment_status, payment_date
        FROM payment_history
        WHERE payment_status = 'Approved'
        ORDER BY payment_date DESC
    ) ph ON ph.user_id = u.id
    WHERE u.role = 'member'
      AND ph.payment_status = 'Approved'
      AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) <= CURDATE()
    ORDER BY ph.payment_date DESC
";

$check_result = $conn->query($check_query);
$expired_count = $check_result ? $check_result->num_rows : 0;
echo "Found $expired_count expired memberships\n\n";

// Update membership_end_date for all users with approved payments
echo "Updating membership_end_date for all users...\n";
$update_query = "
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

$update_result = $conn->query($update_query);
if ($update_result) {
    echo "Successfully updated membership end dates\n";
} else {
    echo "Error updating: " . $conn->error . "\n";
}

// Now let's extend most memberships to be active, leaving only 1 expired
echo "\nExtending memberships to leave only 1 expired...\n";

// Get the most recent expired membership and keep it expired
$keep_expired_query = "
    SELECT u.id, u.username, u.membership_end_date
    FROM users u
    WHERE u.role = 'member'
      AND u.membership_end_date IS NOT NULL
      AND u.membership_end_date <= CURDATE()
    ORDER BY u.membership_end_date DESC
    LIMIT 1
";

$keep_expired_result = $conn->query($keep_expired_query);
$keep_expired = $keep_expired_result->fetch_assoc();

if ($keep_expired) {
    echo "Keeping user ID {$keep_expired['id']} ({$keep_expired['username']}) as expired\n";
    
    // Extend all other expired memberships by 30 days
    $extend_query = "
        UPDATE users
        SET membership_end_date = DATE_ADD(membership_end_date, INTERVAL 30 DAY)
        WHERE role = 'member'
          AND membership_end_date IS NOT NULL
          AND membership_end_date <= CURDATE()
          AND id != ?
    ";
    
    $extend_stmt = $conn->prepare($extend_query);
    $extend_stmt->bind_param("i", $keep_expired['id']);
    
    if ($extend_stmt->execute()) {
        echo "Extended other expired memberships by 30 days\n";
    } else {
        echo "Error extending memberships: " . $conn->error . "\n";
    }
    $extend_stmt->close();
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