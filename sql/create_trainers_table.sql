CREATE TABLE IF NOT EXISTS trainers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(100),
    experience_years INT,
    bio TEXT,
    certification TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample trainer data for testing
INSERT INTO trainers (user_id, name, specialization, experience_years, bio, certification) 
VALUES (
    (SELECT id FROM users WHERE role = 'trainer' LIMIT 1),
    'John Smith',
    'Strength Training',
    5,
    'Experienced trainer specializing in strength and conditioning',
    'Certified Personal Trainer (CPT)'
); 