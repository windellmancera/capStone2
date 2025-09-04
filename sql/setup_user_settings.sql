-- Create user_settings table if it doesn't exist
CREATE TABLE IF NOT EXISTS user_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    email_notifications BOOLEAN DEFAULT TRUE,
    sms_notifications BOOLEAN DEFAULT TRUE,
    membership_renewal_notify BOOLEAN DEFAULT TRUE,
    announcement_notify BOOLEAN DEFAULT TRUE,
    schedule_notify BOOLEAN DEFAULT TRUE,
    promo_notify BOOLEAN DEFAULT TRUE,
    dark_mode BOOLEAN DEFAULT FALSE,
    language VARCHAR(10) DEFAULT 'en',
    timezone VARCHAR(50) DEFAULT 'UTC',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_user_settings (user_id)
) ENGINE=InnoDB;

-- Insert default settings for existing users
INSERT IGNORE INTO user_settings (user_id, email_notifications, sms_notifications)
SELECT id, TRUE, TRUE FROM users WHERE role = 'member'; 