<?php
require 'db.php';

echo "<h2>Testing Complete Password Reset Flow</h2>";

// Get a test user
$sql = "SELECT id, username, email FROM users LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "✅ Using test user: {$user['username']} ({$user['email']})<br><br>";
    
    // Generate a fresh token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Delete any existing tokens for this user
    $delete_sql = "DELETE FROM password_reset_tokens WHERE user_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $user['id']);
    $delete_stmt->execute();
    
    // Insert new token
    $insert_sql = "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("iss", $user['id'], $token, $expires_at);
    
    if ($insert_stmt->execute()) {
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;
        
        echo "<h3>✅ Fresh Reset Token Generated!</h3>";
        echo "<p><strong>Reset Link:</strong></p>";
        echo "<div style='background: #f0f0f0; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "<a href='$reset_link' target='_blank'>$reset_link</a>";
        echo "</div>";
        
        echo "<h3>Testing Steps:</h3>";
        echo "<ol>";
        echo "<li>Click the reset link above (opens in new tab)</li>";
        echo "<li>Enter a new password (minimum 6 characters)</li>";
        echo "<li>Confirm the password</li>";
        echo "<li>Click 'Reset Password'</li>";
        echo "<li>Try logging in with the new password</li>";
        echo "</ol>";
        
        echo "<p><strong>Note:</strong> This token will expire in 1 hour.</p>";
        
    } else {
        echo "❌ Error generating token: " . $conn->error;
    }
} else {
    echo "❌ No users found in database";
}
?> 