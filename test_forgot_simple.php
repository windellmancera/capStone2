<?php
require 'db.php';

echo "<h2>Testing Forgot Password Functionality</h2>";

// Test 1: Check database connection
if ($conn->ping()) {
    echo "✅ Database connection: OK<br>";
} else {
    echo "❌ Database connection: FAILED<br>";
    exit();
}

// Test 2: Check if password_reset_tokens table exists
$table_check = "SHOW TABLES LIKE 'password_reset_tokens'";
$result = $conn->query($table_check);

if ($result->num_rows > 0) {
    echo "✅ Password reset tokens table: EXISTS<br>";
} else {
    echo "❌ Password reset tokens table: MISSING<br>";
    echo "Creating table...<br>";
    
    $create_sql = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `token` varchar(255) NOT NULL,
        `expires_at` timestamp NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `token` (`token`),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($create_sql)) {
        echo "✅ Table created successfully<br>";
    } else {
        echo "❌ Error creating table: " . $conn->error . "<br>";
    }
}

// Test 3: Check if users exist
$user_check = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($user_check);
$user_count = $result->fetch_assoc()['count'];

echo "✅ Users in database: $user_count<br>";

// Test 4: Show available users
$sample_users = "SELECT id, username, email FROM users LIMIT 5";
$result = $conn->query($sample_users);

echo "<h3>Available Users for Testing:</h3>";
echo "<ul>";
while ($user = $result->fetch_assoc()) {
    echo "<li><strong>{$user['username']}</strong> - {$user['email']}</li>";
}
echo "</ul>";

// Test 5: Simulate forgot password process
echo "<h3>Testing Forgot Password Process:</h3>";

if (isset($_POST['test_email'])) {
    $email = trim($_POST['test_email']);
    
    echo "Testing with email: $email<br>";
    
    // Check if email exists
    $sql = "SELECT id, username, email FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo "✅ User found: {$user['username']}<br>";
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Delete existing tokens
        $delete_sql = "DELETE FROM password_reset_tokens WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $user['id']);
        $delete_stmt->execute();
        
        // Insert new token
        $insert_sql = "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("iss", $user['id'], $token, $expires_at);
        
        if ($insert_stmt->execute()) {
            echo "✅ Token generated successfully<br>";
            echo "Token: " . substr($token, 0, 20) . "...<br>";
            echo "Expires: $expires_at<br>";
            
            $reset_link = "reset_password.php?token=" . $token;
            echo "<p><strong>Reset Link:</strong> <a href='$reset_link' target='_blank'>$reset_link</a></p>";
        } else {
            echo "❌ Error generating token: " . $conn->error . "<br>";
        }
    } else {
        echo "❌ Email not found in database<br>";
    }
} else {
    echo "<form method='post'>";
    echo "<p>Enter an email to test:</p>";
    echo "<input type='email' name='test_email' placeholder='Enter email' required>";
    echo "<button type='submit'>Test Forgot Password</button>";
    echo "</form>";
}

echo "<hr>";
echo "<p><a href='forgot.php'>Go to Forgot Password Page</a></p>";
?> 