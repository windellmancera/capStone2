<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not logged in');
}

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Database connection
require_once '../db.php';

$user_id = $_SESSION['user_id'];

// Create notifications table if it doesn't exist (compatible with existing system)
$create_table_sql = "CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error', 'reminder') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

$conn->query($create_table_sql);

// Function to send SSE data
function sendSSE($event, $data) {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Send initial connection message
sendSSE('connected', ['message' => 'Connected to real-time notifications', 'user_id' => $user_id]);

// Keep connection alive and check for new notifications
$last_check = time();
$notification_cache = [];

while (true) {
    // Check if client is still connected
    if (connection_aborted()) {
        break;
    }
    
    try {
        // Check for new notifications every 3 seconds
        if (time() - $last_check >= 3) {
            $last_check = time();
            
            // Get current notifications
            $notifications = getCurrentNotifications($conn, $user_id);
            
            // Check if notifications changed
            $current_hash = md5(json_encode($notifications));
            if (!isset($notification_cache[$user_id]) || $notification_cache[$user_id] !== $current_hash) {
                $notification_cache[$user_id] = $current_hash;
                
                // Send updated notifications
                sendSSE('notifications', [
                    'notifications' => $notifications,
                    'unread_count' => count($notifications),
                    'timestamp' => time()
                ]);
                
                // Send count update
                sendSSE('count_update', [
                    'unread_count' => count($notifications),
                    'timestamp' => time()
                ]);
            }
            
            // Check for new announcements
            $new_announcements = checkNewAnnouncements($conn, $user_id);
            if (!empty($new_announcements)) {
                sendSSE('new_announcement', [
                    'announcements' => $new_announcements,
                    'timestamp' => time()
                ]);
            }
            
            // Check for membership status changes
            $membership_update = checkMembershipStatus($conn, $user_id);
            if ($membership_update) {
                sendSSE('membership_update', [
                    'update' => $membership_update,
                    'timestamp' => time()
                ]);
            }
            
            // Check for equipment status changes
            $equipment_update = checkEquipmentStatus($conn, $user_id);
            if ($equipment_update) {
                sendSSE('equipment_update', [
                    'update' => $equipment_update,
                    'timestamp' => time()
                ]);
            }
        }
        
        // Send heartbeat every 30 seconds to keep connection alive
        if (time() % 30 === 0) {
            sendSSE('heartbeat', ['timestamp' => time()]);
        }
        
        // Sleep for 1 second before next check
        sleep(1);
        
    } catch (Exception $e) {
        sendSSE('error', ['error' => $e->getMessage()]);
        break;
    }
}

// Function to get current notifications
function getCurrentNotifications($conn, $user_id) {
    $notifications = [];
    
    // Get user information
    $user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration,
                         ph.payment_status, ph.payment_date,
                         (SELECT MAX(check_in_time) FROM attendance WHERE user_id = u.id) as last_visit,
                         (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND DATE(check_in_time) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as this_week_visits
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
        // Check membership notifications
        if ($user['payment_status'] === 'Approved' && $user['payment_date'] && $user['plan_duration']) {
            $membership_end_date = date('Y-m-d', strtotime($user['payment_date'] . ' + ' . $user['plan_duration'] . ' days'));
            $days_until_expiry = (strtotime($membership_end_date) - time()) / (60 * 60 * 24);
            
            if ($days_until_expiry <= 0) {
                if (!isNotificationRead($conn, $user_id, 'membership_expired')) {
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
                if (!isNotificationRead($conn, $user_id, 'membership_expiring')) {
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
        
        // Check attendance reminders
        $last_visit = $user['last_visit'];
        if ($last_visit) {
            $days_since_last_visit = (time() - strtotime($last_visit)) / (60 * 60 * 24);
            if ($days_since_last_visit >= 7) {
                if (!isNotificationRead($conn, $user_id, 'visit_reminder')) {
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
        
        // Check fitness goals
        if ($user['this_week_visits'] < 3) {
            if (!isNotificationRead($conn, $user_id, 'weekly_goal')) {
                $notifications[] = [
                    'id' => 'weekly_goal',
                    'title' => 'Weekly Goal Reminder',
                    'message' => "You've visited " . $user['this_week_visits'] . " times this week. Aim for 3+ visits for optimal results!",
                    'type' => 'reminder',
                    'priority' => 'low',
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }
        
        // Check for unread announcements
        $announcements_sql = "SELECT a.* FROM announcements a 
                              LEFT JOIN notifications n ON n.title = a.title AND n.user_id = ? AND n.type = 'info'
                              WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                              AND (n.is_read IS NULL OR n.is_read = FALSE)
                              ORDER BY a.created_at DESC LIMIT 3";
        $announcements_stmt = $conn->prepare($announcements_sql);
        $announcements_stmt->bind_param("i", $user_id);
        $announcements_stmt->execute();
        $announcements_result = $announcements_stmt->get_result();
        
        while ($announcement = $announcements_result->fetch_assoc()) {
            $notifications[] = [
                'id' => 'announcement_' . $announcement['id'],
                'title' => 'Gym Announcement',
                'message' => $announcement['title'] . ': ' . $announcement['content'],
                'type' => 'info',
                'priority' => 'medium',
                'read' => false,
                'timestamp' => strtotime($announcement['created_at'])
            ];
        }
        $announcements_stmt->close();
        
        // Check equipment maintenance
        $equipment_sql = "SELECT e.name, e.status FROM equipment e WHERE e.status = 'Maintenance' LIMIT 2";
        $equipment_result = $conn->query($equipment_sql);
        
        if ($equipment_result && $equipment_result->num_rows > 0) {
            if (!isNotificationRead($conn, $user_id, 'equipment_maintenance')) {
                $maintenance_equipment = [];
                while ($equipment = $equipment_result->fetch_assoc()) {
                    $maintenance_equipment[] = $equipment['name'];
                }
                
                if (!empty($maintenance_equipment)) {
                    $notifications[] = [
                        'id' => 'equipment_maintenance',
                        'title' => 'Equipment Maintenance',
                        'message' => 'Some equipment is under maintenance: ' . implode(', ', $maintenance_equipment),
                        'type' => 'warning',
                        'priority' => 'low',
                        'read' => false,
                        'timestamp' => time()
                    ];
                }
            }
        }
        
        // Check payment status
        if ($user['payment_status'] === 'pending') {
            if (!isNotificationRead($conn, $user_id, 'payment_pending')) {
                $notifications[] = [
                    'id' => 'payment_pending',
                    'title' => 'Payment Pending',
                    'message' => 'Your payment is still pending. Please complete the payment to activate your membership.',
                    'type' => 'warning',
                    'priority' => 'high',
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }
        
        // Welcome notification for new users
        $user_created = strtotime($user['created_at']);
        $days_since_created = (time() - $user_created) / (60 * 60 * 24);
        
        if ($days_since_created <= 7) {
            if (!isNotificationRead($conn, $user_id, 'welcome')) {
                $notifications[] = [
                    'id' => 'welcome',
                    'title' => 'Welcome to Almo Fitness!',
                    'message' => 'Welcome! We\'re excited to help you achieve your fitness goals. Start by exploring our facilities!',
                    'type' => 'success',
                    'priority' => 'low',
                    'read' => false,
                    'timestamp' => $user_created
                ];
            }
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
    return array_slice($notifications, 0, 10);
}

// Function to check if notification is read
function isNotificationRead($conn, $user_id, $notification_id) {
    $check_sql = "SELECT is_read FROM notifications WHERE user_id = ? AND title LIKE ?";
    $check_stmt = $conn->prepare($check_sql);
    $search_term = '%' . $notification_id . '%';
    $check_stmt->bind_param("is", $user_id, $search_term);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
    $check_stmt->close();
    return $is_read;
}

// Function to check for new announcements
function checkNewAnnouncements($conn, $user_id) {
    $sql = "SELECT * FROM announcements WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY created_at DESC LIMIT 5";
    $result = $conn->query($sql);
    $announcements = [];
    
    if ($result && $result->num_rows > 0) {
        while ($announcement = $result->fetch_assoc()) {
            $announcements[] = $announcement;
        }
    }
    
    return $announcements;
}

// Function to check membership status changes
function checkMembershipStatus($conn, $user_id) {
    $sql = "SELECT ph.*, mp.name as plan_name FROM payment_history ph 
            LEFT JOIN membership_plans mp ON ph.plan_id = mp.id 
            WHERE ph.user_id = ? AND ph.payment_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY ph.payment_date DESC LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payment = $result->fetch_assoc();
    $stmt->close();
    
    if ($payment) {
        return [
            'status' => $payment['payment_status'],
            'plan' => $payment['plan_name'],
            'date' => $payment['payment_date']
        ];
    }
    
    return null;
}

// Function to check equipment status changes
function checkEquipmentStatus($conn, $user_id) {
    $sql = "SELECT e.name, e.status, e.last_maintenance_date FROM equipment e 
            WHERE e.status = 'Maintenance' 
            AND e.last_maintenance_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
            ORDER BY e.last_maintenance_date DESC LIMIT 3";
    
    $result = $conn->query($sql);
    $equipment_updates = [];
    
    if ($result && $result->num_rows > 0) {
        while ($equipment = $result->fetch_assoc()) {
            $equipment_updates[] = $equipment;
        }
    }
    
    return !empty($equipment_updates) ? $equipment_updates : null;
}

$conn->close();
?>
