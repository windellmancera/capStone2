-- Add fitness-related fields to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS height DECIMAL(5,2) DEFAULT NULL COMMENT 'Height in centimeters',
ADD COLUMN IF NOT EXISTS weight DECIMAL(5,2) DEFAULT NULL COMMENT 'Current weight in kilograms',
ADD COLUMN IF NOT EXISTS target_weight DECIMAL(5,2) DEFAULT NULL COMMENT 'Target weight in kilograms',
ADD COLUMN IF NOT EXISTS fitness_goal ENUM('muscle_gain', 'weight_loss', 'endurance', 'flexibility', 'general_fitness') DEFAULT 'general_fitness',
ADD COLUMN IF NOT EXISTS experience_level ENUM('beginner', 'intermediate', 'advanced') DEFAULT 'beginner',
ADD COLUMN IF NOT EXISTS preferred_workout_type VARCHAR(50) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_fitness_assessment DATE DEFAULT NULL;

-- Add indices for better query performance
CREATE INDEX IF NOT EXISTS idx_user_fitness ON users (fitness_goal, experience_level);
CREATE INDEX IF NOT EXISTS idx_user_metrics ON users (height, weight, target_weight);

-- Add workout tracking table if it doesn't exist
CREATE TABLE IF NOT EXISTS workout_tracking (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    workout_date DATE NOT NULL,
    workout_type VARCHAR(50) NOT NULL,
    duration_minutes INT NOT NULL,
    intensity_level ENUM('low', 'moderate', 'high') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add fitness goals tracking table if it doesn't exist
CREATE TABLE IF NOT EXISTS fitness_goals (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    goal_type VARCHAR(50) NOT NULL,
    target_value DECIMAL(10,2),
    start_value DECIMAL(10,2),
    start_date DATE NOT NULL,
    target_date DATE,
    status ENUM('active', 'completed', 'abandoned') DEFAULT 'active',
    progress_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add fitness measurements tracking table if it doesn't exist
CREATE TABLE IF NOT EXISTS fitness_measurements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    measurement_date DATE NOT NULL,
    weight DECIMAL(5,2),
    body_fat_percentage DECIMAL(4,1),
    muscle_mass DECIMAL(5,2),
    chest_cm DECIMAL(5,2),
    waist_cm DECIMAL(5,2),
    hips_cm DECIMAL(5,2),
    arms_cm DECIMAL(5,2),
    thighs_cm DECIMAL(5,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indices for better query performance on new tables
CREATE INDEX IF NOT EXISTS idx_workout_user_date ON workout_tracking (user_id, workout_date);
CREATE INDEX IF NOT EXISTS idx_goals_user_status ON fitness_goals (user_id, status);
CREATE INDEX IF NOT EXISTS idx_measurements_user_date ON fitness_measurements (user_id, measurement_date); 