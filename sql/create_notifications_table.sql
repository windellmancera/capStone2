-- Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error', 'reminder') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add indices for better query performance
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications (user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_created_at ON notifications (created_at);

-- Insert sample notifications for demonstration
INSERT INTO notifications (user_id, title, message, type) VALUES
(37, 'Membership Renewal Reminder', 'Your membership will expire in 7 days. Please renew to continue enjoying our services.', 'reminder'),
(37, 'New Equipment Available', 'We have added new cardio machines to our facility. Come check them out!', 'info'),
(37, 'Class Schedule Update', 'Your regular yoga class has been rescheduled to 6:00 PM this Friday.', 'warning'),
(37, 'Achievement Unlocked', 'Congratulations! You have completed 10 workouts this month.', 'success'),
(37, 'Personal Trainer Message', 'Your trainer has sent you a new workout plan. Check it out!', 'info'),
(37, 'Payment Confirmation', 'Your monthly membership payment has been processed successfully.', 'success'),
(37, 'Equipment Maintenance', 'The treadmill in section A is currently under maintenance. Please use alternative equipment.', 'warning'),
(37, 'Welcome Back!', 'We missed you! It has been 5 days since your last visit.', 'reminder'); 