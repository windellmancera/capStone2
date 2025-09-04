ALTER TABLE users ADD COLUMN payment_status ENUM('pending', 'active', 'expired') DEFAULT 'pending';
ALTER TABLE users ADD COLUMN last_payment_date DATE NULL;
ALTER TABLE users ADD COLUMN selected_plan_id INT NULL; 