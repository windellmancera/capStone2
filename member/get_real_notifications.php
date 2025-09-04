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
$notifications = [];

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
    
    // Get user information with membership details
    $user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration,
                         ph.payment_status, ph.payment_date,
                         (SELECT MAX(check_in_time) FROM attendance WHERE user_id = u.id) as last_visit,
                         (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND DATE(check_in_time) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as this_week_visits,
                         (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND DATE(check_in_time) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as this_month_visits
                      FROM users u 
                      LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
                      LEFT JOIN (
                          SELECT * FROM payment_history 
                          WHERE user_id = ? 
                          ORDER BY payment_date DESC 
                          LIMIT 1
                      ) ph ON ph.user_id = u.id
                      WHERE u.id = ?";
    
    $stmt = $conn->prepare($user_sql);
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        // 1. MEMBERSHIP NOTIFICATIONS
        if ($user['payment_status'] === 'Approved' && $user['payment_date'] && $user['plan_duration']) {
            $membership_end_date = date('Y-m-d', strtotime($user['payment_date'] . ' + ' . $user['plan_duration'] . ' days'));
            $days_until_expiry = (strtotime($membership_end_date) - time()) / (60 * 60 * 24);
            
            if ($days_until_expiry <= 0) {
                // Check if already read
                $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'membership_expired'";
                $check_stmt = $conn->prepare($check_read_sql);
                $check_stmt->bind_param("i", $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
                $check_stmt->close();
                
                if (!$is_read) {
                    $notifications[] = [
                        'id' => 'membership_expired',
                        'title' => 'Membership Expired',
                        'message' => 'Your membership has expired. Please renew to continue accessing the gym.',
                        'type' => 'warning',
                        'priority' => 'high',
                        'read' => false,
                        'timestamp' => time()
                    ];
                }
            } elseif ($days_until_expiry <= 3) {
                // Check if already read
                $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'membership_expiring'";
                $check_stmt = $conn->prepare($check_read_sql);
                $check_stmt->bind_param("i", $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
                $check_stmt->close();
                
                if (!$is_read) {
                    $notifications[] = [
                        'id' => 'membership_expiring',
                        'title' => 'Membership Expiring Soon',
                        'message' => "Your membership expires in " . ceil($days_until_expiry) . " days. Renew now to avoid interruption.",
                        'type' => 'info',
                        'priority' => 'medium',
                        'read' => false,
                        'timestamp' => time()
                    ];
                }
            }
        }

        // 2. ATTENDANCE REMINDERS
        $last_visit = $user['last_visit'];
        if ($last_visit) {
            $days_since_last_visit = (time() - strtotime($last_visit)) / (60 * 60 * 24);
            
            if ($days_since_last_visit >= 7) {
                // Check if already read
                $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'visit_reminder'";
                $check_stmt = $conn->prepare($check_read_sql);
                $check_stmt->bind_param("i", $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
                $check_stmt->close();
                
                if (!$is_read) {
                    $notifications[] = [
                        'id' => 'visit_reminder',
                        'title' => 'Visit Reminder',
                        'message' => "It's been " . ceil($days_since_last_visit) . " days since your last visit. Time to hit the gym!",
                        'type' => 'reminder',
                        'priority' => 'medium',
                        'read' => false,
                        'timestamp' => time()
                    ];
                }
            }
        }

        // 3. FITNESS GOAL REMINDERS
        if ($user['this_week_visits'] < 3) {
            // Check if already read
            $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'weekly_goal'";
            $check_stmt = $conn->prepare($check_read_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
            $check_stmt->close();
            
            if (!$is_read) {
                $notifications[] = [
                    'id' => 'weekly_goal',
                    'title' => 'Weekly Goal Reminder',
                    'message' => "You've visited " . $user['this_week_visits'] . " times this week. Aim for 3+ visits for optimal results!",
                    'type' => 'goal',
                    'priority' => 'low',
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }

        // 4. ADMIN ANNOUNCEMENTS (only show unread ones from last 7 days)
        $announcements_sql = "SELECT a.* FROM announcements a 
                              LEFT JOIN user_notifications un ON un.notification_id = CONCAT('announcement_', a.id) AND un.user_id = ?
                              WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                              AND (un.is_read IS NULL OR un.is_read = FALSE)
                              ORDER BY a.created_at DESC LIMIT 3";
        $announcements_stmt = $conn->prepare($announcements_sql);
        $announcements_stmt->bind_param("i", $user_id);
        $announcements_stmt->execute();
        $announcements_result = $announcements_stmt->get_result();
        
        if ($announcements_result && $announcements_result->num_rows > 0) {
            while ($announcement = $announcements_result->fetch_assoc()) {
                $notifications[] = [
                    'id' => 'announcement_' . $announcement['id'],
                    'title' => 'Gym Announcement',
                    'message' => $announcement['title'] . ': ' . $announcement['content'],
                    'type' => 'announcement',
                    'priority' => 'medium',
                    'read' => false,
                    'timestamp' => strtotime($announcement['created_at'])
                ];
            }
        }
        $announcements_stmt->close();

        // 5. EQUIPMENT MAINTENANCE NOTIFICATIONS
        $equipment_sql = "SELECT e.name, e.status FROM equipment e WHERE e.status = 'Maintenance' LIMIT 2";
        $equipment_result = $conn->query($equipment_sql);
        
        if ($equipment_result && $equipment_result->num_rows > 0) {
            // Check if already read
            $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'equipment_maintenance'";
            $check_stmt = $conn->prepare($check_read_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
            $check_stmt->close();
            
            if (!$is_read) {
                $maintenance_equipment = [];
                while ($equipment = $equipment_result->fetch_assoc()) {
                    $maintenance_equipment[] = $equipment['name'];
                }
                
                if (!empty($maintenance_equipment)) {
                    $notifications[] = [
                        'id' => 'equipment_maintenance',
                        'title' => 'Equipment Maintenance',
                        'message' => 'Some equipment is under maintenance: ' . implode(', ', $maintenance_equipment),
                        'type' => 'info',
                        'priority' => 'low',
                        'read' => false,
                        'timestamp' => time()
                    ];
                }
            }
        }

        // 6. PAYMENT REMINDERS
        if ($user['payment_status'] === 'pending') {
            // Check if already read
            $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'payment_pending'";
            $check_stmt = $conn->prepare($check_read_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
            $check_stmt->close();
            
            if (!$is_read) {
                $notifications[] = [
                    'id' => 'payment_pending',
                    'title' => 'Payment Pending',
                    'message' => 'Your payment is still pending. Please complete the payment to activate your membership.',
                    'type' => 'payment',
                    'priority' => 'high',
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }

        // 7. WELCOME NOTIFICATION (if new user, only show once)
        $user_created = strtotime($user['created_at']);
        $days_since_created = (time() - $user_created) / (60 * 60 * 24);
        
        if ($days_since_created <= 7) {
            // Check if already read
            $check_read_sql = "SELECT is_read FROM user_notifications WHERE user_id = ? AND notification_id = 'welcome'";
            $check_stmt = $conn->prepare($check_read_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
            $check_stmt->close();
            
            if (!$is_read) {
                $notifications[] = [
                    'id' => 'welcome',
                    'title' => 'Welcome to Almo Fitness!',
                    'message' => 'Welcome! We\'re excited to help you achieve your fitness goals. Start by exploring our facilities!',
                    'type' => 'welcome',
                    'priority' => 'low',
                    'read' => false,
                    'timestamp' => $user_created
                ];
            }
        }

        // Sort notifications by priority and timestamp
        usort($notifications, function($a, $b) {
            $priority_order = ['high' => 3, 'medium' => 2, 'low' => 1];
            if ($priority_order[$a['priority']] !== $priority_order[$b['priority']]) {
                return $priority_order[$b['priority']] - $priority_order[$a['priority']];
            }
            return $b['timestamp'] - $a['timestamp'];
        });

        // Limit to 10 notifications
        $notifications = array_slice($notifications, 0, 10);

        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => count($notifications),
            'timestamp' => time()
        ]);

    } else {
        throw new Exception('User not found');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch notifications: ' . $e->getMessage(),
        'notifications' => [],
        'unread_count' => 0
    ]);
}
?>
