-- Create member_progress table
CREATE TABLE IF NOT EXISTS `member_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date_recorded` date NOT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `body_fat` decimal(4,2) DEFAULT NULL,
  `muscle_mass` decimal(5,2) DEFAULT NULL,
  `chest` decimal(5,2) DEFAULT NULL,
  `waist` decimal(5,2) DEFAULT NULL,
  `arms` decimal(5,2) DEFAULT NULL,
  `legs` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `photo_before` varchar(255) DEFAULT NULL,
  `photo_after` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `date_recorded` (`date_recorded`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create workout_performance table
CREATE TABLE IF NOT EXISTS `workout_performance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `exercise_name` varchar(100) NOT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `reps` int(11) DEFAULT NULL,
  `sets` int(11) DEFAULT NULL,
  `date_performed` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `date_performed` (`date_performed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci; 