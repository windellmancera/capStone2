-- Add profile fields to users table
ALTER TABLE users
ADD COLUMN IF NOT EXISTS full_name VARCHAR(100),
ADD COLUMN IF NOT EXISTS mobile_number VARCHAR(20),
ADD COLUMN IF NOT EXISTS gender ENUM('male', 'female', 'other'),
ADD COLUMN IF NOT EXISTS home_address TEXT,
ADD COLUMN IF NOT EXISTS date_of_birth DATE,
ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255),
ADD COLUMN IF NOT EXISTS qr_code VARCHAR(255);

-- Update fitness_data table to include location and visit frequency
ALTER TABLE fitness_data
ADD COLUMN IF NOT EXISTS location VARCHAR(255) AFTER gender,
MODIFY COLUMN attendance_frequency INT DEFAULT 0 COMMENT 'Number of visits per month';

-- Create a table for tracking profile picture updates
CREATE TABLE IF NOT EXISTS profile_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    update_type ENUM('profile_picture', 'qr_code') NOT NULL,
    old_value VARCHAR(255),
    new_value VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
); 