-- Drop and recreate the payments table with the correct structure
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    membership_plan_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash', 'GCash') NOT NULL,
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    proof_of_payment VARCHAR(255),
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    notes TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (membership_plan_id) REFERENCES membership_plans(id)
);

-- Update payment_history table to include 'Approved' status
ALTER TABLE payment_history 
MODIFY COLUMN payment_status ENUM('Pending', 'Completed', 'Approved', 'Rejected', 'Failed') DEFAULT 'Pending';

-- Update any existing 'Completed' payments to 'Approved'
UPDATE payment_history 
SET payment_status = 'Approved' 
WHERE payment_status = 'Completed';

-- Add a comment to explain the change
-- This update adds the 'Approved' status to explicitly indicate admin approval
-- and converts existing completed payments to approved status 