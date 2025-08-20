-- Create gym_feedback table
CREATE TABLE IF NOT EXISTS gym_feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    category ENUM('facilities', 'services', 'system', 'general') DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Add indexes for better performance
CREATE INDEX idx_gym_feedback_user ON gym_feedback(user_id);
CREATE INDEX idx_gym_feedback_category ON gym_feedback(category);

-- Add sample feedback data
INSERT INTO gym_feedback (user_id, message, category) 
SELECT 
    u.id,
    ELT(FLOOR(1 + RAND() * 4),
        'The gym equipment is well-maintained and modern. Great facility!',
        'The new mobile app makes it very convenient to book sessions.',
        'Staff is always helpful and professional.',
        'Love the cleanliness and organization of the gym.'
    ) as message,
    ELT(FLOOR(1 + RAND() * 4),
        'facilities',
        'system',
        'services',
        'general'
    ) as category
FROM users u 
WHERE u.role = 'member'
LIMIT 5; 