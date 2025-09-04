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
$action = $_POST['action'] ?? '';

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
    
    switch($action) {
        case 'get_count':
            // Get real-time unread count
            $count_sql = "SELECT COUNT(*) as unread_count FROM admin_notifications WHERE admin_id = ? AND is_read = FALSE";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bind_param("i", $admin_id);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_data = $count_result->fetch_assoc();
            $count_stmt->close();
            
            echo json_encode([
                'success' => true,
                'unread_count' => $count_data['unread_count'] ?? 0,
                'timestamp' => time()
            ]);
            break;
            
        case 'mark_all_read':
            // Mark all notifications as read
            $mark_all_sql = "UPDATE admin_notifications SET is_read = TRUE, updated_at = NOW() WHERE admin_id = ? AND is_read = FALSE";
            $mark_all_stmt = $conn->prepare($mark_all_sql);
            $mark_all_stmt->bind_param("i", $admin_id);
            $mark_all_stmt->execute();
            $affected_rows = $mark_all_stmt->affected_rows;
            $mark_all_stmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => "Marked $affected_rows notifications as read",
                'unread_count' => 0,
                'timestamp' => time()
            ]);
            break;
            
        case 'clear_all':
            // Delete all notifications for admin
            $clear_sql = "DELETE FROM admin_notifications WHERE admin_id = ?";
            $clear_stmt = $conn->prepare($clear_sql);
            $clear_stmt->bind_param("i", $admin_id);
            $clear_stmt->execute();
            $affected_rows = $clear_stmt->affected_rows;
            $clear_stmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => "Cleared $affected_rows notifications",
                'unread_count' => 0,
                'timestamp' => time()
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
