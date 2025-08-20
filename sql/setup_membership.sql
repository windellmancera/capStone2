-- Create membership plans table
CREATE TABLE IF NOT EXISTS membership_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    duration INT NOT NULL, -- Duration in days
    price DECIMAL(10,2) NOT NULL,
    description TEXT
);

-- Insert default membership plans
INSERT INTO membership_plans (name, duration, price, description) VALUES
('Daily Pass', 1, 100.00, 'Access to gym facilities for one day'),
('Monthly Plan', 30, 1200.00, 'Full access to gym facilities for 30 days'),
('Annual Plan', 365, 8000.00, 'Best value! Full year access to all gym facilities');

-- Add membership fields to users table
ALTER TABLE users 
ADD COLUMN membership_plan_id INT,
ADD COLUMN membership_start_date DATE,
ADD COLUMN membership_end_date DATE,
ADD FOREIGN KEY (membership_plan_id) REFERENCES membership_plans(id); 