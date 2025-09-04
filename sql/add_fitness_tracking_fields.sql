-- Add fitness tracking fields to users table
-- This script adds comprehensive fitness tracking capabilities

-- Add body composition fields
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS body_fat DECIMAL(4,1) NULL COMMENT 'Body fat percentage (5-50%)',
ADD COLUMN IF NOT EXISTS muscle_mass DECIMAL(4,1) NULL COMMENT 'Muscle mass percentage (20-60%)';

-- Add circumference measurement fields
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS waist DECIMAL(5,1) NULL COMMENT 'Waist circumference in cm (50-200)',
ADD COLUMN IF NOT EXISTS hip DECIMAL(5,1) NULL COMMENT 'Hip circumference in cm (60-250)';

-- Add training progress fields
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS training_level ENUM('beginner', 'intermediate', 'advanced', 'elite') NULL COMMENT 'Current training level',
ADD COLUMN IF NOT EXISTS training_frequency ENUM('1-2', '3-4', '5-6', 'daily') NULL COMMENT 'Training frequency per week',
ADD COLUMN IF NOT EXISTS training_notes TEXT NULL COMMENT 'Training progress notes and achievements';

-- Add timestamp for tracking when fitness data was last updated
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last fitness data update timestamp';

-- Add indexes for better performance on fitness data queries
CREATE INDEX IF NOT EXISTS idx_users_fitness_data ON users(height, weight, body_fat, muscle_mass);
CREATE INDEX IF NOT EXISTS idx_users_training_level ON users(training_level, training_frequency);
CREATE INDEX IF NOT EXISTS idx_users_updated_at ON users(updated_at);

-- Add constraints for data validation
ALTER TABLE users 
ADD CONSTRAINT chk_body_fat CHECK (body_fat IS NULL OR (body_fat >= 5 AND body_fat <= 50)),
ADD CONSTRAINT chk_muscle_mass CHECK (muscle_mass IS NULL OR (muscle_mass >= 20 AND muscle_mass <= 60)),
ADD CONSTRAINT chk_waist CHECK (waist IS NULL OR (waist >= 50 AND waist <= 200)),
ADD CONSTRAINT chk_hip CHECK (hip IS NULL OR (hip >= 60 AND hip <= 250));

-- Update existing users to have default values for new fields
UPDATE users SET 
    body_fat = NULL,
    muscle_mass = NULL,
    waist = NULL,
    hip = NULL,
    training_level = NULL,
    training_frequency = NULL,
    training_notes = NULL
WHERE body_fat IS NULL;

-- Add comments to existing fitness fields for clarity
ALTER TABLE users 
MODIFY COLUMN height DECIMAL(5,1) NULL COMMENT 'Height in centimeters (100-250)',
MODIFY COLUMN weight DECIMAL(5,2) NULL COMMENT 'Weight in kilograms (30-300)',
MODIFY COLUMN target_weight DECIMAL(5,2) NULL COMMENT 'Target weight in kilograms';

-- Create a view for easy access to fitness metrics
CREATE OR REPLACE VIEW user_fitness_summary AS
SELECT 
    id,
    username,
    height,
    weight,
    CASE 
        WHEN height IS NOT NULL AND weight IS NOT NULL 
        THEN ROUND(weight / POWER(height/100, 2), 1)
        ELSE NULL 
    END as bmi,
    body_fat,
    muscle_mass,
    waist,
    hip,
    training_level,
    training_frequency,
    updated_at,
    CASE 
        WHEN height IS NOT NULL AND weight IS NOT NULL 
        THEN 
            CASE 
                WHEN weight / POWER(height/100, 2) < 18.5 THEN 'Underweight'
                WHEN weight / POWER(height/100, 2) < 25 THEN 'Normal Weight'
                WHEN weight / POWER(height/100, 2) < 30 THEN 'Overweight'
                WHEN weight / POWER(height/100, 2) < 35 THEN 'Obese Class I'
                WHEN weight / POWER(height/100, 2) < 40 THEN 'Obese Class II'
                ELSE 'Obese Class III'
            END
        ELSE NULL
    END as bmi_category
FROM users;

-- Grant permissions (adjust as needed for your database setup)
-- GRANT SELECT ON user_fitness_summary TO 'member_user'@'localhost';
-- GRANT SELECT ON user_fitness_summary TO 'admin_user'@'localhost';
