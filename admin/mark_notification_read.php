<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../db.php';

$admin_id = $_SESSION['user_id'];
$notification_id = $_POST['notification_id'] ?? null;

if (!$notification_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Notification ID required']);
    exit();
}

try {
    // Create admin notifications table if it doesn't exist
    $create_table_sql = "CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info', 'success', 'warning', 'error', 'alert') DEFAULT 'info',
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $conn->query($create_table_sql);
    
    // Mark notification as read
    $mark_read_sql = "INSERT INTO admin_notifications (admin_id, title, message, type, is_read, updated_at) 
                       VALUES (?, ?, ?, 'info', TRUE, NOW()) 
                       ON DUPLICATE KEY UPDATE is_read = TRUE, updated_at = NOW()";
    $stmt = $conn->prepare($mark_read_sql);
    $stmt->bind_param("iss", $admin_id, $notification_id, $_POST['notification_type'] ?? 'general');
    $stmt->execute();
    $stmt->close();
    
    // Get updated notification count
    $count_sql = "SELECT COUNT(*) as unread_count FROM admin_notifications WHERE admin_id = ? AND is_read = FALSE";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $admin_id);
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
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
