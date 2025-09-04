<?php
require_once '../db.php';

function getTopMembershipPlan($conn) {
    try {
        // First try to get plans with active subscribers
        $sql = "SELECT 
            mp.id,
            mp.name as plan_name,
            mp.description,
            mp.price,
            mp.duration,
            COUNT(u.id) as active_subscribers
        FROM 
            membership_plans mp
        LEFT JOIN 
            users u ON mp.id = u.selected_plan_id 
            AND u.role = 'member'
            AND u.membership_end_date > CURDATE()
        GROUP BY 
            mp.id, mp.name, mp.description, mp.price, mp.duration
        ORDER BY 
            active_subscribers DESC 
        LIMIT 1";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            if ($data['active_subscribers'] > 0) {
                return $data;
            }
        }
        
        // If no active subscribers, get the most popular plan by total users
        $sql2 = "SELECT 
            mp.id,
            mp.name as plan_name,
            mp.description,
            mp.price,
            mp.duration,
            COUNT(u.id) as active_subscribers
        FROM 
            membership_plans mp
        LEFT JOIN 
            users u ON mp.id = u.selected_plan_id 
            AND u.role = 'member'
        GROUP BY 
            mp.id, mp.name, mp.description, mp.price, mp.duration
        ORDER BY 
            active_subscribers DESC 
        LIMIT 1";
        $result2 = $conn->query($sql2);
        if ($result2 && $result2->num_rows > 0) {
            return $result2->fetch_assoc();
        }
        
        // If still no results, get any available plan
        $sql3 = "SELECT 
            mp.id,
            mp.name as plan_name,
            mp.description,
            mp.price,
            mp.duration,
            0 as active_subscribers
        FROM 
            membership_plans mp
        LIMIT 1";
        $result3 = $conn->query($sql3);
        if ($result3 && $result3->num_rows > 0) {
            return $result3->fetch_assoc();
        }
        
    } catch (Exception $e) {
        // Log error silently
        error_log("Error in getTopMembershipPlan: " . $e->getMessage());
    }
    // Return default data if query fails or no results
    return [
        'plan_name' => 'Monthly Plan',
        'active_subscribers' => 0,
        'price' => 0,
        'duration' => 0,
        'description' => 'No membership data available'
    ];
}

function getPeakCheckInHours($conn) {
    try {
        $sql = "SELECT 
                HOUR(check_in_time) as hour,
                COUNT(*) as check_ins
            FROM attendance 
            WHERE check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY HOUR(check_in_time)
            ORDER BY check_ins DESC
            LIMIT 1";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            if ($data['check_ins'] > 0) {
                return $data;
            }
        }
        
        // If no attendance data, return a reasonable default peak hour
        return ['hour' => 23, 'check_ins' => 0]; // 11:00 PM as default
    } catch (Exception $e) {
        error_log("Error in getPeakCheckInHours: " . $e->getMessage());
        return ['hour' => 23, 'check_ins' => 0]; // 11:00 PM as default
    }
}

function getTopRatedTrainer($conn) {
    try {
        // First try to get trainers with ratings
        $sql = "SELECT 
            t.id,
            t.name as trainer_name,
            t.specialization,
            ROUND(AVG(f.rating), 2) as average_rating,
            COUNT(f.id) as total_ratings
        FROM 
            trainers t
        LEFT JOIN 
            feedback f ON t.id = f.trainer_id
        GROUP BY 
            t.id, t.name, t.specialization
        HAVING 
            total_ratings > 0
        ORDER BY 
            average_rating DESC, 
            total_ratings DESC 
        LIMIT 1";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        // If no trainers with ratings, get any available trainer
        $sql2 = "SELECT 
            t.id,
            t.name as trainer_name,
            t.specialization,
            0 as average_rating,
            0 as total_ratings
        FROM 
            trainers t
        LIMIT 1";
        $result2 = $conn->query($sql2);
        if ($result2 && $result2->num_rows > 0) {
            return $result2->fetch_assoc();
        }
        
    } catch (Exception $e) {
        error_log("Error in getTopRatedTrainer: " . $e->getMessage());
    }
    // Return default data
    return [
        'trainer_name' => 'No trainers available',
        'specialization' => 'N/A',
        'average_rating' => 0,
        'total_ratings' => 0
    ];
}

