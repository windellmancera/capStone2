-- Drop existing foreign key if it exists
SET @constraint_name = (
    SELECT CONSTRAINT_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'attendance' 
    AND COLUMN_NAME = 'plan_id' 
    AND REFERENCED_TABLE_NAME = 'membership_plans'
);

SET @drop_fk = IF(@constraint_name IS NOT NULL, 
    CONCAT('ALTER TABLE attendance DROP FOREIGN KEY ', @constraint_name),
    'SELECT 1');
PREPARE drop_stmt FROM @drop_fk;
EXECUTE drop_stmt;
DEALLOCATE PREPARE drop_stmt;

-- Drop existing index if it exists
DROP INDEX IF EXISTS idx_attendance_user_time ON attendance;

-- Add or modify plan_id column
ALTER TABLE attendance
ADD COLUMN IF NOT EXISTS plan_id INT DEFAULT NULL;

-- Add foreign key constraint
ALTER TABLE attendance
ADD CONSTRAINT fk_attendance_plan 
FOREIGN KEY (plan_id) REFERENCES membership_plans(id) 
ON DELETE SET NULL;

-- Add index for faster queries
CREATE INDEX idx_attendance_user_time ON attendance (user_id, check_in_time);

-- Update existing records to link with plans (if possible)
UPDATE attendance a
INNER JOIN (
    SELECT ph.user_id, mp.id as plan_id, ph.payment_date,
           DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as expiry_date
    FROM payment_history ph
    INNER JOIN membership_plans mp ON ph.plan_id = mp.id
    WHERE ph.payment_status = 'Completed'
) ph ON a.user_id = ph.user_id 
    AND a.check_in_time BETWEEN ph.payment_date AND ph.expiry_date
SET a.plan_id = ph.plan_id
WHERE a.plan_id IS NULL; 