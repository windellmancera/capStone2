<?php
require 'db.php';

// Get the first user from database
$sql = "SELECT id, username, email FROM users LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Generate a fresh token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    // Clear any existing tokens for this user
    $delete_sql = "DELETE FROM password_reset_tokens WHERE user_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $user['id']);
    $delete_stmt->execute();
    
    // Insert new token
    $insert_sql = "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("iss", $user['id'], $token, $expires_at);
    
    if ($insert_stmt->execute()) {
        // Redirect to reset page with the token
        header("Location: reset_password.php?token=" . $token);
        exit();
    } else {
        echo "Error creating token: " . $conn->error;
    }
} else {
    echo "No users found in database";
}
?> 