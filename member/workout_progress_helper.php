<?php
/**
 * Workout Progress Helper
 * Handles workout progress tracking functionality
 */

class WorkoutProgressHelper {
    private $conn;
    private $user_id;

    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
    }

    /**
     * Get current week start date (Monday)
     */
    public function getCurrentWeekStart() {
        $today = date('Y-m-d');
        $dayOfWeek = date('N', strtotime($today)); // 1 (Monday) through 7 (Sunday)
        $daysToMonday = $dayOfWeek - 1;
        return date('Y-m-d', strtotime("-{$daysToMonday} days", strtotime($today)));
    }

    /**
     * Get user's workout plans
     */
    public function getUserWorkoutPlans() {
        $sql = "SELECT DISTINCT workout_name, 
                       COUNT(*) as exercise_count,
                       SUM(weekly_target) as total_weekly_target
                FROM user_workout_plans 
                WHERE user_id = ? AND is_active = TRUE 
                GROUP BY workout_name 
                ORDER BY workout_name";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $workouts = [];
        while ($row = $result->fetch_assoc()) {
            $workouts[] = $row;
        }
        
        return $workouts;
    }

    /**
     * Get exercises for a specific workout
     */
    public function getWorkoutExercises($workout_name) {
        $sql = "SELECT exercise_name, target_repetitions, target_sets, weekly_target
                FROM user_workout_plans 
                WHERE user_id = ? AND workout_name = ? AND is_active = TRUE 
                ORDER BY exercise_name";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $this->user_id, $workout_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $exercises = [];
        while ($row = $result->fetch_assoc()) {
            $exercises[] = $row;
        }
        
        return $exercises;
    }

    /**
     * Get current week's progress for an exercise
     */
    public function getExerciseProgress($exercise_name, $week_start = null) {
        if (!$week_start) {
            $week_start = $this->getCurrentWeekStart();
        }
        
        $sql = "SELECT SUM(completed_count) as total_completed
                FROM exercise_progress 
                WHERE user_id = ? AND exercise_name = ? AND week_start_date = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iss", $this->user_id, $exercise_name, $week_start);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['total_completed'] ?? 0;
    }

    /**
     * Get completion dates for an exercise in current week
     */
    public function getExerciseCompletionDates($exercise_name, $week_start = null) {
        if (!$week_start) {
            $week_start = $this->getCurrentWeekStart();
        }
        
        $sql = "SELECT completion_date, completed_count
                FROM exercise_progress 
                WHERE user_id = ? AND exercise_name = ? AND week_start_date = ?
                ORDER BY completion_date DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iss", $this->user_id, $exercise_name, $week_start);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $dates = [];
        while ($row = $result->fetch_assoc()) {
            $dates[] = $row;
        }
        
        return $dates;
    }

    /**
     * Get detailed workout progress with all exercises
     */
    public function getWorkoutProgress($workout_name) {
        $exercises = $this->getWorkoutExercises($workout_name);
        $week_start = $this->getCurrentWeekStart();
        
        $progress = [];
        foreach ($exercises as $exercise) {
            $completed = $this->getExerciseProgress($exercise['exercise_name'], $week_start);
            $completion_dates = $this->getExerciseCompletionDates($exercise['exercise_name'], $week_start);
            
            $progress[] = [
                'exercise_name' => $exercise['exercise_name'],
                'target_repetitions' => $exercise['target_repetitions'],
                'target_sets' => $exercise['target_sets'],
                'weekly_target' => $exercise['weekly_target'],
                'completed_count' => $completed,
                'progress_percentage' => $exercise['weekly_target'] > 0 ? 
                    min(100, round(($completed / $exercise['weekly_target']) * 100)) : 0,
                'completion_dates' => $completion_dates
            ];
        }
        
        return $progress;
    }

    /**
     * Get all workout progress for the user
     */
    public function getAllWorkoutProgress() {
        $workouts = $this->getUserWorkoutPlans();
        $all_progress = [];
        
        foreach ($workouts as $workout) {
            $all_progress[] = [
                'workout_name' => $workout['workout_name'],
                'exercise_count' => $workout['exercise_count'],
                'total_weekly_target' => $workout['total_weekly_target'],
                'exercises' => $this->getWorkoutProgress($workout['workout_name'])
            ];
        }
        
        return $all_progress;
    }

    /**
     * Calculate overall progress percentage for a workout
     */
    public function getWorkoutOverallProgress($workout_name) {
        $exercises = $this->getWorkoutExercises($workout_name);
        $week_start = $this->getCurrentWeekStart();
        
        $total_target = 0;
        $total_completed = 0;
        
        foreach ($exercises as $exercise) {
            $total_target += $exercise['weekly_target'];
            $total_completed += $this->getExerciseProgress($exercise['exercise_name'], $week_start);
        }
        
        return $total_target > 0 ? min(100, round(($total_completed / $total_target) * 100)) : 0;
    }

    /**
     * Get sample workout data for demonstration (if no real data exists)
     */
    public function getSampleWorkoutData() {
        return [
            [
                'workout_name' => 'Upper Body Strength',
                'exercise_count' => 3,
                'total_weekly_target' => 27,
                'exercises' => [
                    [
                        'exercise_name' => 'Push-ups',
                        'target_repetitions' => 15,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 9,
                        'progress_percentage' => 100,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-15', 'completed_count' => 3],
                            ['completion_date' => '2024-01-17', 'completed_count' => 3],
                            ['completion_date' => '2024-01-19', 'completed_count' => 3]
                        ]
                    ],
                    [
                        'exercise_name' => 'Pull-ups',
                        'target_repetitions' => 8,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 6,
                        'progress_percentage' => 67,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-16', 'completed_count' => 3],
                            ['completion_date' => '2024-01-18', 'completed_count' => 3]
                        ]
                    ],
                    [
                        'exercise_name' => 'Bench Press',
                        'target_repetitions' => 10,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 3,
                        'progress_percentage' => 33,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-20', 'completed_count' => 3]
                        ]
                    ]
                ]
            ],
            [
                'workout_name' => 'Lower Body Power',
                'exercise_count' => 3,
                'total_weekly_target' => 30,
                'exercises' => [
                    [
                        'exercise_name' => 'Squats',
                        'target_repetitions' => 12,
                        'target_sets' => 4,
                        'weekly_target' => 12,
                        'completed_count' => 12,
                        'progress_percentage' => 100,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-16', 'completed_count' => 4],
                            ['completion_date' => '2024-01-18', 'completed_count' => 4],
                            ['completion_date' => '2024-01-20', 'completed_count' => 4]
                        ]
                    ],
                    [
                        'exercise_name' => 'Deadlifts',
                        'target_repetitions' => 8,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 9,
                        'progress_percentage' => 100,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-17', 'completed_count' => 3],
                            ['completion_date' => '2024-01-19', 'completed_count' => 3],
                            ['completion_date' => '2024-01-21', 'completed_count' => 3]
                        ]
                    ],
                    [
                        'exercise_name' => 'Lunges',
                        'target_repetitions' => 10,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 6,
                        'progress_percentage' => 67,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-15', 'completed_count' => 3],
                            ['completion_date' => '2024-01-19', 'completed_count' => 3]
                        ]
                    ]
                ]
            ],
            [
                'workout_name' => 'Cardio Endurance',
                'exercise_count' => 2,
                'total_weekly_target' => 6,
                'exercises' => [
                    [
                        'exercise_name' => 'Running',
                        'target_repetitions' => 30,
                        'target_sets' => 1,
                        'weekly_target' => 3,
                        'completed_count' => 2,
                        'progress_percentage' => 67,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-16', 'completed_count' => 1],
                            ['completion_date' => '2024-01-18', 'completed_count' => 1]
                        ]
                    ],
                    [
                        'exercise_name' => 'Cycling',
                        'target_repetitions' => 45,
                        'target_sets' => 1,
                        'weekly_target' => 3,
                        'completed_count' => 1,
                        'progress_percentage' => 33,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-20', 'completed_count' => 1]
                        ]
                    ]
                ]
            ],
            [
                'workout_name' => 'Core Strength',
                'exercise_count' => 2,
                'total_weekly_target' => 18,
                'exercises' => [
                    [
                        'exercise_name' => 'Planks',
                        'target_repetitions' => 60,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 9,
                        'progress_percentage' => 100,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-17', 'completed_count' => 3],
                            ['completion_date' => '2024-01-19', 'completed_count' => 3],
                            ['completion_date' => '2024-01-21', 'completed_count' => 3]
                        ]
                    ],
                    [
                        'exercise_name' => 'Crunches',
                        'target_repetitions' => 20,
                        'target_sets' => 3,
                        'weekly_target' => 9,
                        'completed_count' => 6,
                        'progress_percentage' => 67,
                        'completion_dates' => [
                            ['completion_date' => '2024-01-15', 'completed_count' => 3],
                            ['completion_date' => '2024-01-18', 'completed_count' => 3]
                        ]
                    ]
                ]
            ]
        ];
    }
}
?> 