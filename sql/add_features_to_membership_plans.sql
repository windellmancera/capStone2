-- Add features column to membership_plans table
ALTER TABLE membership_plans ADD COLUMN features TEXT DEFAULT NULL;

-- Update existing plans with default features
UPDATE membership_plans SET features = '["Full gym access", "Professional equipment", "Clean facilities"]' WHERE features IS NULL; 