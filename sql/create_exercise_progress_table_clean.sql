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