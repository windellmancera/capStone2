<?php
require 'db.php';

echo "<h2>Debugging Password Reset Token</h2>";

if (isset($_GET['token'])) {
    $token = $_GET['token'];
    echo "<p><strong>Checking token:</strong> $token</p>";
    
    // Check if token exists
    $sql = "SELECT * FROM password_reset_tokens WHERE token = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $token_data = $result->fetch_assoc();
        echo "✅ Token found in database<br>";
        echo "User ID: {$token_data['user_id']}<br>";
        echo "Expires at: {$token_data['expires_at']}<br>";
        echo "Created at: {$token_data['created_at']}<br>";
        
        // Check if token is expired
        $now = date('Y-m-d H:i:s');
        echo "Current time: $now<br>";
        
        if ($token_data['expires_at'] > $now) {
            echo "✅ Token is NOT expired<br>";
            
            // Check if user exists
            $user_sql = "SELECT username, email FROM users WHERE id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $token_data['user_id']);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            
            if ($user_result->num_rows > 0) {
                $user = $user_result->fetch_assoc();
                echo "✅ User found: {$user['username']} ({$user['email']})<br>";
                echo "<p style='color: green;'><strong>Token is VALID!</strong></p>";
            } else {
                echo "❌ User not found<br>";
            }
        } else {
            echo "❌ Token is EXPIRED<br>";
        }
    } else {
        echo "❌ Token not found in database<br>";
    }
} else {
    echo "<p>No token provided. Add ?token=YOUR_TOKEN to URL</p>";
}

echo "<hr>";
echo "<h3>Recent Tokens in Database:</h3>";
$recent_sql = "SELECT prt.*, u.username, u.email 
                FROM password_reset_tokens prt 
                JOIN users u ON prt.user_id = u.id 
                ORDER BY prt.created_at DESC 
                LIMIT 5";
$recent_result = $conn->query($recent_sql);

if ($recent_result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Token</th><th>User</th><th>Expires</th><th>Status</th></tr>";
    while ($row = $recent_result->fetch_assoc()) {
        $status = ($row['expires_at'] > date('Y-m-d H:i:s')) ? "Valid" : "Expired";
        $status_color = ($status == "Valid") ? "green" : "red";
        echo "<tr>";
        echo "<td>" . substr($row['token'], 0, 20) . "...</td>";
        echo "<td>{$row['username']}</td>";
        echo "<td>{$row['expires_at']}</td>";
        echo "<td style='color: $status_color;'>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No tokens found in database";
}
?> 