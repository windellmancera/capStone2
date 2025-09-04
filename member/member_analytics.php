<?php
/**
 * Member Analytics Class
 * Provides analytics and insights for member data
 */

class MemberAnalytics {
    private $conn;
    private $user_id;
    
    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
    }
    
    /**
     * Calculate engagement score for the member
     */
    public function calculateEngagementScore() {
        $score = 0;
        $factors = [];
        
        // Factor 1: Attendance frequency (30 days)
        $attendance_sql = "SELECT COUNT(*) as visit_count 
                          FROM attendance 
                          WHERE user_id = ? 
                          AND check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($attendance_sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attendance = $result->fetch_assoc();
        $visit_count = $attendance['visit_count'];
        
        if ($visit_count >= 15) {
            $score += 30;
            $factors[] = "Excellent attendance (15+ visits/month)";
        } elseif ($visit_count >= 10) {
            $score += 25;
            $factors[] = "Good attendance (10-14 visits/month)";
        } elseif ($visit_count >= 5) {
            $score += 15;
            $factors[] = "Moderate attendance (5-9 visits/month)";
        } else {
            $factors[] = "Low attendance (less than 5 visits/month)";
        }
        
        // Factor 2: Payment reliability
        $payment_sql = "SELECT COUNT(*) as total_payments, 
                               SUM(CASE WHEN payment_status = 'Completed' THEN 1 ELSE 0 END) as completed_payments
                        FROM payment_history 
                        WHERE user_id = ?";
        $stmt = $this->conn->prepare($payment_sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payments = $result->fetch_assoc();
        
        if ($payments['total_payments'] > 0) {
            $reliability = ($payments['completed_payments'] / $payments['total_payments']) * 100;
            if ($reliability >= 90) {
                $score += 25;
                $factors[] = "Excellent payment reliability";
            } elseif ($reliability >= 75) {
                $score += 20;
                $factors[] = "Good payment reliability";
            } elseif ($reliability >= 50) {
                $score += 10;
                $factors[] = "Moderate payment reliability";
            }
        }
        
        // Factor 3: Class enrollment
        $enrollment_sql = "SELECT COUNT(*) as enrolled_classes 
                          FROM class_enrollments 
                          WHERE user_id = ? 
                          AND enrollment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($enrollment_sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $enrollments = $result->fetch_assoc();
        
        if ($enrollments['enrolled_classes'] >= 3) {
            $score += 20;
            $factors[] = "Active class participation";
        } elseif ($enrollments['enrolled_classes'] >= 1) {
            $score += 10;
            $factors[] = "Some class participation";
        }
        
        // Factor 4: Equipment usage
        $equipment_sql = "SELECT COUNT(*) as equipment_usage 
                         FROM equipment_usage 
                         WHERE user_id = ? 
                         AND usage_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($equipment_sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $equipment = $result->fetch_assoc();
        
        if ($equipment['equipment_usage'] >= 10) {
            $score += 15;
            $factors[] = "High equipment utilization";
        } elseif ($equipment['equipment_usage'] >= 5) {
            $score += 10;
            $factors[] = "Moderate equipment utilization";
        }
        
        // Factor 5: Feedback participation
        $feedback_sql = "SELECT COUNT(*) as feedback_count 
                        FROM feedback 
                        WHERE user_id = ? 
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $this->conn->prepare($feedback_sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $feedback = $result->fetch_assoc();
        
        if ($feedback['feedback_count'] >= 2) {
            $score += 10;
            $factors[] = "Active feedback participation";
        } elseif ($feedback['feedback_count'] >= 1) {
            $score += 5;
            $factors[] = "Some feedback participation";
        }
        
        return [
            'score' => min($score, 100),
            'factors' => $factors,
            'attendance_count' => $visit_count,
            'payment_reliability' => $payments['total_payments'] > 0 ? ($payments['completed_payments'] / $payments['total_payments']) * 100 : 0,
            'class_enrollments' => $enrollments['enrolled_classes'],
            'equipment_usage' => $equipment['equipment_usage'],
            'feedback_count' => $feedback['feedback_count']
        ];
    }
    
    /**
     * Get equipment usage data
     */
    public function getEquipmentUsage() {
        $sql = "SELECT eu.*, e.name as equipment_name, ec.name as category_name
                FROM equipment_usage eu
                JOIN equipment e ON eu.equipment_id = e.id
                LEFT JOIN equipment_categories ec ON e.category_id = ec.id
                WHERE eu.user_id = ?
                ORDER BY eu.usage_date DESC
                LIMIT 10";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $usage = [];
        while ($row = $result->fetch_assoc()) {
            $usage[] = $row;
        }
        
        return $usage;
    }
    
    /**
     * Get training sessions data
     */
    public function getTrainingSessions() {
        $sql = "SELECT ts.*, t.name as trainer_name, t.specialization
                FROM training_sessions ts
                JOIN trainers t ON ts.trainer_id = t.id
                WHERE ts.user_id = ?
                ORDER BY ts.session_date DESC
                LIMIT 10";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $row;
        }
        
        return $sessions;
    }
    
    /**
     * Get feedback data
     */
    public function getFeedback() {
        $sql = "SELECT * FROM feedback 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $feedback = [];
        while ($row = $result->fetch_assoc()) {
            $feedback[] = $row;
        }
        
        return $feedback;
    }
    
    /**
     * Get attendance patterns
     */
    public function getAttendancePatterns() {
        $sql = "SELECT 
                    DAYNAME(check_in_time) as day_name,
                    HOUR(check_in_time) as hour,
                    COUNT(*) as visit_count
                FROM attendance 
                WHERE user_id = ? 
                AND check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DAYNAME(check_in_time), HOUR(check_in_time)
                ORDER BY visit_count DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $patterns = [];
        while ($row = $result->fetch_assoc()) {
            $patterns[] = $row;
        }
        
        return $patterns;
    }
    
    /**
     * Get monthly progress
     */
    public function getMonthlyProgress() {
        $sql = "SELECT 
                    DATE_FORMAT(check_in_time, '%Y-%m') as month,
                    COUNT(*) as visits
                FROM attendance 
                WHERE user_id = ? 
                AND check_in_time >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(check_in_time, '%Y-%m')
                ORDER BY month DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        
        return $progress;
    }
}
?> 