-- Add plan_id column if it doesn't exist
ALTER TABLE payment_history
ADD COLUMN IF NOT EXISTS plan_id INT,
ADD CONSTRAINT fk_payment_plan
FOREIGN KEY (plan_id) REFERENCES membership_plans(id);

-- Update existing records to set plan_id from users table
UPDATE payment_history ph
INNER JOIN users u ON ph.user_id = u.id
SET ph.plan_id = u.membership_plan_id
WHERE ph.plan_id IS NULL; 