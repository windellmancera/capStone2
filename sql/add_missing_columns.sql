-- Add missing columns to users table for payment and membership management

-- Add payment_status column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS payment_status ENUM('pending', 'active', 'expired') DEFAULT 'pending';

-- Add last_payment_date column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_payment_date DATE NULL;

-- Add selected_plan_id column if it doesn't exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS selected_plan_id INT NULL;

-- Add foreign key for selected_plan_id if it doesn't exist
-- First check if the foreign key exists
SET @fk_exists = (
    SELECT COUNT(*) 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = 'almo_fitness_db' 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME = 'selected_plan_id' 
    AND REFERENCED_TABLE_NAME = 'membership_plans'
);

-- Add foreign key only if it doesn't exist
SET @sql = IF(@fk_exists = 0, 
    'ALTER TABLE users ADD FOREIGN KEY (selected_plan_id) REFERENCES membership_plans(id)',
    'SELECT "Foreign key already exists" as message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt; 