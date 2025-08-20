<?php
require 'db.php';

echo "<h2>Database Check</h2>";

// Check database connection
if ($conn->ping()) {
    echo "✅ Database connection successful<br>";
} else {
    echo "❌ Database connection failed<br>";
    exit();
}

// Check if password_reset_tokens table exists
$table_check = "SHOW TABLES LIKE 'password_reset_tokens'";
$result = $conn->query($table_check);

if ($result->num_rows > 0) {
    echo "✅ Password reset tokens table exists<br>";
} else {
    echo "❌ Password reset tokens table does not exist<br>";
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

// Check if users table has data
$user_check = "SELECT COUNT(*) as count FROM users";
$result = $conn->query($user_check);
$user_count = $result->fetch_assoc()['count'];

echo "✅ Found $user_count users in database<br>";

// Show sample users
$sample_users = "SELECT id, username, email FROM users LIMIT 3";
$result = $conn->query($sample_users);

echo "<h3>Sample Users:</h3>";
echo "<ul>";
while ($user = $result->fetch_assoc()) {
    echo "<li>ID: {$user['id']} - Username: {$user['username']} - Email: {$user['email']}</li>";
}
echo "</ul>";

echo "<h3>Quick Test:</h3>";
echo "<p><a href='quick_reset.php'>Click here to test password reset</a></p>";
echo "<p>This will generate a fresh token and redirect you to the reset page.</p>";
?> 