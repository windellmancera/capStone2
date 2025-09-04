-- Create feedback table for trainer ratings
CREATE TABLE IF NOT EXISTS feedback (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    trainer_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (trainer_id) REFERENCES trainers(id)
);

-- Add index for faster lookups
CREATE INDEX idx_feedback_trainer ON feedback(trainer_id);
CREATE INDEX idx_feedback_user ON feedback(user_id);

-- Add some sample feedback data if needed
INSERT INTO feedback (user_id, trainer_id, rating, comment) 
SELECT 
    u.id as user_id,
    t.id as trainer_id,
    FLOOR(3 + (RAND() * 3)) as rating,
    'Sample feedback for trainer'
FROM users u 
CROSS JOIN trainers t 
WHERE u.role = 'member'
LIMIT 10; 