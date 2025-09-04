<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

// Database connection
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$notification_id = $_POST['notification_id'] ?? '';

if (empty($notification_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Notification ID required']);
    exit();
}

try {
    // Create notifications table if it doesn't exist
    $create_table_sql = "CREATE TABLE IF NOT EXISTS user_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        notification_id VARCHAR(255) NOT NULL,
        notification_type VARCHAR(100) NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        read_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_notification (user_id, notification_id)
    )";
    
    $conn->query($create_table_sql);
    
    // Mark notification as read
    $mark_read_sql = "INSERT INTO user_notifications (user_id, notification_id, notification_type, is_read, read_at) 
                       VALUES (?, ?, ?, TRUE, NOW()) 
                       ON DUPLICATE KEY UPDATE is_read = TRUE, read_at = NOW()";
    
    $stmt = $conn->prepare($mark_read_sql);
    $stmt->bind_param("iss", $user_id, $notification_id, $_POST['notification_type'] ?? 'general');
    $stmt->execute();
    $stmt->close();
    
    // Get updated notification count
    $count_sql = "SELECT COUNT(*) as unread_count FROM user_notifications WHERE user_id = ? AND is_read = FALSE";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    $count_stmt->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification marked as read',
        'unread_count' => $count_data['unread_count'] ?? 0,
        'notification_id' => $notification_id
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to mark notification as read: ' . $e->getMessage()
    ]);
}
?>
