-- Add admin user if not exists
INSERT INTO users (username, email, password, role) 
SELECT 'Admin', 'admin@almofitness.com', 'admin123', 'admin'
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'admin@almofitness.com'
); 