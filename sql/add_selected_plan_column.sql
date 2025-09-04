-- Add selected_plan_id column to users table
-- This column tracks the plan a member has selected but not yet paid for

ALTER TABLE users ADD COLUMN selected_plan_id INT NULL AFTER membership_plan_id;
ALTER TABLE users ADD FOREIGN KEY (selected_plan_id) REFERENCES membership_plans(id);

-- Add payment_status column to track if membership is active
ALTER TABLE users ADD COLUMN payment_status ENUM('pending', 'active', 'expired') DEFAULT 'pending' AFTER selected_plan_id;

-- Add last_payment_date column
ALTER TABLE users ADD COLUMN last_payment_date DATE NULL AFTER payment_status; 