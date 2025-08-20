<?php
require 'db.php';

echo "=== CHECKING ACTIVE MEMBERS COUNT ===\n\n";

// Check total members
$total_query = "SELECT COUNT(*) as count FROM users WHERE role = 'member'";
$total_result = $conn->query($total_query);
$total_members = $total_result->fetch_assoc()['count'];
echo "Total members: $total_members\n";

// Check expired members
$expired_query = "SELECT COUNT(*) as count FROM users WHERE role = 'member' AND membership_end_date IS NOT NULL AND membership_end_date <= CURDATE()";
$expired_result = $conn->query($expired_query);
$expired_members = $expired_result->fetch_assoc()['count'];
echo "Expired members: $expired_members\n";

// Check active members (total - expired)
$active_members = $total_members - $expired_members;
echo "Active members (calculated): $active_members\n";

// Check what the admin query is actually counting
$admin_active_query = "
    SELECT COUNT(*) as count
    FROM users
    WHERE role = 'member'
      AND (membership_end_date IS NULL OR membership_end_date > CURDATE())
";
$admin_active_result = $conn->query($admin_active_query);
$admin_active_count = $admin_active_result->fetch_assoc()['count'];
echo "Admin active count: $admin_active_count\n";

// Show all members with their status
echo "\n=== ALL MEMBERS STATUS ===\n";
$all_members_query = "
    SELECT username, membership_end_date, 
           CASE 
               WHEN membership_end_date IS NULL THEN 'NO DATE'
               WHEN membership_end_date <= CURDATE() THEN 'EXPIRED'
               ELSE 'ACTIVE'
           END as status
    FROM users
    WHERE role = 'member'
    ORDER BY username
";

$all_members_result = $conn->query($all_members_query);
if ($all_members_result && $all_members_result->num_rows > 0) {
    while ($member = $all_members_result->fetch_assoc()) {
        echo "Username: {$member['username']} | End Date: {$member['membership_end_date']} | Status: {$member['status']}\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Total members: $total_members\n";
echo "Expired members: $expired_members\n";
echo "Active members (should be): " . ($total_members - $expired_members) . "\n";
echo "Admin shows active: $admin_active_count\n";
?> 