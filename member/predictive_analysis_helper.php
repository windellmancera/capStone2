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

        // Get member's comprehensive fitness data with fallback for missing columns
        $fitness_data = $this->getUserFitnessData();

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

            // Body composition analysis
            if ($fitness_data['body_fat'] && $fitness_data['muscle_mass']) {
                $goals['body_composition'] = [
                    'body_fat' => $fitness_data['body_fat'],
                    'muscle_mass' => $fitness_data['muscle_mass'],
                    'analysis' => $this->getBodyCompositionAnalysis($fitness_data['body_fat'], $fitness_data['muscle_mass'], $fitness_data['height'], $fitness_data['weight']),
                    'recommendations' => $this->getBodyCompositionRecommendations($fitness_data['body_fat'], $fitness_data['muscle_mass'])
                ];
            }

            // Circumference measurements analysis
            if ($fitness_data['waist'] && $fitness_data['hip']) {
                $whr = $fitness_data['waist'] / $fitness_data['hip']; // Waist-to-Hip Ratio
                $goals['measurements'] = [
                    'waist' => $fitness_data['waist'],
                    'hip' => $fitness_data['hip'],
                    'waist_hip_ratio' => round($whr, 2),
                    'health_risk' => $this->getWHRHealthRisk($whr, $fitness_data),
                    'recommendations' => $this->getMeasurementRecommendations($whr, $fitness_data)
                ];
            }

            // Training progress analysis
            if ($fitness_data['training_level']) {
                $goals['training'] = [
                    'current_level' => $fitness_data['training_level'],
                    'frequency' => $fitness_data['training_frequency'],
                    'recommendations' => $this->getTrainingRecommendations($fitness_data['training_level'], $fitness_data['training_frequency'], $analytics),
                    'progression' => $this->getProgressionPlan($fitness_data['training_level'], $fitness_data['experience_level'])
                ];
            }

            // Fitness goal specific recommendations (enhanced with new data)
            if ($fitness_data['fitness_goal']) {
                $goals['fitness'] = [
                    'current_goal' => $fitness_data['fitness_goal'],
                    'recommendations' => $this->getFitnessSpecificRecommendations($fitness_data['fitness_goal'], $analytics, $fitness_data)
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

        // Get member's comprehensive fitness preferences and data safely
        $preferences = $this->getUserFitnessData();

        // Compute BMI category if height and weight are available
        $bmi = null;
        $bmiCategory = null;
        if (!empty($preferences['height']) && !empty($preferences['weight']) && $preferences['height'] > 0) {
            $height_m = $preferences['height'] / 100;
            $bmi = $preferences['weight'] / ($height_m * $height_m);
            $bmiCategory = $this->getBMICategory($bmi);
        }

        $recommendations = [
            'workout_plan' => $this->generateWorkoutPlan($preferences, $analytics, $goals, $bmiCategory),
            'intensity_level' => $this->recommendIntensityLevel($analytics['activity_level'], $preferences['experience_level'], $bmiCategory),
            'exercise_types' => $this->recommendExerciseTypes($preferences['fitness_goal'], $goals, $bmiCategory, $preferences),
            'schedule' => $this->generateScheduleRecommendation($analytics['attendance_patterns']),
            'personalized_tips' => $this->getPersonalizedTips($preferences, $goals, $bmiCategory)
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
     * Generate workout plan based on decision tree
     */
    private function generateWorkoutPlan($preferences, $analytics, $goals, $bmiCategory = null) {
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

        // Adjust frequency based on BMI category (safety-first)
        if ($bmiCategory === 'Obese') {
            $plan['frequency'] = '3-4 days per week';
        } elseif ($bmiCategory === 'Underweight') {
            $plan['frequency'] = '3-4 days per week';
        }

        // Workout duration based on experience, adjusted by BMI
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

        if ($bmiCategory === 'Obese' || $bmiCategory === 'Underweight') {
            $plan['duration'] = '30-45 minutes';
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
                    'Day 1' => 'Full Body Workout A (45 min)',
                    'Day 2' => 'Rest + Light Stretching',
                    'Day 3' => 'Full Body Workout B (45 min)',
                    'Day 4' => 'Rest + Mobility Work',
                    'Day 5' => 'Cardio + Core (30 min)',
                    'Day 6' => 'Rest + Light Walking',
                    'Day 7' => 'Active Recovery'
                ]
            ];
        }

        if ($goal === 'muscle_gain' && $experience === 'advanced') {
            return [
                'type' => 'body_part_split',
                'schedule' => [
                    'Day 1' => 'Chest & Triceps (60 min)',
                    'Day 2' => 'Back & Biceps (60 min)',
                    'Day 3' => 'Rest + Light Cardio',
                    'Day 4' => 'Legs & Core (75 min)',
                    'Day 5' => 'Shoulders & Arms (60 min)',
                    'Day 6' => 'Rest + Stretching',
                    'Day 7' => 'Active Recovery'
                ]
            ];
        }

        if ($goal === 'weight_loss') {
            return [
                'type' => 'circuit_training',
                'schedule' => [
                    'Day 1' => 'HIIT Circuit (45 min)',
                    'Day 2' => 'Strength + Cardio (50 min)',
                    'Day 3' => 'Rest + Light Walking',
                    'Day 4' => 'Full Body Circuit (45 min)',
                    'Day 5' => 'Cardio Focus (40 min)',
                    'Day 6' => 'Rest + Stretching',
                    'Day 7' => 'Active Recovery'
                ]
            ];
        }

        if ($goal === 'endurance') {
            return [
                'type' => 'endurance_focused',
                'schedule' => [
                    'Day 1' => 'Long Cardio (60 min)',
                    'Day 2' => 'Interval Training (45 min)',
                    'Day 3' => 'Strength Maintenance (40 min)',
                    'Day 4' => 'Steady State Cardio (50 min)',
                    'Day 5' => 'Circuit Training (45 min)',
                    'Day 6' => 'Rest + Light Stretching',
                    'Day 7' => 'Active Recovery'
                ]
            ];
        }

        return [
            'type' => 'upper_lower_split',
            'schedule' => [
                'Day 1' => 'Upper Body Strength (55 min)',
                'Day 2' => 'Lower Body Strength (55 min)',
                'Day 3' => 'Rest + Light Cardio',
                'Day 4' => 'Upper Body Hypertrophy (50 min)',
                'Day 5' => 'Lower Body Power (50 min)',
                'Day 6' => 'Rest + Stretching',
                'Day 7' => 'Active Recovery'
            ]
        ];
    }

    /**
     * Recommend intensity level
     */
    private function recommendIntensityLevel($activity_level, $experience_level, $bmiCategory = null) {
        // Base recommendation by experience and activity
        $recommendation = [
            'intensity' => 'Moderate',
            'heart_rate_zone' => '60-75% of max HR (120-150 BPM)',
            'rest_periods' => '45-75 seconds between sets',
            'workout_pace' => 'Steady, controlled movements',
            'recovery_time' => '48-72 hours between muscle groups'
        ];

        if ($experience_level === 'beginner') {
            $recommendation = [
                'intensity' => 'Low to Moderate',
                'heart_rate_zone' => '50-70% of max HR (100-140 BPM)',
                'rest_periods' => '60-90 seconds between sets',
                'workout_pace' => 'Slow, controlled movements',
                'recovery_time' => '72-96 hours between muscle groups'
            ];
        } elseif ($activity_level === 'Very Active' && $experience_level === 'advanced') {
            $recommendation = [
                'intensity' => 'High',
                'heart_rate_zone' => '70-85% of max HR (140-170 BPM)',
                'rest_periods' => '30-60 seconds between sets',
                'workout_pace' => 'Explosive, controlled movements',
                'recovery_time' => '24-48 hours between muscle groups'
            ];
        }

        // BMI-based safety adjustments override intensity if necessary
        if ($bmiCategory === 'Obese') {
            $recommendation = [
                'intensity' => 'Low to Moderate',
                'heart_rate_zone' => '50-65% of max HR (100-130 BPM)',
                'rest_periods' => '60-90 seconds between sets',
                'workout_pace' => 'Slow, controlled movements',
                'recovery_time' => '72-96 hours between muscle groups'
            ];
        } elseif ($bmiCategory === 'Underweight') {
            $recommendation = [
                'intensity' => 'Low to Moderate',
                'heart_rate_zone' => '55-70% of max',
                'rest_periods' => '60-90 seconds'
            ];
        }

        return $recommendation;
    }

    /**
     * Recommend exercise types
     */
    private function recommendExerciseTypes($goal, $goals_data, $bmiCategory = null, $preferences = null) {
        $types = [
            'primary' => [],
            'secondary' => [],
            'supplementary' => []
        ];

        switch ($goal) {
            case 'muscle_gain':
                $types['primary'] = [
                    'Squats (3-4 sets, 8-12 reps)',
                    'Deadlifts (3-4 sets, 6-8 reps)',
                    'Bench Press (3-4 sets, 8-12 reps)',
                    'Overhead Press (3-4 sets, 8-12 reps)',
                    'Barbell Rows (3-4 sets, 8-12 reps)'
                ];
                $types['secondary'] = [
                    'Bicep Curls (3 sets, 10-15 reps)',
                    'Tricep Dips (3 sets, 8-12 reps)',
                    'Lateral Raises (3 sets, 10-15 reps)',
                    'Leg Extensions (3 sets, 10-15 reps)',
                    'Calf Raises (3 sets, 15-20 reps)'
                ];
                $types['supplementary'] = [
                    'Light Walking (10-15 min warm-up)',
                    'Dynamic Stretching (5-10 min)',
                    'Foam Rolling (5-10 min recovery)'
                ];
                break;
                
            case 'weight_loss':
                $types['primary'] = [
                    'Burpees (30 seconds, 3 rounds)',
                    'Mountain Climbers (45 seconds, 3 rounds)',
                    'Jump Squats (30 seconds, 3 rounds)',
                    'High Knees (45 seconds, 3 rounds)',
                    'Plank Jacks (30 seconds, 3 rounds)'
                ];
                $types['secondary'] = [
                    'Push-ups (3 sets, max reps)',
                    'Bodyweight Squats (3 sets, 15-20 reps)',
                    'Lunges (3 sets, 10 each leg)',
                    'Plank (3 sets, 30-60 seconds)',
                    'Bicycle Crunches (3 sets, 15 each side)'
                ];
                $types['supplementary'] = [
                    'Core Planks (3 sets, 30-60 seconds)',
                    'Cat-Cow Stretches (10 reps)',
                    'Child\'s Pose (30 seconds hold)'
                ];
                break;
                
            case 'endurance':
                $types['primary'] = [
                    'Running Intervals (30s sprint, 90s walk, 10 rounds)',
                    'Cycling (20-30 min moderate pace)',
                    'Rowing Machine (15-20 min steady state)',
                    'Elliptical (25-30 min varying resistance)',
                    'Swimming (20-30 min continuous)'
                ];
                $types['secondary'] = [
                    'Circuit Training (5 exercises, 3 rounds)',
                    'Bodyweight Squats (4 sets, 20 reps)',
                    'Push-ups (4 sets, 15-20 reps)',
                    'Lunges (4 sets, 15 each leg)',
                    'Mountain Climbers (4 sets, 30 seconds)'
                ];
                $types['supplementary'] = [
                    'Light Strength Maintenance (2-3 sets)',
                    'Active Recovery (walking, light stretching)',
                    'Mobility Work (hip, shoulder, ankle)'
                ];
                break;
                
            default:
                $types['primary'] = [
                    'Full Body Circuit (5 exercises, 3 rounds)',
                    'Moderate Cardio (20-25 min)',
                    'Compound Movements (squats, push-ups, rows)'
                ];
                $types['secondary'] = [
                    'Functional Training (lunges, planks, bridges)',
                    'Core Work (planks, crunches, leg raises)',
                    'Balance Exercises (single-leg stands, heel-to-toe)'
                ];
                $types['supplementary'] = [
                    'Flexibility Work (static stretching, 10-15 min)',
                    'Balance Training (yoga poses, stability work)',
                    'Recovery (foam rolling, light stretching)'
                ];
        }

        // Adjust exercise types based on BMI category for safety
        if ($bmiCategory === 'Obese') {
            $types['primary'] = [
                'Walking (20-30 min, moderate pace)',
                'Stationary Cycling (15-20 min, low resistance)',
                'Seated Exercises (arm circles, leg lifts)',
                'Wall Push-ups (3 sets, 8-12 reps)',
                'Chair Squats (3 sets, 10-15 reps)'
            ];
            $types['secondary'] = [
                'Machine Strength Training (3 sets, 10-15 reps)',
                'Aquatic Exercises (water walking, pool aerobics)',
                'Resistance Band Work (seated exercises)',
                'Light Dumbbell Work (2-3 sets, 8-12 reps)',
                'Stability Ball Exercises (core work)'
            ];
            $types['supplementary'] = [
                'Mobility Work (gentle stretching)',
                'Core Stability (planks on knees, bird dogs)',
                'Balance Training (standing with support)'
            ];
        } elseif ($bmiCategory === 'Overweight') {
            $types['primary'] = [
                'Steady-State Walking (25-30 min)',
                'Low-Impact Cardio (elliptical, cycling)',
                'Full-Body Strength (machines, 3 sets, 10-15 reps)',
                'Bodyweight Circuits (5 exercises, 2-3 rounds)',
                'Swimming (20-25 min, moderate pace)'
            ];
            $types['secondary'] = [
                'Low-Impact Intervals (walking/jogging)',
                'Bodyweight Circuits (squats, push-ups, rows)',
                'Resistance Training (dumbbells, 3 sets, 10-15 reps)',
                'Core Work (planks, crunches, 3 sets)',
                'Flexibility Training (dynamic stretching)'
            ];
            $types['supplementary'] = [
                'Flexibility Training (15-20 min stretching)',
                'Mobility Work (hip, shoulder, ankle)',
                'Recovery (foam rolling, light massage)'
            ];
        } elseif ($bmiCategory === 'Underweight') {
            $types['primary'] = [
                'Strength Training (hypertrophy focus, 4-5 sets)',
                'Compound Lifts (squats, deadlifts, 3-4 sets, 6-8 reps)',
                'Progressive Overload (increase weight weekly)',
                'Multi-Joint Exercises (bench press, rows, 3-4 sets)',
                'Heavy Lifting (80-85% 1RM, 3-5 reps)'
            ];
            $types['secondary'] = [
                'Isolation Exercises (curls, extensions, 3 sets, 10-15 reps)',
                'Core Work (weighted planks, crunches, 3 sets)',
                'Accessory Movements (lateral raises, face pulls)',
                'Grip Training (farmer\'s walks, dead hangs)',
                'Stability Work (single-leg exercises)'
            ];
            $types['supplementary'] = [
                'Light Cardio (5-10 min warm-up only)',
                'Mobility Work (dynamic stretching)',
                'Recovery (adequate rest between sessions)'
            ];
        }

        // Further customization based on body composition
        if ($preferences && isset($preferences['body_fat']) && isset($preferences['muscle_mass'])) {
            if ($preferences['body_fat'] > 25) {
                // High body fat - prioritize fat loss
                array_unshift($types['primary'], 'High-Intensity Cardio (30s work, 30s rest, 8 rounds)');
                $types['supplementary'][] = 'Extended Cardio Sessions (45-60 min low intensity)';
            }
            
            if ($preferences['muscle_mass'] < 35) {
                // Low muscle mass - prioritize muscle building
                array_unshift($types['primary'], 'Resistance Training (4-5 sets, 6-8 reps)');
                $types['secondary'][] = 'Progressive Strength Training (increase weight weekly)';
            }
        }

        return $types;
    }

    /**
     * Get personalized tips based on comprehensive data
     */
    private function getPersonalizedTips($preferences, $goals, $bmiCategory) {
        $tips = [];
        
        // BMI-based tips
        if ($bmiCategory === 'Underweight') {
            $tips[] = 'Focus on strength training (4-5 sets, 6-8 reps) with increased caloric intake (300-500 calories above maintenance)';
            $tips[] = 'Limit cardio to 10-15 min warm-up only to avoid excessive calorie burn';
            $tips[] = 'Prioritize compound movements: squats, deadlifts, bench press, rows';
        } elseif ($bmiCategory === 'Overweight' || $bmiCategory === 'Obese') {
            $tips[] = 'Combine cardio (30-45 min) with strength training (3 sets, 10-15 reps) for optimal fat loss';
            $tips[] = 'Start with low-impact exercises (walking, cycling, swimming) to protect joints';
            $tips[] = 'Focus on form and control over speed or weight';
        }
        
        // Body composition tips
        if (isset($preferences['body_fat']) && $preferences['body_fat'] > 20) {
            $tips[] = 'Track nutrition: aim for 20-25% protein, 45-50% carbs, 25-30% fats';
            $tips[] = 'HIIT training (30s work, 30s rest, 8 rounds) can be especially effective for fat loss';
            $tips[] = 'Include 2-3 cardio sessions per week (20-30 min each)';
        }
        
        if (isset($preferences['muscle_mass']) && $preferences['muscle_mass'] < 40) {
            $tips[] = 'Prioritize protein intake (1.6-2.2g per kg body weight) with meals every 3-4 hours';
            $tips[] = 'Allow 48-72 hours rest between strength sessions for muscle growth';
            $tips[] = 'Focus on progressive overload: increase weight by 2.5-5% weekly';
        }
        
        // Training level tips
        if (isset($preferences['training_level'])) {
            switch ($preferences['training_level']) {
                case 'beginner':
                    $tips[] = 'Start with 2-3 sessions per week, focus on form over intensity';
                    $tips[] = 'Learn proper form with bodyweight exercises before adding weights';
                    $tips[] = 'Rest 60-90 seconds between sets, 72-96 hours between muscle groups';
                    break;
                case 'intermediate':
                    $tips[] = 'Track progress: aim for 2.5-5% weight increase weekly';
                    $tips[] = 'Consider working with a trainer to refine technique and prevent plateaus';
                    $tips[] = 'Rest 45-75 seconds between sets, 48-72 hours between muscle groups';
                    break;
                case 'advanced':
                    $tips[] = 'Implement periodization: 4-6 week cycles with deload weeks';
                    $tips[] = 'Use advanced techniques: drop sets, supersets, rest-pause (2-3 sets, 6-8 reps)';
                    $tips[] = 'Rest 30-60 seconds between sets, 24-48 hours between muscle groups';
                    break;
            }
        }
        
        // Frequency-based tips
        if (isset($preferences['training_frequency'])) {
            if ($preferences['training_frequency'] === '1-2') {
                $tips[] = 'Make each session count with full-body workouts';
                $tips[] = 'Consider increasing frequency as you build consistency';
            } elseif ($preferences['training_frequency'] === 'daily') {
                $tips[] = 'Ensure adequate recovery with varied intensities';
                $tips[] = 'Include active recovery days to prevent burnout';
            }
        }
        
        return array_unique($tips);
    }

    /**
     * Safely get user fitness data with fallback for missing columns
     */
    private function getUserFitnessData() {
        // First, check which columns exist in the users table
        $existing_columns = [];
        $describe_result = $this->conn->query("DESCRIBE users");
        if ($describe_result) {
            while ($row = $describe_result->fetch_assoc()) {
                $existing_columns[] = $row['Field'];
            }
        }

        // Build SQL query with only existing columns
        $base_columns = ['id', 'height', 'weight', 'target_weight', 'fitness_goal', 'experience_level', 'preferred_workout_type'];
        $optional_columns = ['body_fat', 'muscle_mass', 'waist', 'hip', 'training_level', 'training_frequency', 'training_notes', 'activity_level'];
        
        $select_columns = $base_columns;
        foreach ($optional_columns as $column) {
            if (in_array($column, $existing_columns)) {
                $select_columns[] = $column;
            }
        }

        $sql = "SELECT " . implode(', ', $select_columns) . " FROM users WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        
        if (!$stmt) {
            error_log("SQL prepare failed: " . $this->conn->error);
            return null;
        }

        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $fitness_data = $result->fetch_assoc();

        // Set default values for missing columns
        $default_values = [
            'body_fat' => null,
            'muscle_mass' => null,
            'waist' => null,
            'hip' => null,
            'training_level' => null,
            'training_frequency' => null,
            'training_notes' => null,
            'activity_level' => null
        ];

        foreach ($default_values as $column => $default_value) {
            if (!isset($fitness_data[$column])) {
                $fitness_data[$column] = $default_value;
            }
        }

        return $fitness_data;
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

    /**
     * Analyze body composition and provide insights
     */
    private function getBodyCompositionAnalysis($body_fat, $muscle_mass, $height, $weight) {
        $analysis = [];
        
        // Body fat analysis by gender (assume male for now, could be enhanced with gender field)
        if ($body_fat < 10) {
            $analysis['body_fat_status'] = 'Very Low - May impact performance';
        } elseif ($body_fat < 15) {
            $analysis['body_fat_status'] = 'Athletic - Excellent for performance';
        } elseif ($body_fat < 20) {
            $analysis['body_fat_status'] = 'Fit - Good balance';
        } elseif ($body_fat < 25) {
            $analysis['body_fat_status'] = 'Average - Room for improvement';
        } else {
            $analysis['body_fat_status'] = 'High - Focus on fat loss';
        }
        
        // Muscle mass analysis
        if ($muscle_mass > 45) {
            $analysis['muscle_status'] = 'Excellent muscle development';
        } elseif ($muscle_mass > 40) {
            $analysis['muscle_status'] = 'Good muscle mass';
        } elseif ($muscle_mass > 35) {
            $analysis['muscle_status'] = 'Average muscle mass';
        } else {
            $analysis['muscle_status'] = 'Low muscle mass - focus on strength training';
        }
        
        return $analysis;
    }

    /**
     * Get body composition recommendations
     */
    private function getBodyCompositionRecommendations($body_fat, $muscle_mass) {
        $recommendations = [];
        
        if ($body_fat > 20) {
            $recommendations[] = 'Increase cardio training to reduce body fat';
            $recommendations[] = 'Focus on compound movements for efficient calorie burn';
            $recommendations[] = 'Consider HIIT training 2-3 times per week';
        }
        
        if ($muscle_mass < 40) {
            $recommendations[] = 'Prioritize strength training 3-4 times per week';
            $recommendations[] = 'Focus on progressive overload with compound lifts';
            $recommendations[] = 'Ensure adequate protein intake (1.6-2.2g per kg body weight)';
        }
        
        if ($body_fat < 12 && $muscle_mass > 42) {
            $recommendations[] = 'Excellent composition - focus on maintenance and performance';
            $recommendations[] = 'Consider periodized training for continued progress';
        }
        
        return $recommendations;
    }

    /**
     * Determine health risk based on waist-to-hip ratio
     */
    private function getWHRHealthRisk($whr, $fitness_data) {
        // Generally, for men: >0.95 high risk, for women: >0.85 high risk
        // Using 0.9 as general threshold
        if ($whr > 0.95) {
            return 'High risk - Focus on waist reduction';
        } elseif ($whr > 0.85) {
            return 'Moderate risk - Monitor waist measurements';
        } else {
            return 'Low risk - Good distribution';
        }
    }

    /**
     * Get measurement-based recommendations
     */
    private function getMeasurementRecommendations($whr, $fitness_data) {
        $recommendations = [];
        
        if ($whr > 0.9) {
            $recommendations[] = 'Focus on core strengthening exercises';
            $recommendations[] = 'Increase cardio to reduce abdominal fat';
            $recommendations[] = 'Consider dietary changes to reduce waist circumference';
        }
        
        if ($whr < 0.8) {
            $recommendations[] = 'Excellent waist-to-hip ratio - maintain current routine';
            $recommendations[] = 'Continue balanced strength and cardio training';
        }
        
        return $recommendations;
    }

    /**
     * Get training recommendations based on current level
     */
    private function getTrainingRecommendations($training_level, $frequency, $analytics) {
        $recommendations = [];
        
        switch ($training_level) {
            case 'beginner':
                $recommendations[] = 'Focus on learning proper form with bodyweight exercises';
                $recommendations[] = 'Start with 2-3 full body workouts per week';
                $recommendations[] = 'Emphasize basic movement patterns (squat, hinge, push, pull)';
                break;
                
            case 'intermediate':
                $recommendations[] = 'Progress to split routines (upper/lower or push/pull/legs)';
                $recommendations[] = 'Increase training frequency to 3-4 times per week';
                $recommendations[] = 'Add progressive overload and track your lifts';
                break;
                
            case 'advanced':
                $recommendations[] = 'Implement periodization in your training';
                $recommendations[] = 'Consider specialized programs for specific goals';
                $recommendations[] = 'Train 4-6 times per week with varied intensities';
                break;
                
            case 'elite':
                $recommendations[] = 'Focus on competition-specific training';
                $recommendations[] = 'Work with a specialized coach';
                $recommendations[] = 'Implement advanced techniques and recovery protocols';
                break;
        }
        
        // Frequency-based recommendations
        if ($frequency === '1-2' && $training_level !== 'beginner') {
            $recommendations[] = 'Consider increasing training frequency for better results';
        }
        
        return $recommendations;
    }

    /**
     * Get progression plan based on current level
     */
    private function getProgressionPlan($training_level, $experience_level) {
        $plan = [];
        
        switch ($training_level) {
            case 'beginner':
                $plan['next_step'] = 'Master basic movements and build consistency';
                $plan['timeline'] = '2-3 months';
                $plan['focus'] = 'Form, consistency, habit building';
                break;
                
            case 'intermediate':
                $plan['next_step'] = 'Implement progressive overload and specialization';
                $plan['timeline'] = '6-12 months';
                $plan['focus'] = 'Strength gains, muscle building, technique refinement';
                break;
                
            case 'advanced':
                $plan['next_step'] = 'Advanced programming and periodization';
                $plan['timeline'] = '1-2 years';
                $plan['focus'] = 'Performance optimization, specialization';
                break;
                
            case 'elite':
                $plan['next_step'] = 'Competition preparation and peak performance';
                $plan['timeline'] = 'Ongoing';
                $plan['focus'] = 'Competition readiness, peak performance';
                break;
        }
        
        return $plan;
    }

    /**
     * Enhanced fitness-specific recommendations using comprehensive data
     */
    private function getFitnessSpecificRecommendations($fitness_goal, $analytics, $fitness_data = null) {
        $recommendations = [];
        
        switch ($fitness_goal) {
            case 'weight_loss':
                $recommendations[] = 'Create a moderate caloric deficit through diet and exercise';
                $recommendations[] = 'Combine strength training with cardio for optimal fat loss';
                $recommendations[] = 'Aim for 150-300 minutes of moderate cardio per week';
                
                // Enhanced recommendations based on body composition
                if ($fitness_data && $fitness_data['body_fat'] > 25) {
                    $recommendations[] = 'Prioritize high-intensity interval training (HIIT)';
                    $recommendations[] = 'Focus on compound movements to maximize calorie burn';
                }
                break;
                
            case 'muscle_gain':
                $recommendations[] = 'Focus on progressive overload in strength training';
                $recommendations[] = 'Consume adequate protein (1.6-2.2g per kg body weight)';
                $recommendations[] = 'Limit cardio to maintain caloric surplus';
                
                // Enhanced based on muscle mass data
                if ($fitness_data && $fitness_data['muscle_mass'] < 35) {
                    $recommendations[] = 'Emphasize compound lifts (squat, deadlift, bench press)';
                    $recommendations[] = 'Train each muscle group 2-3 times per week';
                }
                break;
                
            case 'endurance':
                $recommendations[] = 'Build aerobic base with steady-state cardio';
                $recommendations[] = 'Include tempo and interval training';
                $recommendations[] = 'Maintain strength training to prevent muscle loss';
                break;
                
            case 'flexibility':
                $recommendations[] = 'Include daily stretching or yoga practice';
                $recommendations[] = 'Focus on dynamic warm-ups before workouts';
                $recommendations[] = 'Add mobility work targeting tight areas';
                break;
                
            case 'general_fitness':
                $recommendations[] = 'Balance strength training, cardio, and flexibility work';
                $recommendations[] = 'Aim for 3-4 workout sessions per week';
                $recommendations[] = 'Focus on functional movement patterns';
                break;
        }
        
        return $recommendations;
    }
}
?> 