<?php
/**
 * Notification Helper
 * Handles notification functionality for users
 */

class NotificationHelper {
    private $conn;
    private $user_id;

    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
    }

    /**
     * Get user's notifications
     */
    public function getUserNotifications($limit = 10, $include_read = false) {
        $sql = "SELECT * FROM notifications 
                WHERE user_id = ? 
                " . ($include_read ? "" : "AND is_read = FALSE ") . "
                ORDER BY created_at DESC 
                LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        
        return $notifications;
    }

    /**
     * Get unread notification count
     */
    public function getUnreadCount() {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = ? AND is_read = FALSE";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notification_id) {
        $sql = "UPDATE notifications SET is_read = TRUE 
                WHERE id = ? AND user_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $notification_id, $this->user_id);
        return $stmt->execute();
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead() {
        $sql = "UPDATE notifications SET is_read = TRUE 
                WHERE user_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        return $stmt->execute();
    }

    /**
     * Create a new notification
     */
    public function createNotification($title, $message, $type = 'info') {
        $sql = "INSERT INTO notifications (user_id, title, message, type) 
                VALUES (?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isss", $this->user_id, $title, $message, $type);
        return $stmt->execute();
    }

    /**
     * Delete a notification
     */
    public function deleteNotification($notification_id) {
        $sql = "DELETE FROM notifications 
                WHERE id = ? AND user_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $notification_id, $this->user_id);
        return $stmt->execute();
    }

    /**
     * Get notification type icon
     */
    public static function getNotificationIcon($type) {
        switch ($type) {
            case 'success':
                return 'fas fa-check-circle';
            case 'warning':
                return 'fas fa-exclamation-triangle';
            case 'error':
                return 'fas fa-times-circle';
            case 'reminder':
                return 'fas fa-bell';
            default:
                return 'fas fa-info-circle';
        }
    }

    /**
     * Get notification type color
     */
    public static function getNotificationColor($type) {
        switch ($type) {
            case 'success':
                return 'text-green-600 bg-green-100';
            case 'warning':
                return 'text-yellow-600 bg-yellow-100';
            case 'error':
                return 'text-red-600 bg-red-100';
            case 'reminder':
                return 'text-blue-600 bg-blue-100';
            default:
                return 'text-gray-600 bg-gray-100';
        }
    }

    /**
     * Format notification time
     */
    public static function formatTime($timestamp) {
        $time = strtotime($timestamp);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }
}
?> 