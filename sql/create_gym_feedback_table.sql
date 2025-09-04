-- Create gym_feedback table for general gym feedback
CREATE TABLE IF NOT EXISTS gym_feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    category ENUM('facilities', 'services', 'system', 'general') DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Add index for faster lookups
CREATE INDEX idx_gym_feedback_user ON gym_feedback(user_id);
CREATE INDEX idx_gym_feedback_category ON gym_feedback(category);

-- Add some sample feedback data if needed
INSERT INTO gym_feedback (user_id, message, category) 
SELECT 
    u.id,
    CASE 
        WHEN RAND() < 0.25 THEN 'The gym equipment is well-maintained and modern. Great facility!'
        WHEN RAND() < 0.5 THEN 'The new mobile app makes it very convenient to book sessions.'
        WHEN RAND() < 0.75 THEN 'Staff is always helpful and professional.'
        ELSE 'Love the cleanliness and organization of the gym.'
    END as message,
    CASE 
        WHEN RAND() < 0.25 THEN 'facilities'
        WHEN RAND() < 0.5 THEN 'system'
        WHEN RAND() < 0.75 THEN 'services'
        ELSE 'general'
    END as category
FROM users u 
WHERE u.role = 'member'
LIMIT 5; 