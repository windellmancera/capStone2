-- Complete Database Setup for QR Scanning System
-- Run this script in your MySQL database

-- 1. Create users table (if not exists)
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role` enum('admin','member','trainer') DEFAULT 'member',
  `selected_plan_id` int(11) DEFAULT NULL,
  `qr_code` text DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create membership_plans table
CREATE TABLE IF NOT EXISTS `membership_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration` int(11) NOT NULL COMMENT 'Duration in days',
  `features` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create payment_history table
CREATE TABLE IF NOT EXISTS `payment_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` datetime NOT NULL,
  `payment_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `payment_method` varchar(100) DEFAULT NULL,
  `proof_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  CONSTRAINT `fk_payment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create attendance table
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `check_in_time` datetime NOT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`),
  KEY `check_in_time` (`check_in_time`),
  CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Add foreign key to users table for selected_plan_id
ALTER TABLE `users` 
ADD CONSTRAINT `fk_user_plan` FOREIGN KEY (`selected_plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE SET NULL;

-- 6. Insert sample membership plans
INSERT INTO `membership_plans` (`name`, `description`, `price`, `duration`, `features`) VALUES
('Basic Plan', 'Basic gym access with essential equipment', 1000.00, 30, 'Gym access, Basic equipment, Locker room'),
('Premium Plan', 'Premium gym access with personal trainer', 2000.00, 30, 'Gym access, All equipment, Personal trainer, Group classes'),
('VIP Plan', 'VIP gym access with all amenities', 3000.00, 30, 'Gym access, All equipment, Personal trainer, Group classes, Spa access, Nutrition consultation');

-- 7. Insert sample admin user (password: admin123)
INSERT INTO `users` (`username`, `password`, `email`, `full_name`, `role`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@almo.com', 'Admin User', 'admin');

-- 8. Insert sample member user (password: member123)
INSERT INTO `users` (`username`, `password`, `email`, `full_name`, `role`, `selected_plan_id`) VALUES
('member1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'member1@almo.com', 'John Doe', 'member', 1);

-- 9. Insert sample approved payment
INSERT INTO `payment_history` (`user_id`, `plan_id`, `amount`, `payment_date`, `payment_status`, `payment_method`) VALUES
(2, 1, 1000.00, NOW(), 'Approved', 'Cash');

-- 10. Generate QR code for sample member
UPDATE `users` SET `qr_code` = '{"user_id":2,"payment_id":1,"plan_name":"Basic Plan","timestamp":' || UNIX_TIMESTAMP() || ',"hash":"' || SHA2(CONCAT(2,1,UNIX_TIMESTAMP(),'ALMO_FITNESS_SECRET'), 256) || '"}' WHERE `id` = 2;

-- 11. Create trainers table
CREATE TABLE IF NOT EXISTS `trainers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `specialization` varchar(255) DEFAULT NULL,
  `experience_years` int(11) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_trainer_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. Create equipment table
CREATE TABLE IF NOT EXISTS `equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Available','In Use','Maintenance','Out of Order') DEFAULT 'Available',
  `location` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. Create announcements table
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `priority` enum('Low','Medium','High','Urgent') DEFAULT 'Medium',
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_announcement_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. Create feedback table
CREATE TABLE IF NOT EXISTS `feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rating` int(11) NOT NULL CHECK (rating >= 1 AND rating <= 5),
  `comment` text DEFAULT NULL,
  `category` enum('Service','Equipment','Staff','Facility') DEFAULT 'Service',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. Insert sample announcements
INSERT INTO `announcements` (`title`, `content`, `priority`, `created_by`) VALUES
('Welcome to Almo Fitness!', 'Welcome to our new members. Please read our gym rules and regulations.', 'Medium', 1),
('Equipment Maintenance', 'Treadmill #3 will be under maintenance tomorrow from 9 AM to 2 PM.', 'Medium', 1);

-- 16. Insert sample equipment
INSERT INTO `equipment` (`name`, `description`, `status`, `location`) VALUES
('Treadmill #1', 'Professional treadmill with incline', 'Available', 'Cardio Area'),
('Treadmill #2', 'Professional treadmill with incline', 'Available', 'Cardio Area'),
('Treadmill #3', 'Professional treadmill with incline', 'Maintenance', 'Cardio Area'),
('Bench Press', 'Adjustable bench press', 'Available', 'Weight Training Area'),
('Dumbbells Set', 'Complete set of dumbbells (5-50 lbs)', 'Available', 'Weight Training Area');

-- 17. Create indexes for better performance
CREATE INDEX IF NOT EXISTS `idx_attendance_user_date` ON `attendance` (`user_id`, `check_in_time`);
CREATE INDEX IF NOT EXISTS `idx_payment_user_status` ON `payment_history` (`user_id`, `payment_status`);
CREATE INDEX IF NOT EXISTS `idx_users_role` ON `users` (`role`);

-- 18. Insert sample trainer
INSERT INTO `users` (`username`, `password`, `email`, `full_name`, `role`) VALUES
('trainer1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'trainer1@almo.com', 'Mike Johnson', 'trainer');

INSERT INTO `trainers` (`user_id`, `specialization`, `experience_years`, `bio`, `hourly_rate`) VALUES
(3, 'Weight Training, Cardio', 5, 'Certified personal trainer with 5 years of experience', 500.00);

-- 19. Insert sample feedback
INSERT INTO `feedback` (`user_id`, `rating`, `comment`, `category`) VALUES
(2, 5, 'Great gym facilities and friendly staff!', 'Service'),
(2, 4, 'Equipment is well-maintained', 'Equipment');

-- Display completion message
SELECT 'Database setup completed successfully!' as status; 