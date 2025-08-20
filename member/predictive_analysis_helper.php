<?php
/**
 * Predictive Analysis Helper
 * Provides predictive analytics and recommendations for members
 */

class MemberPredictiveAnalysis {
    private $conn;
    private $user_id;
    
    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
    }
    
    /**
     * Get comprehensive member analytics
     */
    public function getMemberAnalytics() {
        $analytics = [];
        
        // Get attendance data
        $attendance_sql = "SELECT 
                            COUNT(*) as total_visits,
                            COUNT(DISTINCT DATE(check_in_time)) as unique_days
                          FROM attendance 
                          WHERE user_id = ? 
                          AND check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->conn->prepare($attendance_sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $attendance_result = $stmt->get_result();
        $attendance_data = $attendance_result->fetch_assoc();
        
        // Calculate engagement level
        $engagement_score = $this->calculateEngagementScore($attendance_data);
        $analytics['engagement_level'] = [
            'score' => $engagement_score,
            'level' => $this->getEngagementLevel($engagement_score)
        ];
        
        // Activity level based on visits
        $analytics['activity_level'] = $this->getActivityLevel($attendance_data['total_visits']);
        
        // Attendance patterns
        $analytics['attendance_patterns'] = [
            'total_visits' => $attendance_data['total_visits'],
            'avg_duration' => 60, // Default to 60 minutes since we don't track duration
            'preferred_days' => $this->getPreferredDays(),
            'preferred_hours' => $this->getPreferredHours()
        ];
        
        // Payment behavior
        $analytics['payment_behavior'] = $this->getPaymentBehavior();
        
        // Consistency score
        $analytics['consistency_score'] = $this->calculateConsistencyScore($attendance_data);
        
        return $analytics;
    }
    
    /**
     * Calculate engagement score
     */
    private function calculateEngagementScore($attendance_data) {
        $score = 0;
        $visits = $attendance_data['total_visits'];
        
        if ($visits >= 15) {
            $score = 90;
        } elseif ($visits >= 10) {
            $score = 75;
        } elseif ($visits >= 5) {
            $score = 60;
        } elseif ($visits >= 2) {
            $score = 40;
        } else {
            $score = 20;
        }
        
        return $score;
    }
    
    /**
     * Get engagement level description
     */
    private function getEngagementLevel($score) {
        if ($score >= 80) {
            return 'Highly Engaged';
        } elseif ($score >= 60) {
            return 'Engaged';
        } elseif ($score >= 40) {
            return 'Moderately Engaged';
        } else {
            return 'Low Engagement';
        }
    }
    
    /**
     * Get activity level
     */
    private function getActivityLevel($visits) {
        if ($visits >= 15) {
            return 'Very Active';
        } elseif ($visits >= 10) {
            return 'Active';
        } elseif ($visits >= 5) {
            return 'Moderate';
        } elseif ($visits >= 2) {
            return 'Light';
        } else {
            return 'Inactive';
        }
    }
    
    /**
     * Get preferred days
     */
    private function getPreferredDays() {
        $sql = "SELECT 
                    DAYNAME(check_in_time) as day_name,
                    COUNT(*) as visit_count
                FROM attendance 
                WHERE user_id = ? 
                AND check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DAYNAME(check_in_time)
                ORDER BY visit_count DESC
                LIMIT 3";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $days = [];
        while ($row = $result->fetch_assoc()) {
            $days[] = $row['day_name'];
        }
        
        return $days;
    }
    
    /**
     * Get preferred hours
     */
    private function getPreferredHours() {
        $sql = "SELECT 
                    HOUR(check_in_time) as hour,
                    COUNT(*) as visit_count
                FROM attendance 
                WHERE user_id = ? 
                AND check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY HOUR(check_in_time)
                ORDER BY visit_count DESC
                LIMIT 3";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $hours = [];
        while ($row = $result->fetch_assoc()) {
            $hours[] = $row['hour'] . ':00';
        }
        
        return $hours;
    }
    
    /**
     * Get payment behavior
     */
    private function getPaymentBehavior() {
        $sql = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(CASE WHEN payment_status = 'Completed' THEN 1 ELSE 0 END) as completed_payments,
                    AVG(amount) as avg_payment_amount
                FROM payment_history 
                WHERE user_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment_data = $result->fetch_assoc();
        
        $reliability = $payment_data['total_payments'] > 0 
            ? ($payment_data['completed_payments'] / $payment_data['total_payments']) * 100 
            : 0;
        
        return [
            'total_payments' => $payment_data['total_payments'],
            'completed_payments' => $payment_data['completed_payments'],
            'payment_reliability' => round($reliability, 2),
            'avg_payment_amount' => round($payment_data['avg_payment_amount'] ?? 0, 2)
        ];
    }
    
    /**
     * Calculate consistency score
     */
    private function calculateConsistencyScore($attendance_data) {
        $visits = $attendance_data['total_visits'];
        $unique_days = $attendance_data['unique_days'];
        
        if ($visits == 0) {
            return 0;
        }
        
        // Consistency based on regular visits
        $consistency = ($unique_days / 30) * 100;
        
        // Bonus for multiple visits per day
        if ($visits > $unique_days) {
            $consistency += 10;
        }
        
        return min($consistency, 100);
    }
    
    /**
     * Get plan recommendations
     */
    public function getPlanRecommendations() {
        $analytics = $this->getMemberAnalytics();
        $recommendations = [];
        
        // Get available plans
        $plans_sql = "SELECT * FROM membership_plans ORDER BY price ASC";
        $plans_result = $this->conn->query($plans_sql);
        
        while ($plan = $plans_result->fetch_assoc()) {
            $score = 0;
            $reasons = [];
            
            // Score based on activity level
            $activity_level = $analytics['activity_level'];
            if ($activity_level == 'Very Active' && $plan['duration'] >= 365) {
                $score += 30;
                $reasons[] = "High activity level - maximize value with annual plan";
            } elseif ($activity_level == 'Active' && $plan['duration'] >= 30) {
                $score += 25;
                $reasons[] = "Good activity level - monthly/annual plans provide value";
            } elseif ($activity_level == 'Light' && $plan['duration'] <= 30) {
                $score += 20;
                $reasons[] = "Light activity - shorter plans are cost-effective";
            }
            
            // Score based on payment reliability
            $payment_reliability = $analytics['payment_behavior']['payment_reliability'];
            if ($payment_reliability >= 90) {
                $score += 20;
                $reasons[] = "Excellent payment history - can commit to longer plans";
            } elseif ($payment_reliability >= 75) {
                $score += 15;
                $reasons[] = "Good payment history";
            }
            
            // Score based on consistency
            $consistency = $analytics['consistency_score'];
            if ($consistency >= 80) {
                $score += 25;
                $reasons[] = "High consistency - long-term plans are beneficial";
            } elseif ($consistency >= 60) {
                $score += 15;
                $reasons[] = "Moderate consistency";
            }
            
            // Cost-benefit analysis
            $daily_cost = $plan['price'] / $plan['duration'];
            if ($daily_cost <= 50) {
                $score += 15;
                $reasons[] = "Excellent value for money";
            } elseif ($daily_cost <= 100) {
                $score += 10;
                $reasons[] = "Good value for money";
            }
            
            $recommendations[] = [
                'plan' => $plan,
                'score' => $score,
                'reasons' => $reasons,
                'daily_cost' => round($daily_cost, 2)
            ];
        }
        
        // Sort by score (highest first)
        usort($recommendations, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        return array_slice($recommendations, 0, 3); // Return top 3 recommendations
    }
    
    /**
     * Get fitness goals recommendations
     */
    public function getFitnessGoalsRecommendations() {
        $analytics = $this->getMemberAnalytics();
        $goals = [];

        // Get member's current fitness data
        $sql = "SELECT height, weight, target_weight, fitness_goal 
                FROM users 
                WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $fitness_data = $result->fetch_assoc();

        if ($fitness_data) {
            // Calculate BMI if height and weight are available
            if ($fitness_data['height'] && $fitness_data['weight']) {
                $height_m = $fitness_data['height'] / 100; // Convert cm to m
                $bmi = $fitness_data['weight'] / ($height_m * $height_m);
                $goals['bmi'] = [
                    'current' => round($bmi, 1),
                    'category' => $this->getBMICategory($bmi),
                    'recommendations' => $this->getBMIRecommendations($bmi)
                ];
            }

            // Weight goals analysis
            if ($fitness_data['weight'] && $fitness_data['target_weight']) {
                $weight_diff = $fitness_data['target_weight'] - $fitness_data['weight'];
                $goals['weight'] = [
                    'current' => $fitness_data['weight'],
                    'target' => $fitness_data['target_weight'],
                    'difference' => $weight_diff,
                    'recommendations' => $this->getWeightGoalRecommendations($weight_diff)
                ];
            }

            // Fitness goal specific recommendations
            if ($fitness_data['fitness_goal']) {
                $goals['fitness'] = [
                    'current_goal' => $fitness_data['fitness_goal'],
                    'recommendations' => $this->getFitnessSpecificRecommendations($fitness_data['fitness_goal'], $analytics)
                ];
            }
        }

        return $goals;
    }

    /**
     * Get workout recommendations based on decision tree
     */
    public function getWorkoutRecommendations() {
        $analytics = $this->getMemberAnalytics();
        $goals = $this->getFitnessGoalsRecommendations();
        
        // Get member's fitness preferences
        $sql = "SELECT fitness_goal, experience_level, preferred_workout_type 
                FROM users 
                WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $preferences = $result->fetch_assoc();

        $recommendations = [
            'workout_plan' => $this->generateWorkoutPlan($preferences, $analytics, $goals),
            'intensity_level' => $this->recommendIntensityLevel($analytics['activity_level'], $preferences['experience_level']),
            'exercise_types' => $this->recommendExerciseTypes($preferences['fitness_goal'], $goals),
            'schedule' => $this->generateScheduleRecommendation($analytics['attendance_patterns'])
        ];

        return $recommendations;
    }

    /**
     * Get BMI category
     */
    private function getBMICategory($bmi) {
        if ($bmi < 18.5) return 'Underweight';
        if ($bmi < 25) return 'Normal weight';
        if ($bmi < 30) return 'Overweight';
        return 'Obese';
    }

    /**
     * Get BMI recommendations
     */
    private function getBMIRecommendations($bmi) {
        $recommendations = [];
        
        if ($bmi < 18.5) {
            $recommendations[] = "Focus on strength training to build muscle mass";
            $recommendations[] = "Increase caloric intake with nutrient-rich foods";
            $recommendations[] = "Consider protein supplements under guidance";
        } elseif ($bmi < 25) {
            $recommendations[] = "Maintain current fitness routine";
            $recommendations[] = "Focus on strength and endurance balance";
            $recommendations[] = "Consider setting new fitness challenges";
        } elseif ($bmi < 30) {
            $recommendations[] = "Increase cardio exercises";
            $recommendations[] = "Monitor caloric intake";
            $recommendations[] = "Add HIIT workouts to routine";
        } else {
            $recommendations[] = "Start with low-impact exercises";
            $recommendations[] = "Focus on gradual weight loss";
            $recommendations[] = "Consider working with a personal trainer";
        }

        return $recommendations;
    }

    /**
     * Get weight goal recommendations
     */
    private function getWeightGoalRecommendations($weight_diff) {
        $recommendations = [];

        if ($weight_diff > 0) {
            // Need to gain weight
            $recommendations[] = "Increase protein and healthy calorie intake";
            $recommendations[] = "Focus on compound exercises for muscle gain";
            $recommendations[] = "Consider mass gainer supplements";
        } elseif ($weight_diff < 0) {
            // Need to lose weight
            $recommendations[] = "Create a caloric deficit through diet and exercise";
            $recommendations[] = "Incorporate more cardio and HIIT workouts";
            $recommendations[] = "Focus on portion control and meal timing";
        } else {
            // Maintain weight
            $recommendations[] = "Balance strength training and cardio";
            $recommendations[] = "Maintain current caloric intake";
            $recommendations[] = "Focus on toning and definition";
        }

        return $recommendations;
    }

    /**
     * Get fitness specific recommendations
     */
    private function getFitnessSpecificRecommendations($goal, $analytics) {
        $recommendations = [];

        switch ($goal) {
            case 'muscle_gain':
                $recommendations[] = "Progressive overload in strength training";
                $recommendations[] = "Focus on compound exercises";
                $recommendations[] = "Ensure adequate protein intake";
                break;
            case 'weight_loss':
                $recommendations[] = "Mix cardio with strength training";
                $recommendations[] = "Create caloric deficit";
                $recommendations[] = "High-intensity interval training";
                break;
            case 'endurance':
                $recommendations[] = "Gradually increase workout duration";
                $recommendations[] = "Focus on cardiovascular exercises";
                $recommendations[] = "Include recovery workouts";
                break;
            case 'flexibility':
                $recommendations[] = "Regular stretching routines";
                $recommendations[] = "Consider yoga or Pilates";
                $recommendations[] = "Focus on mobility exercises";
                break;
            default:
                $recommendations[] = "Set specific fitness goals";
                $recommendations[] = "Maintain consistent workout schedule";
                $recommendations[] = "Track progress regularly";
        }

        // Add engagement-based recommendations
        if ($analytics['engagement_level']['score'] < 40) {
            $recommendations[] = "Start with shorter, more frequent workouts";
            $recommendations[] = "Set achievable short-term goals";
        }

        return $recommendations;
    }

    /**
     * Generate workout plan based on decision tree
     */
    private function generateWorkoutPlan($preferences, $analytics, $goals) {
        $plan = [];
        
        // Base workout frequency on activity level
        switch ($analytics['activity_level']) {
            case 'Very Active':
                $plan['frequency'] = '5-6 days per week';
                break;
            case 'Active':
                $plan['frequency'] = '4-5 days per week';
                break;
            case 'Moderate':
                $plan['frequency'] = '3-4 days per week';
                break;
            default:
                $plan['frequency'] = '2-3 days per week';
        }

        // Workout duration based on experience
        switch ($preferences['experience_level']) {
            case 'beginner':
                $plan['duration'] = '30-45 minutes';
                break;
            case 'intermediate':
                $plan['duration'] = '45-60 minutes';
                break;
            case 'advanced':
                $plan['duration'] = '60-90 minutes';
                break;
            default:
                $plan['duration'] = '45-60 minutes';
        }

        // Workout split based on goals and preferences
        $plan['split'] = $this->recommendWorkoutSplit(
            $preferences['fitness_goal'],
            $preferences['experience_level'],
            $analytics['activity_level']
        );

        return $plan;
    }

    /**
     * Recommend workout split
     */
    private function recommendWorkoutSplit($goal, $experience, $activity_level) {
        if ($experience === 'beginner') {
            return [
                'type' => 'full_body',
                'schedule' => [
                    'Day 1' => 'Full Body Workout A',
                    'Day 2' => 'Rest',
                    'Day 3' => 'Full Body Workout B',
                    'Day 4' => 'Rest'
                ]
            ];
        }

        if ($goal === 'muscle_gain' && $experience === 'advanced') {
            return [
                'type' => 'body_part_split',
                'schedule' => [
                    'Day 1' => 'Chest and Triceps',
                    'Day 2' => 'Back and Biceps',
                    'Day 3' => 'Rest',
                    'Day 4' => 'Legs and Core',
                    'Day 5' => 'Shoulders and Arms',
                    'Day 6' => 'Rest'
                ]
            ];
        }

        return [
            'type' => 'upper_lower_split',
            'schedule' => [
                'Day 1' => 'Upper Body',
                'Day 2' => 'Lower Body',
                'Day 3' => 'Rest',
                'Day 4' => 'Upper Body',
                'Day 5' => 'Lower Body',
                'Day 6' => 'Rest'
            ]
        ];
    }

    /**
     * Recommend intensity level
     */
    private function recommendIntensityLevel($activity_level, $experience_level) {
        if ($experience_level === 'beginner') {
            return [
                'intensity' => 'Low to Moderate',
                'heart_rate_zone' => '50-70% of max',
                'rest_periods' => '60-90 seconds'
            ];
        }

        if ($activity_level === 'Very Active' && $experience_level === 'advanced') {
            return [
                'intensity' => 'High',
                'heart_rate_zone' => '70-85% of max',
                'rest_periods' => '30-60 seconds'
            ];
        }

        return [
            'intensity' => 'Moderate',
            'heart_rate_zone' => '60-75% of max',
            'rest_periods' => '45-75 seconds'
        ];
    }

    /**
     * Recommend exercise types
     */
    private function recommendExerciseTypes($goal, $goals_data) {
        $types = [
            'primary' => [],
            'secondary' => [],
            'supplementary' => []
        ];

        switch ($goal) {
            case 'muscle_gain':
                $types['primary'] = ['Compound lifts', 'Progressive overload training'];
                $types['secondary'] = ['Isolation exercises', 'Drop sets'];
                $types['supplementary'] = ['Light cardio', 'Mobility work'];
                break;
            case 'weight_loss':
                $types['primary'] = ['HIIT', 'Circuit training'];
                $types['secondary'] = ['Strength training', 'Steady-state cardio'];
                $types['supplementary'] = ['Core work', 'Flexibility training'];
                break;
            case 'endurance':
                $types['primary'] = ['Long-duration cardio', 'Interval training'];
                $types['secondary'] = ['Circuit training', 'Bodyweight exercises'];
                $types['supplementary'] = ['Strength maintenance', 'Recovery sessions'];
                break;
            default:
                $types['primary'] = ['Balanced strength training', 'Moderate cardio'];
                $types['secondary'] = ['Functional training', 'Core work'];
                $types['supplementary'] = ['Flexibility work', 'Balance training'];
        }

        return $types;
    }

    /**
     * Generate schedule recommendation
     */
    private function generateScheduleRecommendation($attendance_patterns) {
        return [
            'preferred_days' => $attendance_patterns['preferred_days'],
            'preferred_times' => $attendance_patterns['preferred_hours'],
            'recommended_frequency' => $this->recommendFrequency($attendance_patterns['total_visits']),
            'session_duration' => $this->recommendSessionDuration($attendance_patterns['avg_duration'])
        ];
    }

    /**
     * Recommend workout frequency
     */
    private function recommendFrequency($total_visits) {
        if ($total_visits >= 15) {
            return '5-6 sessions per week';
        } elseif ($total_visits >= 10) {
            return '4-5 sessions per week';
        } elseif ($total_visits >= 5) {
            return '3-4 sessions per week';
        } else {
            return '2-3 sessions per week';
        }
    }

    /**
     * Recommend session duration
     */
    private function recommendSessionDuration($avg_duration) {
        if ($avg_duration >= 90) {
            return '60-90 minutes';
        } elseif ($avg_duration >= 60) {
            return '45-60 minutes';
        } else {
            return '30-45 minutes';
        }
    }
}
?> 