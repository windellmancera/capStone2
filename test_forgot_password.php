<?php
require 'db.php';

echo "<h2>Testing Forgot Password Functionality</h2>";

// Test 1: Check if password_reset_tokens table exists
$table_check = "SHOW TABLES LIKE 'password_reset_tokens'";
$result = $conn->query($table_check);

if ($result->num_rows > 0) {
    echo "✅ Password reset tokens table exists<br>";
} else {
    echo "❌ Password reset tokens table does not exist<br>";
    echo "Please run setup_password_reset.php first<br>";
    exit();
}

// Test 2: Check if there are any users in the system
$user_check = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($user_check);
$user_count = $result->fetch_assoc()['count'];

echo "✅ Found $user_count users in the system<br>";

// Test 3: Show sample users for testing
$sample_users = "SELECT id, username, email FROM users LIMIT 3";
$result = $conn->query($sample_users);

echo "<h3>Sample Users for Testing:</h3>";
echo "<ul>";
while ($user = $result->fetch_assoc()) {
    echo "<li>ID: {$user['id']} - Username: {$user['username']} - Email: {$user['email']}</li>";
}
echo "</ul>";

echo "<h3>How to Test:</h3>";
echo "<ol>";
echo "<li>Go to <a href='forgot.php'>forgot.php</a></li>";
echo "<li>Enter one of the email addresses above</li>";
echo "<li>Click 'Reset Password'</li>";
echo "<li>Copy the generated reset link</li>";
echo "<li>Open the reset link in a new tab</li>";
echo "<li>Enter a new password</li>";
echo "<li>Try logging in with the new password</li>";
echo "</ol>";

echo "<p><a href='forgot.php'>Start Testing Forgot Password</a></p>";
?> 