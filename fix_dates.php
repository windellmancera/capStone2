<?php
require 'db.php';

echo "<h2>Checking and Fixing Dates</h2>";

// Check current dates in users table
$sql = "SELECT id, username, email, created_at, membership_start_date, membership_end_date FROM users ORDER BY id DESC LIMIT 10";
$result = $conn->query($sql);

echo "<h3>Current User Dates:</h3>";
echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Created At</th><th>Membership Start</th><th>Membership End</th></tr>";

while ($user = $result->fetch_assoc()) {
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

// Check if there are any incorrect dates (August 2025)
$incorrect_dates = "SELECT id, username, created_at FROM users WHERE created_at LIKE '2025-08%' OR created_at LIKE '2025-09%' OR created_at LIKE '2025-10%' OR created_at LIKE '2025-11%' OR created_at LIKE '2025-12%'";
$result = $conn->query($incorrect_dates);

if ($result->num_rows > 0) {
    echo "<h3>Users with Incorrect Dates (Future dates):</h3>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Created At</th></tr>";
    
    while ($user = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Fixing Dates...</h3>";
    
    // Fix the dates to current date
    $fix_sql = "UPDATE users SET created_at = NOW() WHERE created_at LIKE '2025-08%' OR created_at LIKE '2025-09%' OR created_at LIKE '2025-10%' OR created_at LIKE '2025-11%' OR created_at LIKE '2025-12%'";
    
    if ($conn->query($fix_sql)) {
        echo "✅ Fixed " . $conn->affected_rows . " user(s) with incorrect dates<br>";
    } else {
        echo "❌ Error fixing dates: " . $conn->error . "<br>";
    }
    
    // Also fix membership dates if they're in the future
    $fix_membership_sql = "UPDATE users SET membership_start_date = CURDATE() WHERE membership_start_date > CURDATE()";
    
    if ($conn->query($fix_membership_sql)) {
        echo "✅ Fixed " . $conn->affected_rows . " membership start date(s)<br>";
    }
    
    $fix_end_sql = "UPDATE users SET membership_end_date = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE membership_end_date > DATE_ADD(CURDATE(), INTERVAL 1 YEAR)";
    
    if ($conn->query($fix_end_sql)) {
        echo "✅ Fixed " . $conn->affected_rows . " membership end date(s)<br>";
    }
    
} else {
    echo "✅ No users with incorrect future dates found<br>";
}

echo "<hr>";
echo "<h3>Updated User Dates:</h3>";

// Check dates again after fixing
$sql = "SELECT id, username, email, created_at, membership_start_date, membership_end_date FROM users ORDER BY id DESC LIMIT 10";
$result = $conn->query($sql);

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Created At</th><th>Membership Start</th><th>Membership End</th></tr>";

while ($user = $result->fetch_assoc()) {
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

echo "<p><a href='admin/dashboard.php'>Go to Admin Dashboard</a></p>";
?> 