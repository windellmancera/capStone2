<?php
require_once 'db.php';

echo "=== Testing Active Member Count ===\n";

// Test the old logic (only membership end date)
$old_result = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND (
          u.membership_end_date IS NOT NULL AND u.membership_end_date > CURDATE()
      )
");
$old_count = $old_result->fetch_assoc()['count'];
echo "Old logic (only membership date): $old_count\n";

// Test the new logic (membership date OR approved payment with no date)
$new_result = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    LEFT JOIN payment_history ph ON u.id = ph.user_id
    WHERE u.role = 'member'
      AND (
          (u.membership_end_date IS NOT NULL AND u.membership_end_date > CURDATE())
          OR (ph.payment_status = 'Approved' AND u.membership_end_date IS NULL)
      )
");
$new_count = $new_result->fetch_assoc()['count'];
echo "New logic (membership date OR approved payment): $new_count\n";

// Show breakdown
echo "\n=== Breakdown ===\n";
$result = $conn->query("
    SELECT 
        u.id,
        u.username,
        u.membership_end_date,
        ph.payment_status,
        CASE 
            WHEN u.membership_end_date IS NOT NULL AND u.membership_end_date > CURDATE() THEN 'Active by date'
            WHEN ph.payment_status = 'Approved' AND u.membership_end_date IS NULL THEN 'Active by payment'
            ELSE 'Not active'
        END as active_reason
    FROM users u
    LEFT JOIN payment_history ph ON u.id = ph.user_id
    WHERE u.role = 'member'
    ORDER BY u.id
");

$active_by_date = 0;
$active_by_payment = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['active_reason'] === 'Active by date') {
        $active_by_date++;
        echo "ID: {$row['id']} | Username: {$row['username']} | Reason: {$row['active_reason']} | End Date: {$row['membership_end_date']}\n";
    } elseif ($row['active_reason'] === 'Active by payment') {
        $active_by_payment++;
        echo "ID: {$row['id']} | Username: {$row['username']} | Reason: {$row['active_reason']} | Payment: {$row['payment_status']}\n";
    }
}

echo "\nSummary:\n";
echo "Active by membership date: $active_by_date\n";
echo "Active by approved payment: $active_by_payment\n";
echo "Total active: " . ($active_by_date + $active_by_payment) . "\n";
?> 