function getUpcomingExpirations($conn) {
    try {
        $sql = "SELECT u.id, u.username, u.email, u.membership_end_date, mp.name as plan_name
                FROM users u
                LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                WHERE u.role = 'member'
                AND u.membership_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                ORDER BY u.membership_end_date ASC";
        $result = $conn->query($sql);
        if ($result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Error in getUpcomingExpirations: " . $e->getMessage());
    }
    // Return empty array if query fails
    return [];
}

function getEquipmentUsageTrends($conn) {
    try {
        // First try to get equipment with recent views
        $sql = "SELECT e.name, e.quantity, 
                   COUNT(ev.id) as view_count,
                   e.quantity - IFNULL(
                       (SELECT COUNT(*) 
                        FROM equipment_maintenance em 
                        WHERE em.equipment_id = e.id 
                        AND em.status = 'Under Maintenance'), 0
                   ) as available_quantity
            FROM equipment e
            LEFT JOIN equipment_views ev ON e.id = ev.equipment_id
            WHERE ev.view_timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY e.id, e.name, e.quantity
            ORDER BY view_count DESC
            LIMIT 5";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        
        // If no recent views, get all equipment with 0 views
        $sql2 = "SELECT e.name, e.quantity, 
                   0 as view_count,
                   e.quantity - IFNULL(
                       (SELECT COUNT(*) 
                        FROM equipment_maintenance em 
                        WHERE em.equipment_id = e.id 
                        AND em.status = 'Under Maintenance'), 0
                   ) as available_quantity
            FROM equipment e
            GROUP BY e.id, e.name, e.quantity
            ORDER BY e.name ASC
            LIMIT 5";
        $result2 = $conn->query($sql2);
        if ($result2 && $result2->num_rows > 0) {
            return $result2->fetch_all(MYSQLI_ASSOC);
        }
        
    } catch (Exception $e) {
        error_log("Error in getEquipmentUsageTrends: " . $e->getMessage());
    }
    // Return empty array if query fails
    return [];
}

function getMemberGrowth($conn) {
    try {
        $sql = "SELECT 
                COUNT(CASE WHEN created_at >= DATE_FORMAT(NOW() ,'%Y-%m-01') THEN 1 END) as this_month,
                COUNT(CASE WHEN created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH) ,'%Y-%m-01')
                          AND created_at < DATE_FORMAT(NOW() ,'%Y-%m-01') THEN 1 END) as last_month
            FROM users
            WHERE role = 'member'
            AND created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH) ,'%Y-%m-01')";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            // Ensure we have numeric values
            $data['this_month'] = (int)$data['this_month'];
            $data['last_month'] = (int)$data['last_month'];
            return $data;
        }
    } catch (Exception $e) {
        error_log("Error in getMemberGrowth: " . $e->getMessage());
    }
    // Return default data
    return ['this_month' => 0, 'last_month' => 0];
}

function getRecentActivitySummary($conn) {
    try {
        // Today's check-ins
        $sql_checkins = "SELECT COUNT(DISTINCT user_id) as today_checkins 
                     FROM attendance 
                     WHERE DATE(check_in_time) = CURDATE()";
        $checkins = $conn->query($sql_checkins);
        $checkins_count = ($checkins && $checkins->num_rows > 0) ? 
                         $checkins->fetch_assoc()['today_checkins'] : 0;

        // Today's approved payments
        $sql_payments = "SELECT COUNT(*) as today_payments 
                    FROM payments 
                    WHERE DATE(payment_date) = CURDATE() 
                    AND status = 'Approved'";
        $payments = $conn->query($sql_payments);
        $payments_count = ($payments && $payments->num_rows > 0) ? 
                         $payments->fetch_assoc()['today_payments'] : 0;

        return [
            'today_checkins' => $checkins_count,
            'today_payments' => $payments_count
        ];
    } catch (Exception $e) {
        error_log("Error in getRecentActivitySummary: " . $e->getMessage());
        return [
            'today_checkins' => 0,
            'today_payments' => 0
        ];
    }
} 