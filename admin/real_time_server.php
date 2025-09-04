<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    exit('Not authorized');
}

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Database connection
require_once '../db.php';

$admin_id = $_SESSION['user_id'];

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

// Function to send SSE data
function sendSSE($event, $data) {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Send initial connection message
sendSSE('connected', ['message' => 'Connected to admin real-time notifications', 'admin_id' => $admin_id]);

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
            
            // Get current admin notifications
            $notifications = getCurrentAdminNotifications($conn, $admin_id);
            
            // Check if notifications changed
            $current_hash = md5(json_encode($notifications));
            if (!isset($notification_cache[$admin_id]) || $notification_cache[$admin_id] !== $current_hash) {
                $notification_cache[$admin_id] = $current_hash;
                
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
            
            // Check for new member registrations
            $new_members = checkNewMembers($conn);
            if (!empty($new_members)) {
                sendSSE('new_members', [
                    'members' => $new_members,
                    'timestamp' => time()
                ]);
            }
            
            // Check for pending payments
            $pending_payments = checkPendingPayments($conn);
            if (!empty($pending_payments)) {
                sendSSE('pending_payments', [
                    'payments' => $pending_payments,
                    'timestamp' => time()
                ]);
            }
            
            // Check for equipment issues
            $equipment_issues = checkEquipmentIssues($conn);
            if (!empty($equipment_issues)) {
                sendSSE('equipment_issues', [
                    'equipment' => $equipment_issues,
                    'timestamp' => time()
                ]);
            }
            
            // Check for membership expirations
            $expiring_memberships = checkExpiringMemberships($conn);
            if (!empty($expiring_memberships)) {
                sendSSE('expiring_memberships', [
                    'memberships' => $expiring_memberships,
                    'timestamp' => time()
                ]);
            }
            
            // Check for low attendance alerts
            $low_attendance = checkLowAttendance($conn);
            if (!empty($low_attendance)) {
                sendSSE('low_attendance', [
                    'attendance' => $low_attendance,
                    'timestamp' => time()
                ]);
            }
            
            // Check for feedback submissions
            $new_feedback = checkNewFeedback($conn);
            if (!empty($new_feedback)) {
                sendSSE('new_feedback', [
                    'feedback' => $new_feedback,
                    'timestamp' => time()
                ]);
            }
            
            // Check for system alerts
            $system_alerts = checkSystemAlerts($conn);
            if (!empty($system_alerts)) {
                sendSSE('system_alerts', [
                    'alerts' => $system_alerts,
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

// Function to get current admin notifications
function getCurrentAdminNotifications($conn, $admin_id) {
    $notifications = [];
    
    // Check for new member registrations (last 24 hours)
    $new_members_sql = "SELECT COUNT(*) as count FROM users 
                        WHERE role = 'member' 
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
    $new_members_result = $conn->query($new_members_sql);
    $new_members_count = $new_members_result->fetch_assoc()['count'];
    
    if ($new_members_count > 0 && !isAdminNotificationRead($conn, $admin_id, 'new_members_today')) {
        $notifications[] = [
            'id' => 'new_members_today',
            'title' => 'New Member Registrations',
            'message' => "$new_members_count new member(s) registered today",
            'type' => 'info',
            'priority' => 'medium',
            'read' => false,
            'timestamp' => time()
        ];
    }
    
    // Check for pending payments
    $pending_payments_sql = "SELECT COUNT(*) as count FROM payment_history 
                            WHERE payment_status = 'Pending'";
    $pending_result = $conn->query($pending_payments_sql);
    $pending_count = $pending_result->fetch_assoc()['count'];
    
    if ($pending_count > 0 && !isAdminNotificationRead($conn, $admin_id, 'pending_payments')) {
        $notifications[] = [
            'id' => 'pending_payments',
            'title' => 'Pending Payments',
            'message' => "$pending_count payment(s) pending approval",
            'type' => 'warning',
            'priority' => 'high',
            'read' => false,
            'timestamp' => time()
        ];
    }
    
    // Check for equipment under maintenance
    $maintenance_sql = "SELECT COUNT(*) as count FROM equipment 
                       WHERE status = 'Maintenance'";
    $maintenance_result = $conn->query($maintenance_sql);
    $maintenance_count = $maintenance_result->fetch_assoc()['count'];
    
    if ($maintenance_count > 0 && !isAdminNotificationRead($conn, $admin_id, 'equipment_maintenance')) {
        $notifications[] = [
            'id' => 'equipment_maintenance',
            'title' => 'Equipment Maintenance',
            'message' => "$maintenance_count equipment item(s) under maintenance",
            'type' => 'warning',
            'priority' => 'medium',
            'read' => false,
            'timestamp' => time()
        ];
    }
    
    // Check for expiring memberships (next 7 days)
    $expiring_sql = "SELECT COUNT(*) as count FROM users u 
                     JOIN membership_plans mp ON u.selected_plan_id = mp.id 
                     JOIN payment_history ph ON u.id = ph.user_id 
                     WHERE ph.payment_status = 'Approved' 
                     AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) 
                     BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    $expiring_result = $conn->query($expiring_sql);
    $expiring_count = $expiring_result->fetch_assoc()['count'];
    
    if ($expiring_count > 0 && !isAdminNotificationRead($conn, $admin_id, 'expiring_memberships')) {
        $notifications[] = [
            'id' => 'expiring_memberships',
            'title' => 'Memberships Expiring Soon',
            'message' => "$expiring_count membership(s) expiring in the next 7 days",
            'type' => 'warning',
            'priority' => 'high',
            'read' => false,
            'timestamp' => time()
        ];
    }
    
    // Check for low attendance (members who haven't visited in 14+ days)
    $low_attendance_sql = "SELECT COUNT(DISTINCT u.id) as count FROM users u 
                           LEFT JOIN attendance a ON u.id = a.user_id 
                           WHERE u.role = 'member' 
                           AND (a.check_in_time IS NULL OR a.check_in_time < DATE_SUB(NOW(), INTERVAL 14 DAY))";
    $low_attendance_result = $conn->query($low_attendance_sql);
    $low_attendance_count = $low_attendance_result->fetch_assoc()['count'];
    
    if ($low_attendance_count > 0 && !isAdminNotificationRead($conn, $admin_id, 'low_attendance')) {
        $notifications[] = [
            'id' => 'low_attendance',
            'title' => 'Low Attendance Alert',
            'message' => "$low_attendance_count member(s) haven't visited in 14+ days",
            'type' => 'warning',
            'priority' => 'medium',
            'read' => false,
            'timestamp' => time()
        ];
    }
    
    // Check for new feedback submissions (last 24 hours)
    $new_feedback_sql = "SELECT COUNT(*) as count FROM feedback 
                         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
    $feedback_result = $conn->query($new_feedback_sql);
    $feedback_count = $feedback_result->fetch_assoc()['count'];
    
    if ($feedback_count > 0 && !isAdminNotificationRead($conn, $admin_id, 'new_feedback')) {
        $notifications[] = [
            'id' => 'new_feedback',
            'title' => 'New Feedback Received',
            'message' => "$feedback_count new feedback submission(s) received",
            'type' => 'info',
            'priority' => 'low',
            'read' => false,
            'timestamp' => time()
        ];
    }
    
    // Check for sales performance alerts
    $sales_alerts = checkSalesPerformance($conn);
    if (!empty($sales_alerts)) {
        foreach ($sales_alerts as $alert) {
            if (!isAdminNotificationRead($conn, $admin_id, 'sales_' . $alert['id'])) {
                $notifications[] = [
                    'id' => 'sales_' . $alert['id'],
                    'title' => $alert['title'],
                    'message' => $alert['message'],
                    'type' => $alert['type'],
                    'priority' => $alert['priority'],
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }
    }
    
    // Check for revenue alerts
    $revenue_alerts = checkRevenueAlerts($conn);
    if (!empty($revenue_alerts)) {
        foreach ($revenue_alerts as $alert) {
            if (!isAdminNotificationRead($conn, $admin_id, 'revenue_' . $alert['id'])) {
                $notifications[] = [
                    'id' => 'revenue_' . $alert['id'],
                    'title' => $alert['title'],
                    'message' => $alert['message'],
                    'type' => $alert['type'],
                    'priority' => $alert['priority'],
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }
    }
    
    // Check for business alerts
    $business_alerts = checkBusinessAlerts($conn);
    if (!empty($business_alerts)) {
        foreach ($business_alerts as $alert) {
            if (!isAdminNotificationRead($conn, $admin_id, 'business_' . $alert['id'])) {
                $notifications[] = [
                    'id' => 'business_' . $alert['id'],
                    'title' => $alert['title'],
                    'message' => $alert['message'],
                    'type' => $alert['type'],
                    'priority' => $alert['priority'],
                    'read' => false,
                    'timestamp' => time()
                ];
            }
        }
    }
    
    // Check for system alerts (database issues, etc.)
    $system_alerts = checkSystemHealth($conn);
    if (!empty($system_alerts)) {
        foreach ($system_alerts as $alert) {
            if (!isAdminNotificationRead($conn, $admin_id, 'system_' . $alert['id'])) {
                $notifications[] = [
                    'id' => 'system_' . $alert['id'],
                    'title' => 'System Alert',
                    'message' => $alert['message'],
                    'type' => 'error',
                    'priority' => 'high',
                    'read' => false,
                    'timestamp' => time()
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
    
    // Limit to 20 notifications for admin (increased for business notifications)
    return array_slice($notifications, 0, 20);
}

// Function to check if admin notification is read
function isAdminNotificationRead($conn, $admin_id, $notification_id) {
    $check_sql = "SELECT is_read FROM admin_notifications WHERE admin_id = ? AND title LIKE ?";
    $check_stmt = $conn->prepare($check_sql);
    $search_term = '%' . $notification_id . '%';
    $check_stmt->bind_param("is", $admin_id, $search_term);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $is_read = $check_result->num_rows > 0 ? $check_result->fetch_assoc()['is_read'] : false;
    $check_stmt->close();
    return $is_read;
}

// Function to check for new members
function checkNewMembers($conn) {
    $sql = "SELECT u.id, u.username, u.full_name, u.created_at 
            FROM users u 
            WHERE u.role = 'member' 
            AND u.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) 
            ORDER BY u.created_at DESC LIMIT 5";
    $result = $conn->query($sql);
    $members = [];
    
    if ($result && $result->num_rows > 0) {
        while ($member = $result->fetch_assoc()) {
            $members[] = $member;
        }
    }
    
    return $members;
}

// Function to check for pending payments
function checkPendingPayments($conn) {
    $sql = "SELECT ph.*, u.username, u.full_name 
            FROM payment_history ph 
            JOIN users u ON ph.user_id = u.id 
            WHERE ph.payment_status = 'Pending' 
            ORDER BY ph.created_at DESC LIMIT 5";
    $result = $conn->query($sql);
    $payments = [];
    
    if ($result && $result->num_rows > 0) {
        while ($payment = $result->fetch_assoc()) {
            $payments[] = $payment;
        }
    }
    
    return $payments;
}

// Function to check for equipment issues
function checkEquipmentIssues($conn) {
    $sql = "SELECT e.* FROM equipment e 
            WHERE e.status IN ('Maintenance', 'Out of Order') 
            ORDER BY e.last_maintenance_date DESC LIMIT 5";
    $result = $conn->query($sql);
    $equipment = [];
    
    if ($result && $result->num_rows > 0) {
        while ($item = $result->fetch_assoc()) {
            $equipment[] = $item;
        }
    }
    
    return $equipment;
}

// Function to check for expiring memberships
function checkExpiringMemberships($conn) {
    $sql = "SELECT u.id, u.username, u.full_name, mp.name as plan_name,
                   DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as expiry_date
            FROM users u 
            JOIN membership_plans mp ON u.selected_plan_id = mp.id 
            JOIN payment_history ph ON u.id = ph.user_id 
            WHERE ph.payment_status = 'Approved' 
            AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) 
            BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY expiry_date ASC LIMIT 10";
    $result = $conn->query($sql);
    $memberships = [];
    
    if ($result && $result->num_rows > 0) {
        while ($membership = $result->fetch_assoc()) {
            $memberships[] = $membership;
        }
    }
    
    return $memberships;
}

// Function to check for low attendance
function checkLowAttendance($conn) {
    $sql = "SELECT u.id, u.username, u.full_name, 
                   MAX(a.check_in_time) as last_visit,
                   DATEDIFF(NOW(), MAX(a.check_in_time)) as days_since_visit
            FROM users u 
            LEFT JOIN attendance a ON u.id = a.user_id 
            WHERE u.role = 'member' 
            GROUP BY u.id 
            HAVING days_since_visit > 14 OR days_since_visit IS NULL
            ORDER BY days_since_visit DESC LIMIT 10";
    $result = $conn->query($sql);
    $attendance = [];
    
    if ($result && $result->num_rows > 0) {
        while ($record = $result->fetch_assoc()) {
            $attendance[] = $record;
        }
    }
    
    return $attendance;
}

// Function to check for new feedback
function checkNewFeedback($conn) {
    $sql = "SELECT f.*, u.username, u.full_name 
            FROM feedback f 
            JOIN users u ON f.user_id = u.id 
            WHERE f.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) 
            ORDER BY f.created_at DESC LIMIT 5";
    $result = $conn->query($sql);
    $feedback = [];
    
    if ($result && $result->num_rows > 0) {
        while ($item = $result->fetch_assoc()) {
            $feedback[] = $item;
        }
    }
    
    return $feedback;
}

// Function to check system health
function checkSystemHealth($conn) {
    $alerts = [];
    
    // Check database connection
    if (!$conn->ping()) {
        $alerts[] = [
            'id' => 'db_connection',
            'message' => 'Database connection issue detected'
        ];
    }
    
    // Check for large tables that might need optimization
    $large_tables_sql = "SELECT 
                            table_name, 
                            ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size_MB'
                         FROM information_schema.tables 
                         WHERE table_schema = DATABASE() 
                         AND ((data_length + index_length) / 1024 / 1024) > 10
                         ORDER BY (data_length + index_length) DESC";
    $large_tables_result = $conn->query($large_tables_sql);
    
    if ($large_tables_result && $large_tables_result->num_rows > 0) {
        while ($table = $large_tables_result->fetch_assoc()) {
            if ($table['Size_MB'] > 50) {
                $alerts[] = [
                    'id' => 'large_table_' . $table['table_name'],
                    'message' => "Large table detected: {$table['table_name']} ({$table['Size_MB']} MB)"
                ];
            }
        }
    }
    
    return $alerts;
}

// Function to check sales performance
function checkSalesPerformance($conn) {
    $alerts = [];
    
    // Check today's sales vs yesterday
    $today_sales_sql = "SELECT COALESCE(SUM(ph.amount), 0) as today_total 
                        FROM payment_history ph 
                        WHERE ph.payment_status = 'Completed' 
                        AND DATE(ph.payment_date) = CURDATE()";
    $today_result = $conn->query($today_sales_sql);
    $today_sales = $today_result->fetch_assoc()['today_total'];
    
    $yesterday_sales_sql = "SELECT COALESCE(SUM(ph.amount), 0) as yesterday_total 
                            FROM payment_history ph 
                            WHERE ph.payment_status = 'Completed' 
                            AND DATE(ph.payment_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    $yesterday_result = $conn->query($yesterday_sales_sql);
    $yesterday_sales = $yesterday_result->fetch_assoc()['yesterday_total'];
    
    if ($yesterday_sales > 0) {
        $change_percentage = (($today_sales - $yesterday_sales) / $yesterday_sales) * 100;
        
        if ($change_percentage < -20) {
            $alerts[] = [
                'id' => 'sales_decline',
                'title' => 'Sales Decline Alert',
                'message' => "Today's sales decreased by " . abs(round($change_percentage, 1)) . "% compared to yesterday",
                'type' => 'warning',
                'priority' => 'high'
            ];
        } elseif ($change_percentage > 20) {
            $alerts[] = [
                'id' => 'sales_increase',
                'title' => 'Sales Increase',
                'message' => "Today's sales increased by " . round($change_percentage, 1) . "% compared to yesterday",
                'type' => 'success',
                'priority' => 'low'
            ];
        }
    }
    
    // Check weekly sales trend
    $weekly_sales_sql = "SELECT 
                            DATE(ph.payment_date) as sale_date,
                            SUM(ph.amount) as daily_total
                         FROM payment_history ph 
                         WHERE ph.payment_status = 'Completed' 
                         AND ph.payment_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                         GROUP BY DATE(ph.payment_date)
                         ORDER BY sale_date DESC";
    $weekly_result = $conn->query($weekly_sales_sql);
    
    if ($weekly_result && $weekly_result->num_rows >= 3) {
        $weekly_totals = [];
        while ($row = $weekly_result->fetch_assoc()) {
            $weekly_totals[] = $row['daily_total'];
        }
        
        // Check if there's a consistent decline
        if (count($weekly_totals) >= 3) {
            $recent_avg = array_sum(array_slice($weekly_totals, 0, 3)) / 3;
            $older_avg = array_sum(array_slice($weekly_totals, 3)) / (count($weekly_totals) - 3);
            
            if ($older_avg > 0 && $recent_avg < ($older_avg * 0.7)) {
                $alerts[] = [
                    'id' => 'weekly_sales_decline',
                    'title' => 'Weekly Sales Trend Alert',
                    'message' => 'Recent 3-day sales average is ' . round((($recent_avg - $older_avg) / $older_avg) * 100, 1) . '% lower than previous days',
                    'type' => 'warning',
                    'priority' => 'medium'
                ];
            }
        }
    }
    
    return $alerts;
}

// Function to check revenue alerts
function checkRevenueAlerts($conn) {
    $alerts = [];
    
    // Check monthly revenue vs target (assuming 10% monthly growth target)
    $current_month_sql = "SELECT COALESCE(SUM(ph.amount), 0) as current_month_total 
                          FROM payment_history ph 
                          WHERE ph.payment_status = 'Completed' 
                          AND MONTH(ph.payment_date) = MONTH(CURDATE())
                          AND YEAR(ph.payment_date) = YEAR(CURDATE())";
    $current_month_result = $conn->query($current_month_sql);
    $current_month_revenue = $current_month_result->fetch_assoc()['current_month_total'];
    
    $last_month_sql = "SELECT COALESCE(SUM(ph.amount), 0) as last_month_total 
                       FROM payment_history ph 
                       WHERE ph.payment_status = 'Completed' 
                       AND MONTH(ph.payment_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
                       AND YEAR(ph.payment_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
    $last_month_result = $conn->query($last_month_sql);
    $last_month_revenue = $last_month_result->fetch_assoc()['last_month_total'];
    
    if ($last_month_revenue > 0) {
        $growth_rate = (($current_month_revenue - $last_month_revenue) / $last_month_revenue) * 100;
        $target_growth = 10; // 10% monthly growth target
        
        if ($growth_rate < $target_growth) {
            $alerts[] = [
                'id' => 'revenue_growth_target',
                'title' => 'Revenue Growth Target Alert',
                'message' => "Current month revenue growth is " . round($growth_rate, 1) . "%, below target of $target_growth%",
                'type' => 'warning',
                'priority' => 'high'
            ];
        } elseif ($growth_rate > ($target_growth * 1.5)) {
            $alerts[] = [
                'id' => 'revenue_exceeding_target',
                'title' => 'Revenue Exceeding Target',
                'message' => "Current month revenue growth is " . round($growth_rate, 1) . "%, exceeding target by " . round($growth_rate - $target_growth, 1) . "%",
                'type' => 'success',
                'priority' => 'low'
            ];
        }
    }
    
    // Check for high-value transactions
    $high_value_sql = "SELECT COUNT(*) as count FROM payment_history ph 
                       WHERE ph.payment_status = 'Completed' 
                       AND ph.amount > 1000 
                       AND ph.payment_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
    $high_value_result = $conn->query($high_value_sql);
    $high_value_count = $high_value_result->fetch_assoc()['count'];
    
    if ($high_value_count > 0) {
        $alerts[] = [
            'id' => 'high_value_transactions',
            'title' => 'High-Value Transactions',
            'message' => "$high_value_count high-value transaction(s) (>₱1,000) completed in the last 24 hours",
            'type' => 'success',
            'priority' => 'low'
        ];
    }
    
    return $alerts;
}

// Function to check business alerts
function checkBusinessAlerts($conn) {
    $alerts = [];
    
    // Check membership plan popularity
    $plan_popularity_sql = "SELECT 
                               mp.name as plan_name,
                               COUNT(u.id) as member_count
                            FROM membership_plans mp 
                            LEFT JOIN users u ON mp.id = u.selected_plan_id 
                            WHERE u.role = 'member'
                            GROUP BY mp.id, mp.name 
                            ORDER BY member_count DESC";
    $plan_result = $conn->query($plan_popularity_sql);
    
    if ($plan_result && $plan_result->num_rows > 0) {
        $plans = [];
        while ($plan = $plan_result->fetch_assoc()) {
            $plans[] = $plan;
        }
        
        if (count($plans) > 1) {
            $most_popular = $plans[0]['member_count'];
            $least_popular = $plans[count($plans) - 1]['member_count'];
            
            if ($least_popular > 0 && ($most_popular / $least_popular) > 5) {
                $alerts[] = [
                    'id' => 'plan_imbalance',
                    'title' => 'Membership Plan Imbalance',
                    'message' => "Plan popularity is highly skewed. Consider reviewing pricing or features for less popular plans.",
                    'type' => 'warning',
                    'priority' => 'medium'
                ];
            }
        }
    }
    
    // Check for inactive members (no visits in 30+ days)
    $inactive_members_sql = "SELECT COUNT(DISTINCT u.id) as count FROM users u 
                             LEFT JOIN attendance a ON u.id = a.user_id 
                             WHERE u.role = 'member' 
                             AND (a.check_in_time IS NULL OR a.check_in_time < DATE_SUB(NOW(), INTERVAL 30 DAY))";
    $inactive_result = $conn->query($inactive_members_sql);
    $inactive_count = $inactive_result->fetch_assoc()['count'];
    
    if ($inactive_count > 0) {
        $alerts[] = [
            'id' => 'inactive_members',
            'title' => 'Inactive Members Alert',
            'message' => "$inactive_count member(s) haven't visited in 30+ days. Consider re-engagement strategies.",
            'type' => 'warning',
            'priority' => 'medium'
        ];
    }
    
    // Check for peak hours analysis
    $peak_hours_sql = "SELECT 
                          HOUR(a.check_in_time) as hour,
                          COUNT(*) as visit_count
                       FROM attendance a 
                       WHERE a.check_in_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                       GROUP BY HOUR(a.check_in_time)
                       ORDER BY visit_count DESC
                       LIMIT 3";
    $peak_result = $conn->query($peak_hours_sql);
    
    if ($peak_result && $peak_result->num_rows > 0) {
        $peak_hours = [];
        while ($hour = $peak_result->fetch_assoc()) {
            $peak_hours[] = $hour;
        }
        
        if (!empty($peak_hours)) {
            $busiest_hour = $peak_hours[0]['hour'];
            $busiest_count = $peak_hours[0]['visit_count'];
            
            $alerts[] = [
                'id' => 'peak_hours_analysis',
                'title' => 'Peak Hours Analysis',
                'message' => "Busiest hour is " . sprintf('%02d:00', $busiest_hour) . " with $busiest_count visits. Consider staff scheduling optimization.",
                'type' => 'info',
                'priority' => 'low'
            ];
        }
    }
    
    // Check for equipment utilization
    $equipment_utilization_sql = "SELECT 
                                    e.name,
                                    e.status,
                                    CASE 
                                        WHEN e.status = 'Available' THEN 'Underutilized'
                                        WHEN e.status = 'In Use' THEN 'High Demand'
                                        WHEN e.status = 'Maintenance' THEN 'Needs Attention'
                                        ELSE 'Unknown'
                                    END as utilization_status
                                 FROM equipment e 
                                 ORDER BY e.status";
    $equipment_result = $conn->query($equipment_utilization_sql);
    
    if ($equipment_result && $equipment_result->num_rows > 0) {
        $maintenance_count = 0;
        $available_count = 0;
        
        while ($equipment = $equipment_result->fetch_assoc()) {
            if ($equipment['status'] === 'Maintenance') {
                $maintenance_count++;
            } elseif ($equipment['status'] === 'Available') {
                $available_count++;
            }
        }
        
        if ($maintenance_count > 2) {
            $alerts[] = [
                'id' => 'equipment_maintenance_heavy',
                'title' => 'Heavy Equipment Maintenance',
                'message' => "$maintenance_count equipment items under maintenance. Consider preventive maintenance schedule.",
                'type' => 'warning',
                'priority' => 'medium'
            ];
        }
        
        if ($available_count > 5) {
            $alerts[] = [
                'id' => 'equipment_underutilized',
                'title' => 'Equipment Underutilization',
                'message' => "$available_count equipment items available. Consider member engagement or equipment variety.",
                'type' => 'info',
                'priority' => 'low'
            ];
        }
    }
    
    return $alerts;
}

$conn->close();
?>
