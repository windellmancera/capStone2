-- First ensure trainers table exists
CREATE TABLE IF NOT EXISTS trainers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(255),
    certification VARCHAR(255),
    experience VARCHAR(100),
    description TEXT,
    experience_years INT DEFAULT 0,
    bio TEXT
);

-- Add new columns to trainers table if they don't exist
ALTER TABLE trainers
ADD COLUMN IF NOT EXISTS contact_number VARCHAR(20) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS image_url VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS status ENUM('active', 'inactive', 'on_leave') DEFAULT 'active',
ADD COLUMN IF NOT EXISTS availability_schedule TEXT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS experience_years INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS bio TEXT,
ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_session_end DATETIME DEFAULT NULL,
ADD COLUMN IF NOT EXISTS next_session_start DATETIME DEFAULT NULL;

-- Create trainer_specialties table if it doesn't exist
CREATE TABLE IF NOT EXISTS trainer_specialties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    specialty VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES trainers(id) ON DELETE CASCADE
);

-- Create trainer_schedules table if it doesn't exist
CREATE TABLE IF NOT EXISTS trainer_schedules (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES trainers(id) ON DELETE CASCADE
);

-- Create training_sessions table if it doesn't exist
CREATE TABLE IF NOT EXISTS training_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES trainers(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_trainer_specialties_trainer_id ON trainer_specialties(trainer_id);
CREATE INDEX IF NOT EXISTS idx_trainer_schedules_trainer_id ON trainer_schedules(trainer_id);
CREATE INDEX IF NOT EXISTS idx_training_sessions_trainer_id ON training_sessions(trainer_id);
CREATE INDEX IF NOT EXISTS idx_training_sessions_member_id ON training_sessions(member_id);

-- Insert some default specialties for existing trainers if trainer_specialties is empty
INSERT IGNORE INTO trainer_specialties (trainer_id, specialty)
SELECT id, specialization 
FROM trainers 
WHERE specialization IS NOT NULL 
AND NOT EXISTS (
    SELECT 1 
    FROM trainer_specialties ts 
    WHERE ts.trainer_id = trainers.id
);

-- Update existing trainers with default values if needed
UPDATE trainers 
SET contact_number = COALESCE(contact_number, '123-456-7890'),
    email = COALESCE(email, CONCAT(LOWER(REPLACE(name, ' ', '.')), '@almofitness.com')),
    status = COALESCE(status, 'active'),
    hourly_rate = COALESCE(hourly_rate, 50.00),
    experience_years = COALESCE(experience_years, 1)
WHERE contact_number IS NULL 
   OR email IS NULL 
   OR status IS NULL 
   OR hourly_rate IS NULL 
   OR experience_years IS NULL;

-- Add indexes for better performance
CREATE INDEX IF NOT EXISTS idx_trainer_status ON trainers(status);
CREATE INDEX IF NOT EXISTS idx_trainer_specialization ON trainers(specialization);
CREATE INDEX IF NOT EXISTS idx_trainer_schedules_day ON trainer_schedules(day_of_week);
CREATE INDEX IF NOT EXISTS idx_trainer_specialties_specialty ON trainer_specialties(specialty);

-- Add index for faster status queries
CREATE INDEX IF NOT EXISTS idx_trainer_status ON trainers(status);

-- Update view to include activity status
CREATE OR REPLACE VIEW trainer_activity_status AS
SELECT 
    t.id,
    t.name,
    t.status,
    t.last_login,
    t.last_session_end,
    t.next_session_start,
    CASE 
        WHEN t.status = 'inactive' THEN 'inactive'
        WHEN t.status = 'on_leave' THEN 'on_leave'
        WHEN t.next_session_start IS NOT NULL 
            AND t.next_session_start <= DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN 'upcoming_session'
        WHEN t.last_session_end IS NOT NULL 
            AND t.last_session_end >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'just_finished'
        WHEN t.last_login IS NOT NULL 
            AND t.last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 'online'
        ELSE 'offline'
    END as activity_status
FROM trainers t; 