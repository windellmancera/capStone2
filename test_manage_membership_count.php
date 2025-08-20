<?php
require 'db.php';

// Test the exact query from manage_membership.php
$demographics = $conn->query("
    SELECT 
        COUNT(*) as total_members,
        AVG(
            CASE 
                WHEN u.date_of_birth IS NOT NULL THEN TIMESTAMPDIFF(YEAR, u.date_of_birth, CURDATE())
                ELSE NULL
            END
        ) as avg_age,
        COUNT(CASE WHEN u.gender = 'Male' THEN 1 END) as male_count,
        COUNT(CASE WHEN u.gender = 'Female' THEN 1 END) as female_count,
        COUNT(CASE WHEN EXISTS (SELECT 1 FROM payment_history ph2 WHERE ph2.user_id = u.id AND ph2.payment_status = 'Approved') THEN 1 END) as active_members
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN payment_history ph ON u.id = ph.user_id
    WHERE u.role = 'member'
");

$demographics_data = $demographics->fetch_assoc();

echo "Manage Membership Page Active Members Count: " . $demographics_data['active_members'] . "\n";
echo "Total Members: " . $demographics_data['total_members'] . "\n";

// Also test the simpler query like in dashboard.php
$simple_query = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    WHERE u.role = 'member'
      AND EXISTS (
          SELECT 1 FROM payment_history ph
          WHERE ph.user_id = u.id
          AND ph.payment_status = 'Approved'
      )
");

$simple_result = $simple_query->fetch_assoc();
echo "Simple Query Active Members Count: " . $simple_result['count'] . "\n";
?> 