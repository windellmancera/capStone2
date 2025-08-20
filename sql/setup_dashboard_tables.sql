-- Equipment Categories
CREATE TABLE IF NOT EXISTS equipment_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default equipment categories
INSERT INTO equipment_categories (name, description) VALUES
('Smart Equipment', 'Modern equipment with digital tracking capabilities'),
('Fitness Machines', 'Traditional gym machines for various exercises'),
('High-Intensity Tools', 'Equipment designed for high-intensity workouts');

-- Equipment Usage Tracking
CREATE TABLE IF NOT EXISTS equipment_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    equipment_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    duration_minutes INT,
    calories_burned INT,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (equipment_id) REFERENCES equipment(id)
);

-- Training Sessions
CREATE TABLE IF NOT EXISTS training_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    trainer_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME,
    status ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
    notes TEXT,
    progress_rating INT CHECK (progress_rating BETWEEN 1 AND 5),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (trainer_id) REFERENCES trainers(id)
);

-- Feedback System
CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('compliment', 'complaint', 'suggestion') NOT NULL,
    message TEXT NOT NULL,
    rating INT CHECK (rating BETWEEN 1 AND 5),
    status ENUM('pending', 'reviewed', 'resolved') DEFAULT 'pending',
    admin_response TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Member Analytics
CREATE TABLE IF NOT EXISTS member_analytics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    visit_frequency FLOAT,
    preferred_visit_time TIME,
    last_visit DATETIME,
    membership_duration INT,
    payment_reliability FLOAT,
    engagement_score FLOAT,
    churn_probability FLOAT,
    last_calculated DATETIME,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Notification Settings
CREATE TABLE IF NOT EXISTS notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email_notifications BOOLEAN DEFAULT true,
    sms_notifications BOOLEAN DEFAULT false,
    class_reminders BOOLEAN DEFAULT true,
    payment_reminders BOOLEAN DEFAULT true,
    announcement_notifications BOOLEAN DEFAULT true,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('class', 'payment', 'announcement', 'feedback', 'general') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT false,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Add category_id to equipment table
ALTER TABLE equipment
ADD COLUMN IF NOT EXISTS category_id INT,
ADD FOREIGN KEY (category_id) REFERENCES equipment_categories(id);

-- Update existing equipment with categories
UPDATE equipment SET category_id = (
    SELECT id FROM equipment_categories 
    WHERE name = 'Fitness Machines'
) WHERE category_id IS NULL;

-- Add priority field to announcements table if it doesn't exist
ALTER TABLE announcements
ADD COLUMN IF NOT EXISTS priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Low',
ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Add equipment usage tracking
CREATE TABLE IF NOT EXISTS equipment_views (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    user_id INT NOT NULL,
    view_timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Add index for faster analytics queries
CREATE INDEX idx_equipment_views_timestamp ON equipment_views(view_timestamp);
CREATE INDEX idx_attendance_timestamp ON attendance(check_in_time);
CREATE INDEX idx_payments_timestamp ON payments(payment_date);
CREATE INDEX idx_users_membership ON users(membership_end_date);

-- Add trainer ratings summary view for faster calculations
CREATE OR REPLACE VIEW trainer_ratings_summary AS
SELECT 
    t.id as trainer_id,
    t.name as trainer_name,
    COUNT(f.id) as total_ratings,
    ROUND(AVG(f.rating), 2) as average_rating
FROM trainers t
LEFT JOIN feedback f ON t.id = f.trainer_id
GROUP BY t.id, t.name;

-- Add membership plan popularity view
CREATE OR REPLACE VIEW membership_plan_popularity AS
SELECT 
    mp.id as plan_id,
    mp.name as plan_name,
    COUNT(u.id) as total_subscribers,
    COUNT(CASE WHEN u.membership_end_date >= CURDATE() THEN 1 END) as active_subscribers
FROM membership_plans mp
LEFT JOIN users u ON mp.id = u.membership_plan_id
GROUP BY mp.id, mp.name; 