<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../db.php';
require_once 'notification_helper.php';

$user_id = $_SESSION['user_id'];
$notification_helper = new NotificationHelper($conn, $user_id);

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_notifications':
        $notifications = $notification_helper->getUserNotifications(10, true);
        echo json_encode(['success' => true, 'notifications' => $notifications]);
        break;
        
    case 'get_unread_count':
        $count = $notification_helper->getUnreadCount();
        echo json_encode(['success' => true, 'count' => $count]);
        break;
        
    case 'mark_as_read':
        $notification_id = $_POST['notification_id'] ?? null;
        if ($notification_id) {
            $success = $notification_helper->markAsRead($notification_id);
            echo json_encode(['success' => $success]);
        } else {
            echo json_encode(['error' => 'Notification ID required']);
        }
        break;
        
    case 'mark_all_read':
        $success = $notification_helper->markAllAsRead();
        echo json_encode(['success' => $success]);
        break;
        
    case 'delete_notification':
        $notification_id = $_POST['notification_id'] ?? null;
        if ($notification_id) {
            $success = $notification_helper->deleteNotification($notification_id);
            echo json_encode(['success' => $success]);
        } else {
            echo json_encode(['error' => 'Notification ID required']);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?> 