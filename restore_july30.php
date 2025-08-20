<?php
require 'db.php';

echo "<h2>Restoring to July 30, 2025</h2>";

// Get all users
$sql = "SELECT id, username, email, created_at, membership_start_date, membership_end_date FROM users WHERE role = 'member' ORDER BY id DESC";
$result = $conn->query($sql);

echo "<h3>Current Member Dates:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Current Created At</th><th>Membership Start</th><th>Membership End</th></tr>";

$users = [];
while ($user = $result->fetch_assoc()) {
    $users[] = $user;
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['username']}</td>";
    echo "<td>{$user['email']}</td>";
    echo "<td>{$user['created_at']}</td>";
    echo "<td>{$user['membership_start_date']}</td>";
    echo "<td>{$user['membership_end_date']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Restoring to July 30, 2025...</h3>";

// Set all join dates to July 30, 2025
$july30_date = '2025-07-30 17:59:02';

foreach ($users as $user) {
    // Update the user's created_at date to July 30, 2025
    $update_sql = "UPDATE users SET created_at = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("si", $july30_date, $user['id']);
    
    if ($stmt->execute()) {
        echo "✅ Restored {$user['username']}: {$july30_date}<br>";
    } else {
        echo "❌ Error updating {$user['username']}: " . $conn->error . "<br>";
    }
}

// Set membership start dates to July 30, 2025
echo "<h3>Restoring Membership Start Dates...</h3>";

$membership_start = '2025-07-30';

foreach ($users as $user) {
    if (!empty($user['membership_start_date'])) {
        $update_membership = "UPDATE users SET membership_start_date = ? WHERE id = ?";
        $stmt = $conn->prepare($update_membership);
        $stmt->bind_param("si", $membership_start, $user['id']);
        
        if ($stmt->execute()) {
            echo "✅ Restored {$user['username']} membership start: {$membership_start}<br>";
        }
    }
}

// Set membership end dates to August 29, 2025 (30 days from July 30)
echo "<h3>Restoring Membership End Dates...</h3>";

$membership_end = '2025-08-29';

foreach ($users as $user) {
    if (!empty($user['membership_start_date'])) {
        $update_end = "UPDATE users SET membership_end_date = ? WHERE id = ?";
        $stmt = $conn->prepare($update_end);
        $stmt->bind_param("si", $membership_end, $user['id']);
        
        if ($stmt->execute()) {
            echo "✅ Restored {$user['username']} membership end: {$membership_end}<br>";
        }
    }
}

echo "<hr>";
echo "<h3>Restored Member Data:</h3>";

// Show restored data
$updated_sql = "SELECT id, username, email, created_at, membership_start_date, membership_end_date FROM users WHERE role = 'member' ORDER BY id DESC";
$result = $conn->query($updated_sql);

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Join Date</th><th>Membership Start</th><th>Membership End</th></tr>";

while ($user = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['username']}</td>";
    echo "<td>{$user['email']}</td>";
    echo "<td>" . date('M d, Y', strtotime($user['created_at'])) . "</td>";
    echo "<td>" . (!empty($user['membership_start_date']) ? date('M d, Y', strtotime($user['membership_start_date'])) : 'N/A') . "</td>";
    echo "<td>" . (!empty($user['membership_end_date']) ? date('M d, Y', strtotime($user['membership_end_date'])) : 'N/A') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<p><strong>✅ All member dates have been restored to July 30, 2025!</strong></p>";
echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
echo "<p><a href='admin/manage_members.php'>Go to Member List</a></p>";
?> 