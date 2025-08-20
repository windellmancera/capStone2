-- Create membership_plans table if it doesn't exist
CREATE TABLE IF NOT EXISTS membership_plans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    duration INT NOT NULL COMMENT 'Duration in days',
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create payments table if it doesn't exist
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    status VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create payment_history table
CREATE TABLE IF NOT EXISTS payment_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    payment_method ENUM('Cash', 'GCash', 'PayMaya', 'GoTyme') NOT NULL,
    payment_status ENUM('Pending', 'Pending Verification', 'Completed', 'Failed', 'Refunded') NOT NULL DEFAULT 'Pending',
    proof_of_payment VARCHAR(255),
    reference_number VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create membership_freeze table
CREATE TABLE IF NOT EXISTS membership_freeze (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    status ENUM('Pending', 'Approved', 'Rejected', 'Completed') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Add balance and freeze_credits columns to users table if they don't exist
ALTER TABLE users
ADD COLUMN IF NOT EXISTS balance DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Outstanding balance',
ADD COLUMN IF NOT EXISTS freeze_credits INT DEFAULT 2 COMMENT 'Number of times a member can freeze their membership per year',
ADD COLUMN IF NOT EXISTS membership_plan_id INT NULL,
ADD COLUMN IF NOT EXISTS membership_start_date DATE NULL,
ADD COLUMN IF NOT EXISTS membership_end_date DATE NULL,
ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL,
ADD FOREIGN KEY IF NOT EXISTS (membership_plan_id) REFERENCES membership_plans(id);

-- Insert default membership plans if they don't exist
INSERT IGNORE INTO membership_plans (name, description, duration, price) VALUES
('Daily Pass', 'Perfect for trying us out', 1, 150.00),
('Monthly Plan', 'Most popular choice', 30, 2000.00),
('Annual Plan', 'Best value for money', 365, 20000.00),
('Premium Plan', 'Ultimate fitness experience', 365, 30000.00);

-- Create equipment table if not exists
CREATE TABLE IF NOT EXISTS equipment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category ENUM('Smart Equipment', 'Fitness Equipment') NOT NULL,
    type VARCHAR(50) NOT NULL,
    status ENUM('Available', 'In Use', 'Maintenance') NOT NULL DEFAULT 'Available',
    features TEXT,
    tracking_capabilities TEXT,
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create equipment_usage table if not exists
CREATE TABLE IF NOT EXISTS equipment_usage (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    equipment_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    duration INT,
    calories_burned DECIMAL(10,2),
    distance DECIMAL(10,2),
    intensity_level VARCHAR(20),
    heart_rate_avg INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(id)
);

-- Insert sample equipment data if not exists
INSERT IGNORE INTO equipment (name, description, category, type, features, tracking_capabilities) VALUES
('Treadmill Pro X1', 'Advanced treadmill with built-in workout programs', 'Smart Equipment', 'Cardio', 
'Heart rate monitoring, Incline control, Speed control, LCD display', 
'Distance tracking, Calorie tracking, Heart rate monitoring, Speed tracking'),

('Smith Machine', 'Professional smith machine for weight training', 'Fitness Equipment', 'Strength',
'Safety locks, Adjustable hooks, Weight storage', 
'Weight tracking'),

('Smart Bike Elite', 'Interactive cycling experience with virtual routes', 'Smart Equipment', 'Cardio',
'Virtual routes, Resistance control, Tablet holder, Bluetooth connectivity',
'Distance tracking, Speed tracking, Resistance level tracking, Heart rate monitoring'),

('Power Rack', 'Heavy-duty power rack for strength training', 'Fitness Equipment', 'Strength',
'Multiple safety catches, Pull-up bar, Band pegs',
NULL),

('Smart Rowing Machine', 'Advanced rowing machine with performance tracking', 'Smart Equipment', 'Cardio',
'Digital display, Adjustable resistance, Ergonomic handle',
'Stroke rate tracking, Distance tracking, Power output tracking'),

('Adjustable Dumbbells', 'Quick-change weight adjustment system', 'Fitness Equipment', 'Free Weights',
'Quick-lock adjustment, Compact design',
NULL),

('Smart Cable Machine', 'Dual pulley system with weight tracking', 'Smart Equipment', 'Strength',
'Digital weight selection, Multiple attachments, Exercise tracking',
'Weight tracking, Rep counting, Set tracking'),

('Olympic Bench Press', 'Professional Olympic bench with safety spotters', 'Fitness Equipment', 'Strength',
'Safety spotters, Adjustable seat, Weight storage',
NULL); 