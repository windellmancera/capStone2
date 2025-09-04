-- Create exercise progress tracking table
CREATE TABLE IF NOT EXISTS exercise_progress (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    workout_name VARCHAR(100) NOT NULL,
    target_repetitions INT NOT NULL,
    target_sets INT NOT NULL,
    completed_count INT DEFAULT 0,
    completion_date DATE NOT NULL,
    week_start_date DATE NOT NULL COMMENT 'Start of the week (Monday) for weekly reset',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_exercise_week (user_id, exercise_name, week_start_date)
);

-- Create workout plans table to store assigned workout plans
CREATE TABLE IF NOT EXISTS user_workout_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    workout_name VARCHAR(100) NOT NULL,
    exercise_name VARCHAR(100) NOT NULL,
    target_repetitions INT NOT NULL,
    target_sets INT NOT NULL,
    weekly_target INT NOT NULL COMMENT 'Total target completions per week',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indices for better query performance
CREATE INDEX IF NOT EXISTS idx_exercise_progress_user_week ON exercise_progress (user_id, week_start_date);
CREATE INDEX IF NOT EXISTS idx_exercise_progress_exercise ON exercise_progress (exercise_name);
CREATE INDEX IF NOT EXISTS idx_user_workout_plans_user ON user_workout_plans (user_id, is_active);
CREATE INDEX IF NOT EXISTS idx_user_workout_plans_workout ON user_workout_plans (workout_name);

-- Insert sample workout plans for demonstration
INSERT INTO user_workout_plans (user_id, workout_name, exercise_name, target_repetitions, target_sets, weekly_target) VALUES
(37, 'Upper Body Strength', 'Push-ups', 15, 3, 9),
(37, 'Upper Body Strength', 'Pull-ups', 8, 3, 9),
(37, 'Upper Body Strength', 'Bench Press', 10, 3, 9),
(37, 'Lower Body Power', 'Squats', 12, 4, 12),
(37, 'Lower Body Power', 'Deadlifts', 8, 3, 9),
(37, 'Lower Body Power', 'Lunges', 10, 3, 9),
(37, 'Cardio Endurance', 'Running', 30, 1, 3),
(37, 'Cardio Endurance', 'Cycling', 45, 1, 3),
(37, 'Core Strength', 'Planks', 60, 3, 9),
(37, 'Core Strength', 'Crunches', 20, 3, 9);

-- Insert sample progress data for demonstration
INSERT INTO exercise_progress (user_id, exercise_name, workout_name, target_repetitions, target_sets, completed_count, completion_date, week_start_date) VALUES
(37, 'Push-ups', 'Upper Body Strength', 15, 3, 3, '2024-01-15', '2024-01-15'),
(37, 'Push-ups', 'Upper Body Strength', 15, 3, 3, '2024-01-17', '2024-01-15'),
(37, 'Push-ups', 'Upper Body Strength', 3, 3, 3, '2024-01-19', '2024-01-15'),
(37, 'Squats', 'Lower Body Power', 12, 4, 4, '2024-01-16', '2024-01-15'),
(37, 'Squats', 'Lower Body Power', 12, 4, 4, '2024-01-18', '2024-01-15'),
(37, 'Squats', 'Lower Body Power', 4, 4, 4, '2024-01-20', '2024-01-15'),
(37, 'Running', 'Cardio Endurance', 30, 1, 1, '2024-01-16', '2024-01-15'),
(37, 'Running', 'Cardio Endurance', 30, 1, 1, '2024-01-18', '2024-01-15'),
(37, 'Planks', 'Core Strength', 60, 3, 3, '2024-01-17', '2024-01-15'),
(37, 'Planks', 'Core Strength', 60, 3, 3, '2024-01-19', '2024-01-15'),
(37, 'Planks', 'Core Strength', 3, 3, 3, '2024-01-21', '2024-01-15'); 