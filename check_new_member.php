<?php
require_once 'db.php';

echo "=== Checking New Member Status ===\n";

// Find the most recent member (you)
$result = $conn->query("
    SELECT 
        u.id,
        u.username,
        u.email,
        u.role,
        u.membership_end_date,
        ph.payment_status,
        ph.payment_date,
        ph.amount,
        CASE 
            WHEN u.membership_end_date IS NULL THEN 'No membership date'
            WHEN u.membership_end_date > CURDATE() THEN 'Active'
            ELSE 'Expired'
        END as status
    FROM users u
    LEFT JOIN payment_history ph ON u.id = ph.user_id
    WHERE u.role = 'member'
    ORDER BY u.id DESC
    LIMIT 5
");

echo "Most Recent Members:\n";
while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']} | Username: {$row['username']} | Status: {$row['status']} | Payment: {$row['payment_status']} | End Date: " . ($row['membership_end_date'] ?? 'NULL') . "\n";
}

// Check current active members count
echo "\n=== Current Active Members Count ===\n";
$active_result = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND (
          u.membership_end_date IS NOT NULL AND u.membership_end_date > CURDATE()
      )
");
$active_count = $active_result->fetch_assoc()['count'];
echo "Active Members: $active_count\n";

// Check if there are any members with approved payments but no membership end date
echo "\n=== Members with Approved Payments ===\n";
$result = $conn->query("
    SELECT 
        u.id,
        u.username,
        u.membership_end_date,
        ph.payment_status,
        ph.payment_date,
        ph.amount
    FROM users u
    JOIN payment_history ph ON u.id = ph.user_id
    WHERE u.role = 'member' 
    AND ph.payment_status = 'Approved'
    ORDER BY ph.payment_date DESC
");
while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']} | Username: {$row['username']} | Payment: {$row['payment_status']} | Date: {$row['payment_date']} | Amount: {$row['amount']} | End Date: " . ($row['membership_end_date'] ?? 'NULL') . "\n";
}
?> 